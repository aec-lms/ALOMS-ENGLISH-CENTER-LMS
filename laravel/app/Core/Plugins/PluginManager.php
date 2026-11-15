<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use App\Core\Contracts\PluginInterface;
use App\Core\Security\AuditLogger;
use App\Models\Permission;
use App\Models\PluginRecord;
use App\Models\PluginSetting;
use App\Models\Role;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

class PluginManager
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, PluginManifest> */
    public function discoverManifests(): array
    {
        $modulesPath = (string) config('plugins.modules_path');
        if (!is_dir($modulesPath)) {
            return [];
        }

        $manifests = [];
        foreach (scandir($modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $modulesPath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'plugin.json')) {
                $manifest = PluginManifest::fromDirectory($dir);
                $manifests[$manifest->slug] = $manifest;
            }
        }
        ksort($manifests);

        return $manifests;
    }

    public function syncCatalog(): void
    {
        if (!Schema::hasTable('plugins')) {
            return;
        }

        foreach ($this->discoverManifests() as $manifest) {
            $existing = PluginRecord::query()->where('slug', $manifest->slug)->first();

            PluginRecord::query()->updateOrCreate(
                ['slug' => $manifest->slug],
                [
                    'name' => $manifest->name,
                    'version' => $existing && $existing->is_installed ? $existing->version : $manifest->version,
                    'author' => $manifest->author,
                    'category' => $manifest->category,
                    'description' => $manifest->description,
                    'core_compatibility' => $manifest->coreCompatibility,
                    'dependencies' => [],
                    'manifest_snapshot' => $manifest->toArray(),
                    'is_core' => $manifest->isCore || $manifest->slug === config('plugins.core_identifier'),
                ]
            );
        }
    }

    public function isCompatible(PluginManifest $manifest): bool
    {
        $coreVersion = (string) config('lms.core_version', '2.0.0');
        $major = explode('.', $coreVersion)[0] ?? '2';
        $constraint = ltrim($manifest->coreCompatibility, '^~>= ');
        $requiredMajor = explode('.', $constraint)[0] ?? $major;

        return $major === $requiredMajor;
    }

    /**
     * Plugins operate strictly independently with Core LMS only; no plugin blocks another plugin.
     */
    public function assertDependenciesSatisfied(PluginManifest $manifest, bool $requireEnabled = false): void
    {
        // Zero inter-plugin dependency enforcement by architecture design.
    }

    public function install(string $slug, ?int $actorId = null): PluginRecord
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin {$slug} not found.");
        if (!$this->isCompatible($manifest)) {
            throw new RuntimeException("Plugin {$slug} ({$manifest->coreCompatibility}) is incompatible with Core " . config('lms.core_version'));
        }

        $this->runPluginMigrations($manifest);

        return DB::transaction(function () use ($manifest, $actorId): PluginRecord {
            $record = PluginRecord::query()->updateOrCreate(
                ['slug' => $manifest->slug],
                [
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'author' => $manifest->author,
                    'category' => $manifest->category,
                    'description' => $manifest->description,
                    'core_compatibility' => $manifest->coreCompatibility,
                    'dependencies' => [],
                    'manifest_snapshot' => $manifest->toArray(),
                    'is_installed' => true,
                    'is_enabled' => true,
                    'installed_at' => now(),
                ]
            );

            $this->syncManifestPermissions($manifest);

            $instance = $this->resolvePluginInstance($manifest);
            if ($instance) {
                app()->register($instance);
                $instance->onInstall();
                $instance->onEnable();
            }

            $this->audit->log('plugin.installed', 'plugin', $manifest->slug, ['version' => $manifest->version], $actorId);

            return $record;
        });
    }

    public function updatePlugin(string $slug, ?int $actorId = null): PluginRecord
    {
        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin {$slug} not found.");
        if (!$this->isCompatible($manifest)) {
            throw new RuntimeException("Plugin {$slug} ({$manifest->coreCompatibility}) is incompatible with Core " . config('lms.core_version'));
        }

        $record = PluginRecord::query()->where('slug', $slug)->first();
        $fromVersion = $record?->version ?? '0.0.0';

        $this->runPluginMigrations($manifest);

        return DB::transaction(function () use ($manifest, $fromVersion, $actorId): PluginRecord {
            $record = PluginRecord::query()->updateOrCreate(
                ['slug' => $manifest->slug],
                [
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'author' => $manifest->author,
                    'category' => $manifest->category,
                    'description' => $manifest->description,
                    'core_compatibility' => $manifest->coreCompatibility,
                    'dependencies' => [],
                    'manifest_snapshot' => $manifest->toArray(),
                    'is_installed' => true,
                    'installed_at' => now(),
                ]
            );

            $this->syncManifestPermissions($manifest);

            if (Schema::hasTable('update_logs')) {
                UpdateLog::query()->create([
                    'target_type' => 'plugin',
                    'target_slug' => $manifest->slug,
                    'from_version' => $fromVersion,
                    'to_version' => $manifest->version,
                    'package_checksum' => hash('sha256', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR)),
                    'status' => 'applied',
                    'notes' => 'Plugin updated and migrations synchronized from manifest.',
                    'executed_by' => $actorId,
                ]);
            }

            $this->audit->log('plugin.updated', 'plugin', $manifest->slug, [
                'from_version' => $fromVersion,
                'to_version' => $manifest->version,
            ], $actorId);

            return $record;
        });
    }

    public function installOrUpdateFromZip(string $zipPath, ?int $actorId = null): PluginRecord
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Uploaded plugin archive could not be read.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open plugin ZIP archive.');
        }

        $stagingDir = storage_path('app/plugin-staging/' . uniqid('plg_', true));
        File::ensureDirectoryExists($stagingDir);

        try {
            $zip->extractTo($stagingDir);
            $zip->close();

            $manifestFile = $this->locateManifestFile($stagingDir);
            if (!$manifestFile) {
                throw new RuntimeException('Invalid plugin archive: plugin.json is missing.');
            }

            $packageRoot = dirname($manifestFile);
            $manifest = PluginManifest::fromDirectory($packageRoot);
            if (!$this->isCompatible($manifest)) {
                throw new RuntimeException("Plugin {$manifest->slug} is incompatible with Core " . config('lms.core_version'));
            }

            $targetDir = (string) config('plugins.modules_path') . DIRECTORY_SEPARATOR . $manifest->slug;
            $alreadyExists = PluginRecord::query()->where('slug', $manifest->slug)->where('is_installed', true)->exists();

            File::ensureDirectoryExists(dirname($targetDir));
            if (is_dir($targetDir)) {
                File::deleteDirectory($targetDir);
            }
            File::copyDirectory($packageRoot, $targetDir);

            return $alreadyExists
                ? $this->updatePlugin($manifest->slug, $actorId)
                : $this->install($manifest->slug, $actorId);
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    public function enable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        if (!$record->is_installed) {
            throw new RuntimeException("Plugin {$slug} must be installed before enabling.");
        }

        $manifest = $this->discoverManifests()[$slug] ?? throw new RuntimeException("Plugin manifest {$slug} missing.");

        $record->update(['is_enabled' => true]);
        $provider = $this->resolvePluginInstance($manifest);
        if ($provider) {
            app()->register($provider);
            $provider->onEnable();
        }
        $this->audit->log('plugin.enabled', 'plugin', $slug, [], $actorId);

        return $record->fresh();
    }

    public function disable(string $slug, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        if ($record->is_core || $slug === config('plugins.core_identifier')) {
            throw new RuntimeException('Protected Core cannot be disabled.');
        }

        $record->update(['is_enabled' => false]);
        if (isset($this->discoverManifests()[$slug])) {
            $this->resolvePluginInstance($this->discoverManifests()[$slug])?->onDisable();
        }
        $this->audit->log('plugin.disabled', 'plugin', $slug, [], $actorId);

        return $record->fresh();
    }

    /**
     * Uninstalls any plugin cleanly & independently while strictly preserving shared business records
     * (students, teachers, payments, enrollments, audit logs).
     */
    public function uninstall(string $slug, bool $confirmedPurgePluginData = false, ?int $actorId = null): PluginRecord
    {
        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();
        if ($record->is_core || $slug === config('plugins.core_identifier')) {
            throw new RuntimeException('Protected Core cannot be uninstalled.');
        }

        $manifest = $this->discoverManifests()[$slug] ?? null;
        if ($record->is_enabled && $manifest) {
            $this->resolvePluginInstance($manifest)?->onDisable();
        }

        if ($manifest) {
            $this->resolvePluginInstance($manifest)?->onUninstall($confirmedPurgePluginData);
        }

        $record->update(['is_installed' => false, 'is_enabled' => false]);
        $this->audit->log('plugin.uninstalled', 'plugin', $slug, ['purge_requested' => $confirmedPurgePluginData], $actorId);

        return $record->fresh();
    }

    public function saveSettings(string $slug, array $values, array $secretKeys = [], ?int $actorId = null): void
    {
        foreach ($values as $key => $val) {
            $isSecret = in_array($key, $secretKeys, true);
            $stringVal = is_scalar($val) ? (string) $val : json_encode($val, JSON_THROW_ON_ERROR);
            PluginSetting::query()->updateOrCreate(
                ['plugin_slug' => $slug, 'setting_key' => (string) $key],
                [
                    'setting_value' => $isSecret && $stringVal !== '' ? Crypt::encryptString($stringVal) : $stringVal,
                    'is_encrypted' => $isSecret,
                ]
            );
        }

        $this->audit->log('plugin.configured', 'plugin', $slug, ['keys' => array_keys($values)], $actorId);
    }

    public function getSetting(string $slug, string $key, ?string $default = null): ?string
    {
        if (!Schema::hasTable('plugin_settings')) {
            return $default;
        }

        $setting = PluginSetting::query()
            ->where('plugin_slug', $slug)
            ->where('setting_key', $key)
            ->first();

        return $setting ? ($setting->decryptedValue() ?? $default) : $default;
    }

    public function isEnabled(string $slug): bool
    {
        if (!Schema::hasTable('plugins')) {
            return false;
        }

        return PluginRecord::query()
            ->where('slug', $slug)
            ->where('is_installed', true)
            ->where('is_enabled', true)
            ->exists();
    }

    private function runPluginMigrations(PluginManifest $manifest): void
    {
        $migrationsDir = $manifest->basePath . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        if (is_dir($migrationsDir)) {
            $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $migrationsDir);
            Artisan::call('migrate', ['--path' => $relative, '--force' => true]);
        }
    }

    private function syncManifestPermissions(PluginManifest $manifest): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->first();
        foreach ($manifest->permissions as $perm) {
            $permSlug = is_array($perm) ? (string) ($perm['slug'] ?? '') : (string) $perm;
            $permName = is_array($perm) ? (string) ($perm['name'] ?? $permSlug) : $permSlug;
            if ($permSlug === '') {
                continue;
            }
            $permission = Permission::query()->updateOrCreate(
                ['slug' => $permSlug],
                ['name' => $permName, 'module' => $manifest->slug]
            );
            if ($adminRole) {
                $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    private function locateManifestFile(string $dir): ?string
    {
        $direct = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
        if (is_file($direct)) {
            return $direct;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_dir($dir . DIRECTORY_SEPARATOR . $entry) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolvePluginInstance(PluginManifest $manifest): ?PluginInterface
    {
        if (!$manifest->providerClass || !class_exists($manifest->providerClass)) {
            return null;
        }
        $instance = app($manifest->providerClass);

        return $instance instanceof PluginInterface ? $instance : null;
    }
}
