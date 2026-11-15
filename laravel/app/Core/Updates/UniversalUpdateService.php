<?php

declare(strict_types=1);

namespace App\Core\Updates;

use App\Core\Backup\BackupService;
use App\Core\Security\AuditLogger;
use App\Models\PluginRecord;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use ZipArchive;

class UniversalUpdateService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly AuditLogger $audit,
    ) {}

    public function verifySignedPackage(string $packagePath, string $expectedSha256, string $signatureHex): array
    {
        if (!is_file($packagePath)) {
            throw new RuntimeException('Update package file does not exist.');
        }

        $actualSha = hash_file('sha256', $packagePath) ?: '';
        if (!hash_equals(strtolower($expectedSha256), strtolower($actualSha))) {
            throw new RuntimeException('Update package SHA-256 checksum mismatch.');
        }

        $signingKey = (string) config('lms.plugin_signing_key');
        if ($signingKey === '') {
            throw new RuntimeException('AEC_PLUGIN_SIGNING_KEY is not configured; refusing unverified package.');
        }

        $expectedSignature = hash_hmac('sha256', $actualSha, $signingKey);
        if (!hash_equals($expectedSignature, strtolower($signatureHex))) {
            throw new RuntimeException('Update package digital signature is invalid.');
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new RuntimeException('Unable to open update archive.');
        }

        $manifestRaw = $zip->getFromName('update-manifest.json');
        $zip->close();
        if ($manifestRaw === false) {
            throw new RuntimeException('Update package missing update-manifest.json.');
        }

        return json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function applyVerifiedUpdate(string $packagePath, string $expectedSha256, string $signatureHex, ?int $actorId = null): UpdateLog
    {
        $manifest = $this->verifySignedPackage($packagePath, $expectedSha256, $signatureHex);
        $targetType = (string) ($manifest['target_type'] ?? 'plugin');
        $targetSlug = (string) ($manifest['target_slug'] ?? '');
        $toVersion = (string) ($manifest['version'] ?? '');
        $fromVersion = null;

        if ($targetSlug === '' || $toVersion === '') {
            throw new RuntimeException('Update manifest must specify target_slug and version.');
        }

        if ($targetType === 'plugin') {
            $plugin = PluginRecord::query()->where('slug', $targetSlug)->first();
            $fromVersion = $plugin?->version;
            if (!$this->coreVersionMatches((string) ($manifest['core_compatibility'] ?? '^2.0'))) {
                throw new RuntimeException("Plugin update {$targetSlug} is incompatible with Core.");
            }
        }

        // Create backup before applying update
        $backup = $this->backups->createEncryptedBackup($actorId, false);
        Artisan::call('down');

        try {
            if ($targetType === 'plugin') {
                PluginRecord::query()->where('slug', $targetSlug)->update(['version' => $toVersion]);
            }
            $log = UpdateLog::query()->create([
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_checksum' => $expectedSha256,
                'status' => 'applied',
                'backup_reference' => $backup->filename,
                'notes' => 'Signed update verified and applied with pre-update backup.',
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.applied', $targetType, $targetSlug, ['to' => $toVersion], $actorId);
            return $log;
        } catch (\Throwable $e) {
            $log = UpdateLog::query()->create([
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'package_checksum' => $expectedSha256,
                'status' => 'failed',
                'backup_reference' => $backup->filename,
                'notes' => $e->getMessage(),
                'executed_by' => $actorId,
            ]);
            $this->audit->log('update.failed', $targetType, $targetSlug, ['error' => $e->getMessage()], $actorId);
            throw $e;
        } finally {
            Artisan::call('up');
        }
    }

    private function coreVersionMatches(string $constraint): bool
    {
        $coreMajor = explode('.', (string) config('lms.core_version', '2.0.0'))[0] ?? '2';
        $reqMajor = explode('.', ltrim($constraint, '^~>= '))[0] ?? $coreMajor;

        return $coreMajor === $reqMajor;
    }
}
