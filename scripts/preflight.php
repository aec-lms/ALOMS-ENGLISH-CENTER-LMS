<?php
declare(strict_types=1);

/**
 * Read-only cPanel deployment preflight. Run from the deployed project root:
 *   php scripts/preflight.php
 *
 * This checks CLI-visible requirements only. HTTPS, Apache module state and
 * actual HTTP access controls must still be verified against the live host.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_config.php';

$args = array_slice($argv, 1);
if ($args === ['--help']) {
    echo "Usage: php scripts/preflight.php\nChecks PHP, required extensions, database connectivity, app mail/URL configuration, private storage and deployment access files.\n";
    exit(0);
}
if ($args !== []) {
    fwrite(STDERR, "Unknown option. Use --help.\n");
    exit(2);
}

$errors = [];
$warnings = [];
$passed = 0;

function preflight_pass(string $message): void
{
    global $passed;
    $passed++;
    echo "[PASS] {$message}\n";
}

function preflight_fail(string $message): void
{
    global $errors;
    $errors[] = $message;
    echo "[FAIL] {$message}\n";
}

function preflight_warn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
    echo "[MANUAL] {$message}\n";
}

function preflight_env(string $key): ?string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? null : trim($value);
}

function preflight_find_command(array $names): ?string
{
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        foreach ($names as $name) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
    }
    return null;
}

if (PHP_VERSION_ID >= 80300) {
    preflight_pass('PHP ' . PHP_VERSION . ' meets the PHP 8.3 minimum.');
} else {
    preflight_fail('PHP 8.3 or newer is required; detected ' . PHP_VERSION . '.');
}

if (function_exists('proc_open')) preflight_pass('PHP proc_open is available for the encrypted backup pipeline.');
else preflight_fail('PHP proc_open is disabled; encrypted backup/restore cannot start required CLI tools.');

foreach (['pdo_mysql', 'mbstring', 'zip', 'zlib', 'openssl'] as $extension) {
    if (extension_loaded($extension)) {
        preflight_pass("PHP extension {$extension} is loaded.");
    } else {
        preflight_fail("Required PHP extension {$extension} is missing.");
    }
}

$backupPassphrase = preflight_env('AEC_BACKUP_PASSPHRASE');
if ($backupPassphrase === null || strlen($backupPassphrase) < 32) {
    preflight_fail('AEC_BACKUP_PASSPHRASE must be set in the private config and contain at least 32 characters.');
} else {
    preflight_pass('Backup encryption passphrase is configured.');
}
foreach ([['gpg'],['tar'],['gzip'],['mysqldump','mariadb-dump'],['mysql','mariadb']] as $commands) {
    if (preflight_find_command($commands) !== null) preflight_pass('Backup command available: ' . implode(' or ', $commands) . '.');
    else preflight_fail('Missing backup command: ' . implode(' or ', $commands) . '.');
}

$host = preflight_env('AEC_DB_HOST');
$name = preflight_env('AEC_DB_NAME');
$user = preflight_env('AEC_DB_USER');
$pass = getenv('AEC_DB_PASS');
$port = preflight_env('AEC_DB_PORT') ?? '3306';

if ($host === null || $name === null || $user === null || $pass === false) {
    preflight_fail('Set AEC_DB_HOST, AEC_DB_NAME, AEC_DB_USER and AEC_DB_PASS in the private server environment.');
} elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
    preflight_fail('AEC_DB_PORT must be a TCP port from 1 to 65535.');
} elseif (!extension_loaded('pdo_mysql')) {
    preflight_fail('Cannot test MySQL connectivity because pdo_mysql is not loaded.');
} else {
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            (string)$pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        preflight_pass("MySQL connection succeeded (server {$serverVersion}).");
    } catch (Throwable $error) {
        preflight_fail('MySQL connection failed: ' . $error->getMessage());
    }
}

$appUrl = preflight_env('AEC_APP_URL');
if ($appUrl === null) {
    preflight_fail('AEC_APP_URL must be configured as the canonical HTTPS site URL.');
} else {
    $parts = parse_url($appUrl);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        preflight_fail('AEC_APP_URL must be a valid HTTPS URL without embedded credentials.');
    } else {
        preflight_pass('AEC_APP_URL is a valid HTTPS URL.');
    }
}

$mailEncryptionKey = preflight_env('AEC_MAIL_ENCRYPTION_KEY');
$mailEncryptionBytes = $mailEncryptionKey !== null ? base64_decode($mailEncryptionKey, true) : false;
if (!is_string($mailEncryptionBytes) || strlen($mailEncryptionBytes) !== 32 || !function_exists('openssl_encrypt')) {
    preflight_fail('AEC_MAIL_ENCRYPTION_KEY must be a base64-encoded 32-byte key and OpenSSL encryption must be available.');
} else {
    preflight_pass('Dedicated email outbox encryption key and OpenSSL are configured.');
}

$mailFrom = preflight_env('AEC_MAIL_FROM');
if ($mailFrom === null || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
    preflight_fail('AEC_MAIL_FROM must be a valid sender email address.');
} else {
    preflight_pass('AEC_MAIL_FROM has a valid email format.');
}

$projectRoot = dirname(__DIR__);
$documentRootValue = preflight_env('AEC_DOCUMENT_ROOT') ?? ($_SERVER['DOCUMENT_ROOT'] ?? '');
$documentRoot = $documentRootValue !== '' ? realpath($documentRootValue) : false;
if ($documentRoot === false) {
    preflight_fail('AEC_DOCUMENT_ROOT must be set to the real public document root in the private configuration.');
}

$privatePath = preflight_env('AEC_PRIVATE_STORAGE_DIR');
if ($privatePath === null && $documentRoot !== false) {
    $home = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
    $rootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $parent = $home !== false && $home !== $documentRoot && !str_starts_with($home, $rootPrefix)
        ? $home
        : dirname($documentRoot);
    $privatePath = $parent . DIRECTORY_SEPARATOR . 'aec-private-storage';
}
if ($privatePath === null) {
    preflight_warn('AEC_PRIVATE_STORAGE_DIR is unset and no safe default can be derived in this CLI context; set an absolute path outside the document root.');
} elseif ($privatePath[0] !== DIRECTORY_SEPARATOR) {
    preflight_fail('AEC_PRIVATE_STORAGE_DIR must be an absolute path.');
} else {
    $resolvedPrivate = realpath($privatePath);
    $probe = $privatePath;
    $missingParts = [];
    while (!file_exists($probe) && dirname($probe) !== $probe) {
        array_unshift($missingParts, basename($probe));
        $probe = dirname($probe);
    }
    $resolvedProbe = realpath($probe);
    if ($resolvedProbe === false || !is_dir($resolvedProbe) || !is_writable($resolvedProbe)
        || ($resolvedPrivate !== false && !is_dir($resolvedPrivate))) {
        preflight_fail('Private storage path has no existing writable directory or writable parent.');
    } else {
        $candidate = $resolvedPrivate !== false
            ? $resolvedPrivate
            : rtrim($resolvedProbe, DIRECTORY_SEPARATOR)
                . ($missingParts === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $missingParts));
        $insideDocumentRoot = false;
        if ($documentRoot !== false) {
            $docPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $insideDocumentRoot = $candidate === $documentRoot || str_starts_with($candidate, $docPrefix);
        }
        if ($insideDocumentRoot) {
            preflight_fail('Private storage resolves inside the public document root.');
        } else {
            preflight_pass('Private storage path is absolute, outside the detected document root (if supplied), and has a writable existing parent.');
        }
    }
}

$home = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
$backupPath = preflight_env('AEC_BACKUP_DIR') ?? ($home !== false ? rtrim($home, DIRECTORY_SEPARATOR) . '/.aec-lms/backups' : null);
if ($backupPath === null || $backupPath[0] !== DIRECTORY_SEPARATOR) {
    preflight_fail('AEC_BACKUP_DIR must resolve to an absolute private path.');
} else {
    $backupParent = realpath(dirname($backupPath));
    if ($backupParent === false || !is_dir($backupParent) || !is_writable($backupParent)) {
        preflight_fail('Backup directory parent must exist and be writable.');
    } else {
        $resolvedBackup = rtrim($backupParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($backupPath);
        $insideDoc = $documentRoot !== false && ($resolvedBackup === $documentRoot || str_starts_with($resolvedBackup, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
        $privateBoundary = isset($privatePath) && is_string($privatePath) ? (realpath($privatePath) ?: $privatePath) : '';
        $insideStorage = $privateBoundary !== '' && ($resolvedBackup === $privateBoundary || str_starts_with($resolvedBackup, rtrim($privateBoundary, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || str_starts_with($privateBoundary, rtrim($resolvedBackup, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
        if ($insideDoc || $insideStorage) preflight_fail('Backup directory must be separate from the public document root and private storage.');
        else preflight_pass('Backup directory resolves to a writable private path separate from the web root and private storage.');
    }
}

$accessFile = $projectRoot . '/.htaccess';
if (is_file($accessFile) && is_readable($accessFile)) {
    $rules = (string)file_get_contents($accessFile);
    if (stripos($rules, 'RewriteEngine On') !== false) {
        preflight_pass('Root .htaccess exists and enables rewrite rules.');
    } else {
        preflight_fail('Root .htaccess is missing RewriteEngine On.');
    }
} else {
    preflight_fail('Root .htaccess is missing or unreadable.');
}

$apiAccessFile = $projectRoot . '/api/.htaccess';
if (is_file($apiAccessFile) && is_readable($apiAccessFile)
    && stripos((string)file_get_contents($apiAccessFile), 'Require all denied') !== false) {
    preflight_pass('API .htaccess contains direct-access denial rules.');
} else {
    preflight_fail('api/.htaccess is missing or does not deny direct access to protected bootstrap files.');
}

if (is_file($projectRoot . '/scripts/process-email-queue.php')) {
    preflight_pass('Encrypted account email queue worker is present.');
} else {
    preflight_fail('scripts/process-email-queue.php is missing.');
}
preflight_warn('Configure a cPanel Cron entry to run php /absolute/path/to/lms/scripts/process-email-queue.php --limit=50 every five minutes. The worker must run from the deployed release and uses the private email encryption key.');

preflight_warn('Verify on the live cPanel host that HTTPS redirects work, Apache mod_rewrite and AllowOverride are enabled, and protected paths return HTTP 403/404. CLI cannot prove web-server configuration or email delivery.');

echo "\nPreflight summary: {$passed} passed, " . count($errors) . " failed, " . count($warnings) . " manual verification(s).\n";
if ($errors !== []) {
    exit(1);
}
exit(0);
