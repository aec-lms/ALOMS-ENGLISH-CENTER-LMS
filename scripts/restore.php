<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/backup-common.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo "Usage: php scripts/restore.php --backup-dir=/absolute/backup/path --database=EMPTY_DB --storage=/new/private/path --confirm-restore=EMPTY_DB\n";
        echo "Restore is CLI-only, requires a pre-created empty database and a new private storage path, and never overwrites existing data.\n";
        exit(0);
    }
    if (!preg_match('/^--([a-z-]+)=(.*)$/', $arg, $match)) aec_backup_fail('Invalid option. Use --help.');
    if (!in_array($match[1], ['backup-dir','database','storage','confirm-restore'], true)) aec_backup_fail('Unknown option: --' . $match[1]);
    $options[$match[1]] = $match[2];
}
foreach (['backup-dir','database','storage','confirm-restore'] as $required) if (empty($options[$required])) aec_backup_fail('Missing required option --' . $required . '. Use --help.');
$targetDb = (string)$options['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $targetDb) || !hash_equals($targetDb, (string)$options['confirm-restore'])) aec_backup_fail('Target database must be a safe identifier and must exactly match --confirm-restore.');

$config = aec_backup_config();
$backupDir = realpath((string)$options['backup-dir']);
if ($backupDir === false || !is_dir($backupDir)) aec_backup_fail('Backup directory does not exist.');
if ($config['document_root'] !== '') aec_backup_assert_outside($backupDir, $config['document_root'], 'Backup directory');
aec_backup_assert_outside($backupDir, $config['storage'], 'Backup directory');
$targetStorage = (string)$options['storage'];
if (!str_starts_with($targetStorage, DIRECTORY_SEPARATOR) || file_exists($targetStorage) || is_link($targetStorage) || basename($targetStorage) === '') aec_backup_fail('Restore storage must be a new absolute path that does not already exist.');
$parent = realpath(dirname($targetStorage));
if ($parent !== false) $targetStorage = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($targetStorage);
if ($parent === false || !is_writable($parent)) aec_backup_fail('Restore storage parent directory must exist and be writable.');
if ($config['document_root'] !== '') aec_backup_assert_outside($parent, $config['document_root'], 'Restore storage');
if (str_starts_with($targetStorage . DIRECTORY_SEPARATOR, rtrim($config['storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || str_starts_with($config['storage'] . DIRECTORY_SEPARATOR, rtrim($targetStorage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) aec_backup_fail('Restore storage must be separate from the current private storage.');
if (str_starts_with($backupDir . DIRECTORY_SEPARATOR, rtrim($targetStorage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || str_starts_with($targetStorage . DIRECTORY_SEPARATOR, rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) aec_backup_fail('Restore storage and backup directory must not contain one another.');

$files = ['database.sql.gz.gpg','private-storage.tar.gz.gpg','manifest.json.gpg'];
$checksumPath = $backupDir . '/SHA256SUMS';
if (!is_file($checksumPath)) aec_backup_fail('SHA256SUMS is missing from the backup directory.');
$checksums = [];
foreach (file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (!preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/', trim($line), $match)) aec_backup_fail('Checksum file contains an invalid entry.');
    $checksums[$match[2]] = $match[1];
}
foreach ($files as $name) {
    $path = $backupDir . '/' . $name;
    if (!is_file($path) || !isset($checksums[$name]) || !hash_equals($checksums[$name], (string)hash_file('sha256',$path))) aec_backup_fail('Backup checksum verification failed for ' . $name . '.');
}

$tempDir = aec_backup_make_temp_dir();
$failed = false;
try {
    $passFile = $tempDir . '/gpg-passphrase';
    $mysqlDefaults = $tempDir . '/mysql.cnf';
    aec_backup_write_secret($passFile, $config['passphrase']);
    aec_backup_mysql_defaults($mysqlDefaults, $config);
    $manifestRaw = aec_backup_decrypt_string($backupDir . '/manifest.json.gpg', $passFile, $tempDir);
    $manifest = json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'aec-lms-encrypted-backup-v1') aec_backup_fail('Unsupported or invalid backup manifest.');
    foreach (['database.sql.gz.gpg'=>'database','private-storage.tar.gz.gpg'=>'private_storage'] as $file => $key) {
        if (!isset($manifest['files'][$key]['sha256']) || !hash_equals((string)$manifest['files'][$key]['sha256'], (string)hash_file('sha256',$backupDir . '/' . $file))) aec_backup_fail('Manifest integrity check failed for ' . $file . '.');
    }

    $targetPdo = aec_backup_pdo($config, $targetDb);
    $existingTables = $targetPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($existingTables !== []) aec_backup_fail('Target database is not empty. Restore refused; no table was changed.');

    $databaseGz = $tempDir . '/database.sql.gz';
    $privateTarGz = $tempDir . '/private-storage.tar.gz';
    aec_backup_decrypt_to_file($backupDir . '/database.sql.gz.gpg', $databaseGz, $passFile, $tempDir);
    aec_backup_decrypt_to_file($backupDir . '/private-storage.tar.gz.gpg', $privateTarGz, $passFile, $tempDir);
    $gzip = aec_backup_bin('gzip');
    $tar = aec_backup_bin('tar');
    aec_backup_run([$gzip,'-t',$databaseGz], $tempDir . '/gzip-restore-check.err');

    $tarError = $tempDir . '/tar-list.err';
    $tarProc = proc_open([$tar,'-tvzf',$privateTarGz], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$tarError,'w']], $tarPipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($tarProc)) aec_backup_fail('Could not inspect private storage archive.');
    while (($line = fgets($tarPipes[1])) !== false) {
        $kind = $line[0] ?? '';
        if (!in_array($kind, ['-','d'], true)) { proc_terminate($tarProc); aec_backup_fail('Private storage archive contains a link or special file; refusing extraction.'); }
        $parts = preg_split('/\s+/', trim($line), 6);
        $name = (string)($parts[5] ?? '');
        $normalized = preg_replace('#^\\./+#', '', $name) ?? $name;
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\\") || str_contains($name, "\n") || str_contains($name, "\r")
            || in_array('..', explode('/', $normalized), true)) {
            proc_terminate($tarProc);
            aec_backup_fail('Private storage archive contains an unsafe path; refusing extraction.');
        }
    }
    fclose($tarPipes[1]);
    if (proc_close($tarProc) !== 0) aec_backup_fail('Private storage archive validation failed: ' . trim((string)@file_get_contents($tarError)));

    if (!mkdir($targetStorage, 0700)) aec_backup_fail('Could not create new private restore directory.');
    aec_backup_run([$tar,'--no-same-owner','--no-same-permissions','-xzf',$privateTarGz,'-C',$targetStorage], $tempDir . '/tar-extract.err');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetStorage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink()) aec_backup_fail('Restored private storage contains a symbolic link.');
        @chmod($entry->getPathname(), $entry->isDir() ? 0750 : 0640);
    }
    $storageStats = aec_backup_private_stats($targetStorage);
    if ((int)($manifest['private_storage']['file_count'] ?? -1) !== $storageStats['file_count']
        || (int)($manifest['private_storage']['total_bytes'] ?? -1) !== $storageStats['total_bytes']) aec_backup_fail('Restored private storage does not match the backup manifest.');

    $mysql = aec_backup_bin('mysql','mariadb');
    $gzipError = $tempDir . '/gzip-import.err';
    $mysqlError = $tempDir . '/mysql-import.err';
    $gzipProc = proc_open([$gzip,'-dc',$databaseGz], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$gzipError,'w']], $gzipPipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($gzipProc)) aec_backup_fail('Could not start database decompression.');
    $mysqlProc = proc_open([$mysql,'--defaults-extra-file='.$mysqlDefaults,$targetDb], [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$mysqlError,'w']], $mysqlPipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($mysqlProc)) { proc_terminate($gzipProc); aec_backup_fail('Could not start database restore client.'); }
    while (!feof($gzipPipes[1])) {
        $chunk = fread($gzipPipes[1],65536);
        if ($chunk === false) { proc_terminate($gzipProc); proc_terminate($mysqlProc); aec_backup_fail('Could not read decompressed SQL stream.'); }
        if ($chunk === '' && feof($gzipPipes[1])) break;
        aec_backup_write_all($mysqlPipes[0],$chunk);
    }
    fclose($gzipPipes[1]); fclose($mysqlPipes[0]);
    $gzipCode = proc_close($gzipProc); $mysqlCode = proc_close($mysqlProc);
    if ($gzipCode !== 0 || $mysqlCode !== 0) aec_backup_fail('Database restore failed. Target database may be partially imported and must not be used. gzip=' . trim((string)@file_get_contents($gzipError)) . ' mysql=' . trim((string)@file_get_contents($mysqlError)));

    $restoredPdo = aec_backup_pdo($config,$targetDb);
    $restoredTables = (int)$restoredPdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    if ($restoredTables !== (int)($manifest['table_count'] ?? -1)) aec_backup_fail('Restored database table count does not match the backup manifest.');
    $restoredMigrations = 0;
    try { $restoredMigrations = (int)$restoredPdo->query('SELECT COUNT(*) FROM aec_schema_migrations')->fetchColumn(); } catch (Throwable $ignored) {}
    if ($restoredMigrations !== (int)($manifest['migration_count'] ?? -1)) aec_backup_fail('Restored migration history does not match the backup manifest.');

    echo "[PASS] Encrypted backup restored into the empty target database and new private directory.\n";
    echo "Target database: {$targetDb}; tables: {$restoredTables}; migrations: {$restoredMigrations}.\n";
    echo "Private storage: {$targetStorage}; files: {$storageStats['file_count']} ({$storageStats['total_bytes']} bytes).\n";
    echo "Do not switch production config until you have completed application smoke tests against these restored targets.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] Restore failed. Do not use the target database or storage until inspected: ' . $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    aec_backup_remove_tree($tempDir);
}
if ($failed) exit(1);
