<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/backup-common.php';

function aec_backup_test_run(array $command): array {
    $proc = proc_open($command, [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($proc)) throw new RuntimeException('Could not start CLI test process.');
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code'=>proc_close($proc),'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}

$root = dirname(__DIR__);
$token = bin2hex(random_bytes(6));
$sourceStorage = sys_get_temp_dir() . '/aec-backup-source-' . $token;
$backupBase = sys_get_temp_dir() . '/aec-backup-output-' . $token;
$restoredStorage = sys_get_temp_dir() . '/aec-backup-restored-' . $token;
$blockedStorage = sys_get_temp_dir() . '/aec-backup-blocked-' . $token;
$targetDb = 'aec_restore_' . $token;
$createdDb = false;
$config = [];

$failed = false;
try {
    $config = [
        'host'=>getenv('AEC_DB_HOST') ?: '127.0.0.1',
        'name'=>getenv('AEC_DB_NAME') ?: '',
        'user'=>getenv('AEC_DB_USER') ?: 'root',
        'pass'=>getenv('AEC_DB_PASS') ?: '',
        'port'=>getenv('AEC_DB_PORT') ?: '',
    ];
    if ($config['name'] === '') throw new RuntimeException('AEC_DB_NAME is required for backup/restore integration test.');
    foreach ([$sourceStorage,$backupBase] as $dir) if (!mkdir($dir,0700,true)) throw new RuntimeException('Could not create temporary test directory.');
    if (!mkdir($sourceStorage . '/nested',0700,true)) throw new RuntimeException('Could not create private storage fixture directory.');
    file_put_contents($sourceStorage . '/nested/fixture.txt','AEC LMS encrypted backup restore fixture ' . $token);
    putenv('AEC_PRIVATE_STORAGE_DIR=' . $sourceStorage);
    putenv('AEC_BACKUP_DIR=' . $backupBase);
    putenv('AEC_BACKUP_PASSPHRASE=' . bin2hex(random_bytes(32)));
    putenv('AEC_DOCUMENT_ROOT=' . $root);

    $adminDsn = 'mysql:host=' . $config['host'] . ($config['port'] !== '' ? ';port=' . $config['port'] : '') . ';charset=utf8mb4';
    $adminDb = new PDO($adminDsn,$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $adminDb->exec('CREATE DATABASE ' . $targetDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDb = true;
    $sourceDb = aec_backup_pdo($config);
    $markerKey = 'backup_restore_test_' . $token;
    $sourceDb->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$markerKey, json_encode(['marker' => $token])]);

    $backup = aec_backup_test_run([PHP_BINARY,$root . '/scripts/backup.php','--output-dir=' . $backupBase]);
    if ($backup['code'] !== 0 || !preg_match('/Backup directory: (.+)/',$backup['stdout'],$match)) throw new RuntimeException('Encrypted backup command failed: ' . $backup['stderr'] . $backup['stdout']);
    $backupDir = trim($match[1]);
    foreach (['database.sql.gz.gpg','private-storage.tar.gz.gpg','manifest.json.gpg','SHA256SUMS'] as $file) {
        if (!is_file($backupDir . '/' . $file)) throw new RuntimeException('Backup artifact missing: ' . $file);
    }

    $restore = aec_backup_test_run([PHP_BINARY,$root . '/scripts/restore.php','--backup-dir=' . $backupDir,'--database=' . $targetDb,'--storage=' . $restoredStorage,'--confirm-restore=' . $targetDb]);
    if ($restore['code'] !== 0) throw new RuntimeException('Encrypted restore command failed: ' . $restore['stderr'] . $restore['stdout']);
    if (!is_file($restoredStorage . '/nested/fixture.txt') || trim((string)file_get_contents($restoredStorage . '/nested/fixture.txt')) !== 'AEC LMS encrypted backup restore fixture ' . $token) {
        throw new RuntimeException('Private storage fixture did not survive backup/restore.');
    }
    $restored = aec_backup_pdo($config,$targetDb);
    $source = aec_backup_pdo($config);
    $sourceTables = (int)$source->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $targetTables = (int)$restored->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    if ($sourceTables !== $targetTables || $targetTables < 1) throw new RuntimeException('Restored database table count does not match source.');
    $sourceMigrations = (int)$source->query('SELECT COUNT(*) FROM aec_schema_migrations')->fetchColumn();
    $targetMigrations = (int)$restored->query('SELECT COUNT(*) FROM aec_schema_migrations')->fetchColumn();
    if ($sourceMigrations !== $targetMigrations) throw new RuntimeException('Restored migration history does not match source.');
    $marker = $restored->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
    $marker->execute([$markerKey]);
    $markerValue = json_decode((string)$marker->fetchColumn(), true);
    if (($markerValue['marker'] ?? '') !== $token) throw new RuntimeException('Database row content did not survive backup/restore.');

    $refused = aec_backup_test_run([PHP_BINARY,$root . '/scripts/restore.php','--backup-dir=' . $backupDir,'--database=' . $targetDb,'--storage=' . $blockedStorage,'--confirm-restore=' . $targetDb]);
    if ($refused['code'] === 0 || is_dir($blockedStorage)) throw new RuntimeException('Restore must refuse a non-empty target database without creating a storage directory.');

    echo "PASS encrypted database backup, private storage backup, integrity checks and restore rehearsal\n";
    echo "PASS restore refuses a non-empty target database\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FAIL backup/restore integration: ' . $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    if ($createdDb) {
        try {
            $adminDsn = 'mysql:host=' . ($config['host'] ?? '127.0.0.1') . (!empty($config['port']) ? ';port=' . $config['port'] : '') . ';charset=utf8mb4';
            $adminDb = new PDO($adminDsn,$config['user'] ?? 'root',$config['pass'] ?? '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $adminDb->exec('DROP DATABASE IF EXISTS ' . $targetDb);
            if (!empty($config['name'])) {
                $sourceDb = aec_backup_pdo($config);
                $sourceDb->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute(['backup_restore_test_' . $token]);
            }
        } catch (Throwable $ignored) {}
    }
    foreach ([$sourceStorage,$backupBase,$restoredStorage,$blockedStorage] as $dir) aec_backup_remove_tree($dir);
}
if ($failed) exit(1);
