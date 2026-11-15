<?php
declare(strict_types=1);

/**
 * Alom's English Center LMS API bootstrap.
 * Configure database credentials as server environment variables. Never commit secrets.
 */
const AEC_API_VERSION = '1.0.0';

require_once __DIR__ . '/_config.php';

function aec_env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

/**
 * Resolve a private upload directory outside the public document root.
 * AEC_PRIVATE_STORAGE_DIR may point to a dedicated writable directory.
 * By default, files are stored in the hosting account home directory.
 */
function aec_private_storage_dir(string $bucket): string {
    if (!preg_match('/^[a-z0-9_-]{1,32}$/i', $bucket)) {
        aec_fail('Invalid private storage directory.', 500);
    }

    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $privateRoot = aec_env('AEC_PRIVATE_STORAGE_DIR');
    if ($privateRoot === null) {
        if ($documentRoot === false) {
            aec_fail('Set AEC_PRIVATE_STORAGE_DIR to an absolute path outside the public document root.', 503);
        }
        $homeDirectory = realpath((string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
        $documentRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $storageParent = $homeDirectory !== false
            && $homeDirectory !== $documentRoot
            && !str_starts_with($homeDirectory, $documentRootPrefix)
                ? $homeDirectory
                : dirname($documentRoot);
        $privateRoot = $storageParent . DIRECTORY_SEPARATOR . 'aec-private-storage';
    }

    if ($privateRoot === '' || $privateRoot[0] !== DIRECTORY_SEPARATOR) {
        aec_fail('AEC_PRIVATE_STORAGE_DIR must be an absolute filesystem path.', 503);
    }
    if (!is_dir($privateRoot) && !mkdir($privateRoot, 0750, true) && !is_dir($privateRoot)) {
        aec_fail('Private upload storage is unavailable. Check AEC_PRIVATE_STORAGE_DIR permissions.', 500);
    }

    $resolvedRoot = realpath($privateRoot);
    if ($resolvedRoot === false) {
        aec_fail('Private upload storage path could not be resolved.', 500);
    }
    @chmod($resolvedRoot, 0750);
    if ($documentRoot !== false) {
        $documentRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedRoot === $documentRoot || str_starts_with($resolvedRoot, $documentRootPrefix)) {
            aec_fail('Private upload storage must be outside the public document root.', 503);
        }
    }

    $directory = $resolvedRoot . DIRECTORY_SEPARATOR . $bucket;
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        aec_fail('Private upload storage is unavailable. Check directory permissions.', 500);
    }
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || !str_starts_with($resolvedDirectory, $resolvedRoot . DIRECTORY_SEPARATOR)) {
        aec_fail('Private upload storage directory is invalid.', 500);
    }
    @chmod($resolvedDirectory, 0750);
    return $resolvedDirectory;
}

function aec_json(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aec_ok(mixed $data = null, string $message = 'OK', int $status = 200): never {
    aec_json(['success' => true, 'message' => $message, 'data' => $data], $status);
}

function aec_fail(string $message, int $status = 400, array $extra = []): never {
    aec_json(['success' => false, 'message' => $message, 'error' => $message] + $extra, $status);
}

function aec_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = aec_env('AEC_DB_HOST');
    $name = aec_env('AEC_DB_NAME');
    $user = aec_env('AEC_DB_USER');
    $pass = aec_env('AEC_DB_PASS');
    if (!$host || !$name || !$user) aec_fail('Database is not configured. Set AEC_DB_HOST, AEC_DB_NAME, AEC_DB_USER and AEC_DB_PASS.', 503);
    $port = aec_env('AEC_DB_PORT');
    if ($port !== null && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) {
        aec_fail('AEC_DB_PORT must be a valid TCP port.', 503);
    }
    $dsn = $port
        ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name)
        : sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    try {
        $pdo = new PDO($dsn, $user, $pass ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('AEC database connection failed: ' . $e->getMessage());
        aec_fail('Database connection failed.', 503);
    }
    return $pdo;
}

