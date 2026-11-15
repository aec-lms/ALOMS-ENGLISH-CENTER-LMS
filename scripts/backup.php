<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/backup-common.php';

$args = array_slice($argv, 1);
$outputOption = null;
foreach ($args as $arg) {
    if ($arg === '--help') {
        echo "Usage: php scripts/backup.php [--output-dir=/absolute/private/path]\nCreates an encrypted database and private-storage backup outside the document root.\n";
        exit(0);
    }
    if (str_starts_with($arg, '--output-dir=')) $outputOption = substr($arg, 13);
    else aec_backup_fail('Unknown option. Use --help.');
}

$config = aec_backup_config();
$gpg = aec_backup_bin('gpg');
$gzip = aec_backup_bin('gzip');
$tar = aec_backup_bin('tar');
$dump = aec_backup_bin('mysqldump', 'mariadb-dump');
$pdo = aec_backup_pdo($config);
$tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
$nonTransactional = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND COALESCE(engine,'') <> 'InnoDB'")->fetchColumn();
if ($nonTransactional > 0) aec_backup_fail('All base tables must use InnoDB for a consistent --single-transaction backup.');
if ($tables < 1) aec_backup_fail('The configured database has no tables; refusing to create an empty backup.');
$migrations = 0;
try { $migrations = (int)$pdo->query('SELECT COUNT(*) FROM aec_schema_migrations')->fetchColumn(); } catch (Throwable $ignored) { $migrations = 0; }

$home = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
if ($home === false) aec_backup_fail('Cannot resolve hosting account HOME directory.');
$base = $outputOption ?? (getenv('AEC_BACKUP_DIR') ?: (rtrim($home, DIRECTORY_SEPARATOR) . '/.aec-lms/backups'));
if (!str_starts_with($base, DIRECTORY_SEPARATOR)) aec_backup_fail('Backup output directory must be an absolute path.');
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) aec_backup_fail('Cannot create backup output directory.');
@chmod($base, 0700);
$base = realpath($base) ?: aec_backup_fail('Cannot resolve backup output directory.');
if ($config['document_root'] !== '') aec_backup_assert_outside($base, $config['document_root'], 'Backup directory');
if (str_starts_with($config['storage'] . DIRECTORY_SEPARATOR, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || str_starts_with($base . DIRECTORY_SEPARATOR, rtrim($config['storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    aec_backup_fail('Backup directory and private storage must not contain one another.');
}

$stamp = gmdate('Ymd\THis\Z');
$backupDir = $base . '/aec-lms-backup-' . $stamp . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDir, 0700)) aec_backup_fail('Cannot create a unique backup directory.');
@chmod($backupDir, 0700);
$tempDir = null;
$failed = false;
try {
    $tempDir = aec_backup_make_temp_dir();
    $passFile = $tempDir . '/gpg-passphrase';
    $mysqlDefaults = $tempDir . '/mysql.cnf';
    aec_backup_write_secret($passFile, $config['passphrase']);
    aec_backup_mysql_defaults($mysqlDefaults, $config);

    $storageStats = aec_backup_private_stats($config['storage']);
    $databaseFile = $backupDir . '/database.sql.gz.gpg';
    $privateFile = $backupDir . '/private-storage.tar.gz.gpg';
    $manifestFile = $backupDir . '/manifest.json.gpg';

    $dumpCommand = [$dump, '--defaults-extra-file=' . $mysqlDefaults, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--default-character-set=utf8mb4', '--no-tablespaces', $config['name']];
    aec_backup_encrypt_stream($dumpCommand, $databaseFile, $passFile, $tempDir, true);
    $tarCommand = [$tar, '-czf', '-', '-C', $config['storage'], '.'];
    aec_backup_encrypt_stream($tarCommand, $privateFile, $passFile, $tempDir, false);

    $manifest = [
        'format' => 'aec-lms-encrypted-backup-v1',
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'database_name' => $config['name'],
        'table_count' => $tables,
        'migration_count' => $migrations,
        'private_storage' => $storageStats,
        'files' => [
            'database' => ['name' => basename($databaseFile), 'sha256' => hash_file('sha256', $databaseFile)],
            'private_storage' => ['name' => basename($privateFile), 'sha256' => hash_file('sha256', $privateFile)],
        ],
    ];
    aec_backup_encrypt_string(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $manifestFile, $passFile, $tempDir);

    foreach ([$databaseFile,$privateFile,$manifestFile] as $file) aec_backup_verify_gpg($file, $passFile, $tempDir);
    $dbCheck = $tempDir . '/verify-database.sql.gz';
    $filesCheck = $tempDir . '/verify-private.tar.gz';
    aec_backup_decrypt_to_file($databaseFile, $dbCheck, $passFile, $tempDir);
    aec_backup_decrypt_to_file($privateFile, $filesCheck, $passFile, $tempDir);
    aec_backup_run([$gzip, '-t', $dbCheck], $tempDir . '/gzip-check.err');
    aec_backup_run([$tar, '-tzf', $filesCheck], $tempDir . '/tar-check.err');
    @unlink($dbCheck); @unlink($filesCheck);

    $checksums = '';
    foreach ([$databaseFile,$privateFile,$manifestFile] as $file) $checksums .= hash_file('sha256', $file) . '  ' . basename($file) . PHP_EOL;
    if (file_put_contents($backupDir . '/SHA256SUMS', $checksums, LOCK_EX) === false) aec_backup_fail('Could not write backup checksum manifest.');
    @chmod($backupDir . '/SHA256SUMS', 0600);
    @chmod($databaseFile, 0600); @chmod($privateFile, 0600); @chmod($manifestFile, 0600);

    echo "[PASS] Encrypted database and private-file backup created and verified.\n";
    echo "Backup directory: {$backupDir}\n";
    echo "Database tables: {$tables}; applied migrations: {$migrations}; private files: {$storageStats['file_count']} ({$storageStats['total_bytes']} bytes).\n";
    echo "Copy the complete encrypted directory and SHA256SUMS off-host. Keep AEC_BACKUP_PASSPHRASE separately in an approved password manager.\n";
} catch (Throwable $error) {
    aec_backup_remove_tree($backupDir);
    fwrite(STDERR, '[ERROR] Backup failed; partial output was removed: ' . $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    if ($tempDir !== null) aec_backup_remove_tree($tempDir);
}
if ($failed) exit(1);
