<?php
declare(strict_types=1);

/**
 * One-time super-admin bootstrap. Run only from CLI after applying migrations:
 *   php scripts/create-admin.php
 *
 * The database advisory lock and existing-super-admin check make repeated or
 * concurrent bootstrap attempts fail closed. This script never resets users.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../api/_bootstrap.php';

function admin_prompt_line(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}

function admin_prompt_secret(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('exec')
        && function_exists('stream_isatty') && stream_isatty(STDIN);
    $output = [];
    $status = 1;
    if ($canHide) {
        exec('stty -echo 2>/dev/null', $output, $status);
        $canHide = $status === 0;
    }

    if (!$canHide) {
        fwrite(STDOUT, "(terminal input may be visible) ");
        $value = fgets(STDIN);
        return $value === false ? '' : rtrim($value, "\r\n");
    }

    try {
        $value = fgets(STDIN);
    } finally {
        exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value === false ? '' : rtrim($value, "\r\n");
}

$db = aec_db();
$lockName = 'aec_lms_first_admin_bootstrap';
$lock = $db->prepare('SELECT GET_LOCK(?, 10)');
$lock->execute([$lockName]);
$lockAcquired = (int)$lock->fetchColumn() === 1;
$lock->closeCursor();

if (!$lockAcquired) {
    fwrite(STDERR, "Another administrator bootstrap is running. Try again later.\n");
    exit(1);
}

try {
    $existing = $db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'admin')")->fetchColumn();
    if ((int)$existing > 0) {
        fwrite(STDERR, "An administrator already exists. Bootstrap is permanently closed; no changes made.\n");
        exit(1);
    }

    $name = admin_prompt_line('Administrator name: ');
    $email = strtolower(admin_prompt_line('Administrator email: '));
    $password = admin_prompt_secret('Administrator password (minimum 12 characters): ');
    $confirmation = admin_prompt_secret('Confirm administrator password: ');

    if ($name === '' || strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 190 || strlen($password) < 12 || !hash_equals($password, $confirmation)) {
        fwrite(STDERR, "Invalid name/email, password too short, or passwords do not match. No changes made.\n");
        exit(1);
    }

    $db->beginTransaction();
    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        $db->rollBack();
        fwrite(STDERR, "An account with that email already exists. No changes made.\n");
        exit(1);
    }

    $stmt = $db->prepare(
        "INSERT INTO users (name, email, password_hash, role, is_active, created_at)
         VALUES (?, ?, ?, 'admin', 1, NOW())"
    );
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $db->commit();

    fwrite(STDOUT, "First administrator created successfully. Run this script again only to confirm bootstrap is closed; it will not create another account.\n");
    fwrite(STDOUT, "For defense in depth, remove scripts/create-admin.php from the deployed account after bootstrap and keep the scripts directory blocked from HTTP access.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('AEC first-admin bootstrap failed: ' . $error->getMessage());
    fwrite(STDERR, "Administrator bootstrap failed. Check server logs; no credentials were logged.\n");
    exit(1);
} finally {
    try {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    } catch (Throwable $ignored) {
        // Preserve the original bootstrap result.
    }
}
