<?php
declare(strict_types=1);

/**
 * One-time, CLI-only fresh installer for cPanel.
 * Run from the project root: php scripts/install.php
 * Never exposes an HTTP setup wizard.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}
if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "PHP 8.3 or newer is required. Detected " . PHP_VERSION . ".\n");
    exit(1);
}
foreach (['pdo_mysql', 'mbstring', 'zip', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Required PHP extension missing: {$extension}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$documentRootInput = getenv('AEC_DOCUMENT_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? $root);
$documentRoot = realpath((string)$documentRootInput);
if ($documentRoot === false) {
    fwrite(STDERR, "Cannot resolve the document root. Set AEC_DOCUMENT_ROOT to the actual domain document root and retry.\\n");
    exit(1);
}
$home = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? dirname($root))));
if ($home === false) {
    fwrite(STDERR, "Cannot resolve the hosting account home directory. Set HOME and retry.\n");
    exit(1);
}
$configDir = $home . DIRECTORY_SEPARATOR . '.aec-lms';
if (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir)) {
    fwrite(STDERR, "Cannot create private config directory under the hosting account home.\n");
    exit(1);
}
@chmod($configDir, 0700);
$configDir = realpath($configDir) ?: $configDir;
$prefix = rtrim((string)$documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($documentRoot !== false && ($configDir === $documentRoot || str_starts_with($configDir, $prefix))) {
    fwrite(STDERR, "Refusing to store configuration inside the public document root. Set HOME to the account home directory.\n");
    exit(1);
}
$lockPath = $configDir . '/install.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle !== false) @chmod($lockPath, 0600);
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another installation process is running, or the install lock cannot be acquired.\n");
    exit(1);
}
$statePath = $configDir . '/installation.json';
if (is_file($statePath)) {
    $state = json_decode((string)file_get_contents($statePath), true);
    $status = is_array($state) ? ($state['status'] ?? 'unknown') : 'unknown';
    fwrite(STDERR, "Installation is locked (state: {$status}). This installer will not overwrite an existing installation.\n");
    exit(1);
}

function ask(string $label, bool $secret = false): string
{
    fwrite(STDOUT, $label);
    if ($secret) fwrite(STDOUT, "(input may be visible in this terminal) ");
    $value = fgets(STDIN);
    return $value === false ? '' : rtrim($value, "\r\n");
}
function fail_install(string $message, string $statePath): never
{
    global $installStarted;
    if (!empty($installStarted)) {
    $state = ['status' => 'failed', 'message' => $message, 'failed_at_utc' => gmdate('c')];
    $tmp = $statePath . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $statePath);
    }
    fwrite(STDERR, "INSTALLATION STOPPED: {$message}\n");
    fwrite(STDERR, "A failed installation is locked against unsafe retries. Inspect the database and restore/reset it deliberately before an operator-approved fresh install.\n");
    exit(1);
}
function install_sql(PDO $db, string $sql): void
{
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*(?:--|#).*$/m', '', $sql) ?? $sql;
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $db->exec($statement);
    }
}

fwrite(STDOUT, "AEC LMS fresh installer — CLI only\n");
fwrite(STDOUT, "This installs into a NEW, EMPTY database. It never drops or overwrites existing tables.\n\n");
$dbHost = trim(ask("MySQL host [localhost]: "));
$dbHost = $dbHost !== '' ? $dbHost : 'localhost';
$dbName = trim(ask("cPanel database name (including account prefix): "));
$dbUser = trim(ask("cPanel database username (including account prefix): "));
$dbPass = ask("Database password: ", true);
$dbPort = trim(ask("MySQL port [3306]: "));
$dbPort = $dbPort !== '' ? $dbPort : '3306';
$appUrl = rtrim(trim(ask("Canonical HTTPS site URL [https://example.com]: ")), '/');
$mailFrom = trim(ask("Verified sender email address: "));
$privateDir = trim(ask("Private storage absolute path [{$home}/aec-private-storage]: "));
$privateDir = $privateDir !== '' ? $privateDir : $home . '/aec-private-storage';
$adminName = trim(ask("First Admin full name: "));
$adminEmail = strtolower(trim(ask("First Admin email: ")));
$adminPassword = ask("First Admin password (minimum 12 characters): ", true);
$adminConfirm = ask("Confirm Admin password: ", true);

if ($dbName === '' || $dbUser === '' || $dbPass === '' || !ctype_digit($dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
    fail_install('Database settings are incomplete or the port is invalid.', $statePath);
}
if (!filter_var($appUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) !== 'https'
    || parse_url($appUrl, PHP_URL_USER) !== null || parse_url($appUrl, PHP_URL_PASS) !== null) {
    fail_install('The canonical site URL must be a valid HTTPS URL without embedded credentials.', $statePath);
}
if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL) || $adminName === '' || strlen($adminName) > 160
    || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 190
    || strlen($adminPassword) < 12 || !hash_equals($adminPassword, $adminConfirm)) {
    fail_install('Invalid sender/admin details or administrator passwords do not match / are shorter than 12 characters.', $statePath);
}
if ($privateDir === '' || $privateDir[0] !== DIRECTORY_SEPARATOR) {
    fail_install('Private storage must be an absolute filesystem path.', $statePath);
}
if (!is_dir($privateDir) && !mkdir($privateDir, 0750, true) && !is_dir($privateDir)) {
    fail_install('Cannot create private storage directory.', $statePath);
}
$resolvedPrivate = realpath($privateDir);
if ($resolvedPrivate === false || !is_writable($resolvedPrivate)
    || ($documentRoot !== false && ($resolvedPrivate === $documentRoot || str_starts_with($resolvedPrivate, $prefix)))) {
    fail_install('Private storage must be writable and outside the public document root.', $statePath);
}
@chmod($resolvedPrivate, 0750);

try {
    $db = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $existingTables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($existingTables !== []) {
        fail_install('The selected database is not empty (' . count($existingTables) . ' table(s) found). Choose a new empty database; no existing table was changed.', $statePath);
    }
} catch (Throwable $error) {
    fail_install('Could not connect to the selected database or verify that it is empty: ' . $error->getMessage(), $statePath);
}

$signingKey = bin2hex(random_bytes(32));
$settings = [
    'AEC_DB_HOST' => $dbHost, 'AEC_DB_NAME' => $dbName, 'AEC_DB_USER' => $dbUser,
    'AEC_DB_PASS' => $dbPass, 'AEC_DB_PORT' => $dbPort, 'AEC_APP_URL' => $appUrl,
    'AEC_MAIL_FROM' => $mailFrom, 'AEC_PRIVATE_STORAGE_DIR' => $resolvedPrivate,
    'AEC_DOCUMENT_ROOT' => $documentRoot,
    'AEC_PLUGIN_SIGNING_KEY' => $signingKey,
    'AEC_BACKUP_PASSPHRASE' => bin2hex(random_bytes(32)),
    'AEC_MAIL_ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
];
$configPath = $configDir . '/config.php';
$configContent = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($settings, true) . ";\n";
$configTmp = $configPath . '.tmp';
if (file_put_contents($configTmp, $configContent, LOCK_EX) === false || !chmod($configTmp, 0600) || !rename($configTmp, $configPath)) {
    fail_install('Could not securely write the private application configuration.', $statePath);
}
putenv('AEC_CONFIG_FILE=' . $configPath);
require_once $root . '/api/_config.php';

$installStarted = true;
$lockName = 'aec_lms_fresh_install';
$lock = $db->prepare('SELECT GET_LOCK(?, 15)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) fail_install('Could not acquire the database installation lock.', $statePath);

try {
    $migrationDir = $root . '/database/migrations';
    $migrationFiles = glob($migrationDir . '/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);
    if ($migrationFiles === []) throw new RuntimeException('No migration SQL files found.');

    $db->exec("CREATE TABLE aec_schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ($migrationFiles as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        if (!is_string($checksum)) throw new RuntimeException("Cannot checksum {$name}.");
        $sql = file_get_contents($file);
        if (!is_string($sql)) throw new RuntimeException("Cannot read {$name}.");
        install_sql($db, $sql);
        $record = $db->prepare('INSERT INTO aec_schema_migrations (migration, checksum) VALUES (?, ?)');
        $record->execute([$name, $checksum]);
        fwrite(STDOUT, "APPLIED {$name}\n");
    }

    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$adminEmail]);
    if ($check->fetch()) throw new RuntimeException('The first administrator email already exists in the newly migrated schema.');
    $createAdmin = $db->prepare(
        "INSERT INTO users (name, email, password_hash, role, is_active, created_at)
         VALUES (?, ?, ?, 'admin', 1, NOW())"
    );
    $createAdmin->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT)]);

    $marker = [
        'status' => 'installed', 'installed_at_utc' => gmdate('c'),
        'database' => $dbName, 'admin_email' => $adminEmail,
        'migration_count' => count($migrationFiles), 'config_file' => $configPath,
    ];
    $markerTmp = $statePath . '.tmp';
    if (file_put_contents($markerTmp, json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !chmod($markerTmp, 0600) || !rename($markerTmp, $statePath)) {
        throw new RuntimeException('Cannot atomically create the completed installation lock.');
    }
    fwrite(STDOUT, "\nINSTALLATION COMPLETE. Applied " . count($migrationFiles) . " migrations.\n");
    fwrite(STDOUT, "Private config: {$configPath} (mode 0600)\n");
    fwrite(STDOUT, "Private storage: {$resolvedPrivate}\n");
    fwrite(STDOUT, "Admin: {$adminEmail}\n");
    fwrite(STDOUT, "The installation lock is stored outside the document root. Do not delete it.\n");
    fwrite(STDOUT, "Backup encryption passphrase is saved in the private config. Export it to an offline password manager before relying on backups.\n");
    fwrite(STDOUT, "Next: verify HTTPS, run php scripts/preflight.php and complete the live-host smoke tests.\n");
} catch (Throwable $error) {
    fail_install('Installation failed: ' . $error->getMessage(), $statePath);
} finally {
    try {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    } catch (Throwable $ignored) {
        // Preserve the install result.
    }
}
