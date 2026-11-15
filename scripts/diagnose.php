<?php
declare(strict_types=1);

/**
 * Read-only, secret-redacted support diagnostics for cPanel deployments.
 * Usage: php scripts/diagnose.php [--json]
 * This command never changes files, database rows, permissions or schema.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_config.php';

$args = array_slice($argv, 1);
if ($args !== [] && $args !== ['--json']) {
    fwrite(STDERR, "Usage: php scripts/diagnose.php [--json]\n");
    exit(2);
}

$checks = [];
$add = static function (string $id, string $status, string $message) use (&$checks): void {
    $checks[] = ['check' => $id, 'status' => $status, 'message' => $message];
};

$add('php_version', PHP_VERSION_ID >= 80300 ? 'pass' : 'fail',
    PHP_VERSION_ID >= 80300 ? 'PHP ' . PHP_VERSION . ' meets the 8.3 minimum.' : 'PHP 8.3 or newer is required.');
foreach (['pdo_mysql', 'mbstring', 'zip', 'openssl'] as $extension) {
    $loaded = extension_loaded($extension);
    $add('extension_' . $extension, $loaded ? 'pass' : 'fail',
        $loaded ? $extension . ' is loaded.' : 'Required extension ' . $extension . ' is missing.');
}

$mailKey = getenv('AEC_MAIL_ENCRYPTION_KEY');
$mailKeyBytes = is_string($mailKey) ? base64_decode($mailKey, true) : false;
$add('email_outbox_key', is_string($mailKeyBytes) && strlen($mailKeyBytes) === 32 && function_exists('openssl_encrypt') ? 'pass' : 'fail',
    is_string($mailKeyBytes) && strlen($mailKeyBytes) === 32 && function_exists('openssl_encrypt')
        ? 'Dedicated email outbox encryption key is configured (value withheld).'
        : 'Email outbox encryption key or OpenSSL is unavailable.');

$configPath = getenv('AEC_CONFIG_FILE');
if ($configPath === false || $configPath === '') {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    $configPath = $home !== '' ? rtrim($home, DIRECTORY_SEPARATOR) . '/.aec-lms/config.php' : '';
}
$configExists = $configPath !== '' && is_file($configPath);
$add('private_config', $configExists ? 'pass' : 'warn',
    $configExists ? 'Private config file exists (path and contents withheld).' : 'Default private config was not found; environment variables may be used.');

$installStatePath = $configPath !== '' ? dirname($configPath) . '/installation.json' : '';
$installState = $installStatePath !== '' && is_file($installStatePath);
$add('installation_lock', $installState ? 'pass' : 'warn',
    $installState ? 'Installation completion marker exists.' : 'Installation completion marker was not found.');

$root = dirname(__DIR__);
foreach ([
    'root_access_rules' => $root . '/.htaccess',
    'api_access_rules' => $root . '/api/.htaccess',
    'scripts_access_rules' => $root . '/scripts/.htaccess',
    'migration_directory' => $root . '/database/migrations',
] as $id => $path) {
    $exists = file_exists($path);
    $add($id, $exists ? 'pass' : 'fail', $exists ? $id . ' is present.' : $id . ' is missing.');
}

$documentRootValue = getenv('AEC_DOCUMENT_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '');
$documentRoot = $documentRootValue !== '' ? realpath($documentRootValue) : false;
$privateStorage = getenv('AEC_PRIVATE_STORAGE_DIR');
if ($privateStorage === false || $privateStorage === '') {
    $privateStorage = '';
}
if ($privateStorage === '' && $documentRoot !== false) {
    $home = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
    $prefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $parent = $home !== false && $home !== $documentRoot && !str_starts_with($home, $prefix)
        ? $home : dirname($documentRoot);
    $privateStorage = $parent . DIRECTORY_SEPARATOR . 'aec-private-storage';
}
if ($privateStorage === '') {
    $add('private_storage', 'warn', 'Private storage path cannot be determined in this CLI context; set AEC_PRIVATE_STORAGE_DIR.');
} else {
    $resolvedStorage = realpath($privateStorage);
    $probe = $privateStorage;
    while (!file_exists($probe) && dirname($probe) !== $probe) $probe = dirname($probe);
    $resolvedProbe = realpath($probe);
    $inside = $resolvedStorage !== false && $documentRoot !== false
        && ($resolvedStorage === $documentRoot || str_starts_with($resolvedStorage, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
    $writable = $resolvedStorage !== false ? is_writable($resolvedStorage) : ($resolvedProbe !== false && is_writable($resolvedProbe));
    $add('private_storage', $inside ? 'fail' : ($writable ? 'pass' : 'fail'),
        $inside ? 'Private storage resolves inside the public document root.' : ($writable ? 'Private storage or its nearest existing parent is writable and outside the document root.' : 'Private storage path is not writable.'));
}

$dbHost = getenv('AEC_DB_HOST');
$dbName = getenv('AEC_DB_NAME');
$dbUser = getenv('AEC_DB_USER');
$dbPass = getenv('AEC_DB_PASS');
$dbPort = getenv('AEC_DB_PORT') ?: '3306';
if (!$dbHost || !$dbName || !$dbUser || $dbPass === false) {
    $add('database', 'warn', 'Database credentials are not available to this CLI process; database check skipped.');
} elseif (!extension_loaded('pdo_mysql') || !ctype_digit($dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
    $add('database', 'fail', 'MySQL driver or configured database port is invalid.');
} else {
    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            (string)$dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
        );
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $tableCount = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $migrationCount = 0;
        $migrationTable = $pdo->query("SHOW TABLES LIKE 'aec_schema_migrations'")->fetchColumn();
        if ($migrationTable !== false) {
            $migrationCount = (int)$pdo->query('SELECT COUNT(*) FROM aec_schema_migrations')->fetchColumn();
        }
        $add('database', 'pass', 'MySQL connection succeeded; server ' . preg_replace('/[^0-9A-Za-z._-]/', '', $version) . '.');
        $add('database_tables', 'info', $tableCount . ' table(s) found; ' . $migrationCount . ' migration record(s) found.');
        $migrationFiles = glob($root . '/database/migrations/*.sql') ?: [];
        sort($migrationFiles, SORT_STRING);
        $applied = [];
        if ($migrationTable !== false) {
            foreach ($pdo->query('SELECT migration, checksum FROM aec_schema_migrations')->fetchAll() as $row) {
                $applied[(string)$row['migration']] = (string)$row['checksum'];
            }
        }
        $pending = [];
        $mismatch = [];
        foreach ($migrationFiles as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if (!isset($applied[$name])) $pending[] = $name;
            elseif (!hash_equals($applied[$name], (string)$checksum)) $mismatch[] = $name;
        }
        foreach (array_keys($applied) as $name) {
            if (!is_file($root . '/database/migrations/' . basename($name))) $mismatch[] = $name . ' (file missing)';
        }
        $add('migration_integrity', $mismatch !== [] ? 'fail' : ($pending !== [] ? 'warn' : 'pass'),
            $mismatch !== [] ? 'Migration checksum/file mismatch detected: ' . implode(', ', $mismatch) :
            ($pending !== [] ? count($pending) . ' unapplied migration(s) detected; review before applying.' : 'All checked-in migrations match recorded checksums.'));
    } catch (Throwable $error) {
        $add('database', 'fail', 'MySQL connection or read-only schema inspection failed (' . get_class($error) . '). Details withheld to protect server information.');
    }
}

$backupPassphrase = getenv('AEC_BACKUP_PASSPHRASE') ?: '';
$backupRuntimeReady = function_exists('proc_open') && extension_loaded('zlib');
$add('backup_runtime', $backupRuntimeReady ? 'pass' : 'warn', $backupRuntimeReady ? 'PHP proc_open and zlib are available for streaming backup operations.' : 'PHP proc_open or zlib is unavailable; encrypted backup/restore cannot run.');
$add('backup_encryption_key', strlen($backupPassphrase) >= 32 ? 'pass' : 'warn',
    strlen($backupPassphrase) >= 32 ? 'Backup encryption passphrase is configured.' : 'AEC_BACKUP_PASSPHRASE is missing or too short; encrypted backup/restore is unavailable.');
$pathEntries = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
$commandAvailable = static function (array $names) use ($pathEntries): bool {
    foreach ($pathEntries as $directory) foreach ($names as $name) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && is_executable($candidate)) return true;
    }
    return false;
};
foreach ([['gpg'],['tar'],['gzip'],['mysqldump','mariadb-dump'],['mysql','mariadb']] as $commands) {
    $available = $commandAvailable($commands);
    $add('backup_tool_' . preg_replace('/[^a-z0-9]+/i', '_', implode('_', $commands)), $available ? 'pass' : 'warn',
        $available ? 'Backup tool available: ' . implode(' or ', $commands) . '.' : 'Backup tool missing: ' . implode(' or ', $commands) . '.');
}

$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
foreach ($checks as $check) $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
$report = [
    'product' => 'AEC LMS Support Diagnostics',
    'report_version' => 1,
    'generated_at_utc' => gmdate('c'),
    'runtime' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os_family' => PHP_OS_FAMILY],
    'summary' => $counts,
    'checks' => $checks,
    'privacy' => 'No passwords, backup passphrases, signing keys, database host/name/user, email addresses, SQL rows, session data or raw exception messages are included.',
];
if ($args === ['--json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo "AEC LMS Support Diagnostics v1\nGenerated (UTC): {$report['generated_at_utc']}\n";
    foreach ($checks as $check) {
        echo '[' . strtoupper($check['status']) . '] ' . $check['check'] . ': ' . $check['message'] . "\n";
    }
    echo "Summary: {$counts['pass']} pass, {$counts['warn']} warning, {$counts['fail']} fail.\n";
    echo "Read-only: no files, permissions, database rows or schema were changed.\n";
    echo "JSON report: php scripts/diagnose.php --json\n";
}
exit($counts['fail'] > 0 ? 1 : 0);
