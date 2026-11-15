<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Safe declarative plugin package manager.
 * Packages are data-only JSON manifests/payloads: PHP, JS, HTML and executable
 * files are deliberately rejected. This endpoint never writes into the LMS core.
 */
$actor = aec_current_user();
if (($actor['role'] ?? '') !== 'admin') aec_fail('Only an administrator can manage plugin packages.', 403);
$action = (string)($_GET['action'] ?? 'list');
if ($action !== 'list') {
    aec_method(['POST']);
    aec_csrf_check();
}
if (!class_exists(ZipArchive::class)) aec_fail('PHP ZIP extension is required for plugin package operations.', 503);

$root = aec_private_storage_dir('plugin-manager');
$activeDir = $root . DIRECTORY_SEPARATOR . 'active';
$backupDir = $root . DIRECTORY_SEPARATOR . 'backups';
foreach ([$activeDir, $backupDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) aec_fail('Plugin storage is not writable.', 500);
}
$secret = aec_env('AEC_PLUGIN_SIGNING_KEY');
if ($action !== 'list' && (!$secret || strlen($secret) < 32)) {
    aec_fail('Plugin updates are disabled until AEC_PLUGIN_SIGNING_KEY (at least 32 characters) is configured outside the web root.', 503);
}
function aec_plugin_id(string $id): string {
    if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $id)) aec_fail('Invalid plugin identifier.', 422);
    return $id;
}
function aec_plugin_read_registry(string $root): array {
    $file = $root . DIRECTORY_SEPARATOR . 'registry.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function aec_plugin_write_registry(string $root, array $data): void {
    $target = $root . DIRECTORY_SEPARATOR . 'registry.json';
    $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $target)) {
        @unlink($tmp);
        aec_fail('Could not safely update plugin registry.', 500);
    }
    @chmod($target, 0640);
}
function aec_plugin_version(string $version): void {
    if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) aec_fail('Plugin version must use semantic versioning.', 422);
}
function aec_plugin_remove_tree(string $path): void {
    if (!is_dir($path) || is_link($path)) return;
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) continue;
        $child = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) aec_plugin_remove_tree($child); else @unlink($child);
    }
    @rmdir($path);
}
if ($action === 'list') {
    aec_method(['GET']);
    $items = aec_plugin_read_registry($root);
    foreach ($items as $id => &$item) {
        $safeId = aec_plugin_id((string)$id);
        $dir = $backupDir . DIRECTORY_SEPARATOR . $safeId;
        $item['rollback_snapshots'] = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $snapshotDir) {
            $name = basename($snapshotDir);
            if (preg_match('/^\\d{8}_\\d{6}_[a-f0-9]{6}$/', $name)) $item['rollback_snapshots'][] = $name;
        }
        rsort($item['rollback_snapshots']);
    }
    unset($item);
    aec_ok(array_values($items));
}
if ($action === 'install') {
    if (!isset($_FILES['package']) || !is_uploaded_file($_FILES['package']['tmp_name'])) aec_fail('Upload a signed .zip package in the package field.', 422);
    $upload = $_FILES['package'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$upload['size'] < 1 || (int)$upload['size'] > 5242880) aec_fail('Plugin package must be between 1 byte and 5 MB.', 422);
    $zip = new ZipArchive();
    if ($zip->open($upload['tmp_name']) !== true) aec_fail('Invalid ZIP package.', 422);
    try {
        if ($zip->numFiles < 2 || $zip->numFiles > 52) aec_fail('Package must contain a manifest, signature and at most 50 payload files.', 422);
        $manifestRaw = $zip->getFromName('manifest.json');
        $signature = trim((string)$zip->getFromName('signature.txt'));
        if (!is_string($manifestRaw) || $signature === '') aec_fail('manifest.json and signature.txt are required.', 422);
        $expected = hash_hmac('sha256', $manifestRaw, (string)$secret);
        if (!hash_equals($expected, $signature)) aec_fail('Package signature verification failed.', 422);
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) aec_fail('Invalid plugin manifest JSON.', 422);
        $id = aec_plugin_id((string)($manifest['id'] ?? ''));
        $version = (string)($manifest['version'] ?? '');
        aec_plugin_version($version);
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || count($files) < 1 || count($files) > 50) aec_fail('Manifest must declare 1 to 50 payload files.', 422);
        $allowed = ['manifest.json' => true, 'signature.txt' => true];
        $payload = [];
        foreach ($files as $relative => $hash) {
            if (!is_string($relative) || !preg_match('#^payload/[a-zA-Z0-9_-]{1,48}\.json$#', $relative) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) aec_fail('Only declared JSON payload files are allowed.', 422);
            $allowed[$relative] = true;
            $index = $zip->locateName($relative);
            $stat = $index === false ? false : $zip->statIndex($index);
            if (!$stat || (int)($stat['size'] ?? 0) > 262144) aec_fail('Payload is missing or exceeds the 256 KB limit.', 422);
            $bytes = $zip->getFromIndex($index);
            if (!is_string($bytes) || !hash_equals($hash, hash('sha256', $bytes))) aec_fail('Payload checksum does not match.', 422);
            if (json_decode($bytes, true) === null && trim($bytes) !== 'null') aec_fail('Every payload must contain valid JSON.', 422);
            $payload[$relative] = $bytes;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            if (!isset($allowed[$name]) || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) aec_fail('Package contains an undeclared or unsafe path.', 422);
        }
        $registry = aec_plugin_read_registry($root);
        $pluginRoot = $activeDir . DIRECTORY_SEPARATOR . $id;
        $backupRoot = $backupDir . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($backupRoot) && !mkdir($backupRoot, 0750, true) && !is_dir($backupRoot)) aec_fail('Could not create plugin backup directory.', 500);
        if (is_dir($pluginRoot)) {
            $backup = $backupRoot . DIRECTORY_SEPARATOR . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
            if (!mkdir($backup, 0750, true)) aec_fail('Could not create rollback snapshot.', 500);
            foreach (glob($pluginRoot . DIRECTORY_SEPARATOR . '*.json') ?: [] as $oldFile) {
                if (!copy($oldFile, $backup . DIRECTORY_SEPARATOR . basename($oldFile))) aec_fail('Could not create rollback snapshot.', 500);
            }
            file_put_contents($backup . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($registry[$id] ?? []), LOCK_EX);
        }
        $stage = $root . DIRECTORY_SEPARATOR . 'staging_' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0750, true)) aec_fail('Could not create isolated staging directory.', 500);
        try {
            foreach ($payload as $relative => $bytes) {
                $target = $stage . DIRECTORY_SEPARATOR . basename($relative);
                if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) aec_fail('Could not stage plugin payload.', 500);
                @chmod($target, 0640);
            }
            $previous = $pluginRoot . '.previous';
            aec_plugin_remove_tree($previous);
            if (is_dir($pluginRoot) && !rename($pluginRoot, $previous)) aec_fail('Could not preserve current plugin version.', 500);
            if (!rename($stage, $pluginRoot)) {
                if (is_dir($previous)) @rename($previous, $pluginRoot);
                aec_fail('Atomic plugin activation failed; previous version was preserved.', 500);
            }
            aec_plugin_remove_tree($previous);
        } finally {
            aec_plugin_remove_tree($stage);
        }
        $registry[$id] = ['id' => $id, 'version' => $version, 'name' => mb_substr((string)($manifest['name'] ?? $id), 0, 100), 'installed_at' => gmdate('c'), 'file_count' => count($payload), 'enabled' => false, 'execution' => 'data-only'];
        aec_plugin_write_registry($root, $registry);
        $db = aec_db();
        $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'plugin.package_installed', 'plugin', ?, ?, ?)")
            ->execute([(int)$actor['id'], 0, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['plugin_id' => $id, 'version' => $version])]);
        aec_ok($registry[$id], 'Signed data-only plugin package installed. It remains disabled until explicitly enabled.');
    } finally {
        $zip->close();
    }
}
if ($action === 'toggle') {
    $input = aec_input();
    $id = aec_plugin_id((string)($input['id'] ?? ''));
    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) aec_fail('The enabled field must be a boolean.', 422);
    $registry = aec_plugin_read_registry($root);
    if (!isset($registry[$id])) aec_fail('Installed plugin package not found.', 404);
    $registry[$id]['enabled'] = $input['enabled'];
    $registry[$id]['updated_at'] = gmdate('c');
    aec_plugin_write_registry($root, $registry);
    aec_db()->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'plugin.package_toggled', 'plugin', 0, ?, ?)")
        ->execute([(int)$actor['id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['plugin_id' => $id, 'enabled' => $input['enabled']])]);
    aec_ok($registry[$id], 'Plugin package state updated. Data packages do not execute code.');
}
if ($action === 'rollback') {
    $input = aec_input();
    $id = aec_plugin_id((string)($input['id'] ?? ''));
    $snapshot = (string)($input['snapshot'] ?? '');
    if (!preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/', $snapshot)) aec_fail('Invalid rollback snapshot.', 422);
    $source = $backupDir . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . $snapshot;
    if (!is_dir($source) || is_link($source)) aec_fail('Rollback snapshot not found.', 404);
    $stage = $root . DIRECTORY_SEPARATOR . 'rollback_' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0750, true)) aec_fail('Could not stage rollback.', 500);
    foreach (glob($source . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        if (basename($file) === 'metadata.json') continue;
        if (!copy($file, $stage . DIRECTORY_SEPARATOR . basename($file))) aec_fail('Could not stage rollback snapshot.', 500);
    }
    $target = $activeDir . DIRECTORY_SEPARATOR . $id;
    $previous = $target . '.previous';
    aec_plugin_remove_tree($previous);
    if (is_dir($target) && !rename($target, $previous)) aec_fail('Could not preserve current plugin version.', 500);
    if (!rename($stage, $target)) {
        if (is_dir($previous)) @rename($previous, $target);
        aec_fail('Rollback activation failed; previous version was preserved.', 500);
    }
    aec_plugin_remove_tree($previous);
    $meta = json_decode((string)@file_get_contents($source . DIRECTORY_SEPARATOR . 'metadata.json'), true);
    if (is_array($meta)) {
        $registry = aec_plugin_read_registry($root);
        $registry[$id] = $meta;
        aec_plugin_write_registry($root, $registry);
    }
    aec_ok(['id' => $id], 'Previous plugin data snapshot restored.');
}
aec_fail('Unknown plugin updater action.', 404);