function aec_notify_user(
    PDO $db,
    int $recipientUserId,
    ?int $senderUserId,
    string $title,
    string $message,
    string $type = 'info',
    ?string $actionUrl = null,
    ?string $titleBn = null,
    ?string $messageBn = null
): void {
    if ($recipientUserId < 1 || trim($title) === '' || trim($message) === '') return;
    if (!in_array($type, ['info', 'success', 'warning', 'alert'], true)) $type = 'info';
    if ($actionUrl !== null && !preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $actionUrl)) $actionUrl = null;
    try {
        $stmt = $db->prepare('INSERT INTO notifications
            (recipient_user_id, sender_user_id, title, title_bn, message, message_bn, type, target_role, action_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, \'all\', ?)');
        $stmt->execute([
            $recipientUserId,
            $senderUserId && $senderUserId > 0 ? $senderUserId : null,
            mb_substr(trim($title), 0, 180),
            $titleBn !== null && trim($titleBn) !== '' ? mb_substr(trim($titleBn), 0, 180) : null,
            mb_substr(trim($message), 0, 4000),
            $messageBn !== null && trim($messageBn) !== '' ? mb_substr(trim($messageBn), 0, 4000) : null,
            $type,
            $actionUrl,
        ]);
    } catch (Throwable $e) {
        error_log('AEC notification insert failed: ' . $e->getMessage());
    }
}

function aec_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('AEC_LMS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function aec_method(array $allowed): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) aec_fail('Method not allowed.', 405);
    return $method;
}

function aec_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > 1048576) aec_fail('Request body is too large.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) aec_fail('Request body must be valid JSON.', 400);
    return $data;
}

function aec_csrf_check(): void {
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$expected || !$provided || !hash_equals($expected, $provided)) aec_fail('Invalid or missing CSRF token.', 419);
}

function aec_current_user(): array {
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) aec_fail('Authentication required.', 401);
    $stmt = aec_db()->prepare('SELECT id, name, email, phone, role, branch_id, is_active, session_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || !(bool)$user['is_active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        aec_fail('Account is unavailable.', 401);
    }
    if ((int)($_SESSION['session_version'] ?? 1) !== (int)$user['session_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        aec_fail('Your session has expired. Please sign in again.', 401);
    }
    unset($user['session_version']);
    // Legacy Super Admin accounts are transparently represented as the single Admin role.
    if ($user['role'] === 'super_admin') $user['role'] = 'admin';
    aec_assert_active_branch(aec_db(), $user);
    return $user;
}

function aec_require_permission(string $permission): array {
    $user = aec_current_user();
    // The single Admin role retains the former Super Admin's full permission bypass.
    if ($user['role'] === 'admin') return $user;
    $stmt = aec_db()->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ? LIMIT 1');
    $stmt->execute([$user['role'], $permission]);
    if (!(bool)$stmt->fetchColumn()) aec_fail('You do not have permission to perform this action.', 403);
    return $user;
}

/** Deny branch operators access to global datasets until the endpoint enforces branch ownership. */
function aec_require_unscoped_permission(string $permission): array {
    $user = aec_current_user();
    if (in_array((string)($user['role'] ?? ''), ['branch_manager', 'support_staff'], true)) {
        aec_fail('This module is not available to branch operators until branch-level access is implemented.', 403);
    }
    return aec_require_permission($permission);
}

function aec_assert_active_branch(PDO $db, array $actor): void {
    if (!in_array((string)($actor['role'] ?? ''), ['branch_manager', 'support_staff'], true)) return;
    $branchId = filter_var($actor['branch_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$branchId || $branchId < 1) aec_fail('Your account is not assigned to a branch.', 403);
    $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int)$branchId]);
    if (!$stmt->fetchColumn()) aec_fail('Your assigned branch is inactive. Contact an administrator.', 403);
}

function aec_bootstrap(): void {
    aec_start_session();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
aec_bootstrap();
