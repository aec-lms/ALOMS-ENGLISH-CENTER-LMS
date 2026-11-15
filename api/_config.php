<?php
declare(strict_types=1);

/**
 * Load private cPanel configuration without exposing it to the web.
 * AEC_CONFIG_FILE may point to an absolute file outside DOCUMENT_ROOT.
 */
function aec_load_private_config(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $path = getenv('AEC_CONFIG_FILE');
    if ($path === false || trim($path) === '') {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if ($home !== '') $path = rtrim($home, DIRECTORY_SEPARATOR) . '/.aec-lms/config.php';
        elseif (!empty($_SERVER['DOCUMENT_ROOT'])) $path = dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/.aec-lms/config.php';
    }
    if (!is_string($path) || $path === '' || $path[0] !== DIRECTORY_SEPARATOR || !is_file($path)) return;

    $resolved = realpath($path);
    if ($resolved === false || !is_readable($resolved)) return;
    $configPermissions = fileperms($resolved);
    $configDirectoryPermissions = fileperms(dirname($resolved));
    if ($configPermissions === false || ($configPermissions & 0077) !== 0
        || $configDirectoryPermissions === false || ($configDirectoryPermissions & 0077) !== 0) {
        error_log('AEC private configuration permissions are too broad; expected a private directory and file.');
        return;
    }
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $prefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolved === $documentRoot || str_starts_with($resolved, $prefix)) {
            error_log('AEC private configuration is inside the public document root; refusing to load it.');
            return;
        }
    }

    $settings = require $resolved;
    if (!is_array($settings)) {
        error_log('AEC private configuration must return an array.');
        return;
    }
    foreach ($settings as $key => $value) {
        if (!is_string($key) || !preg_match('/^AEC_[A-Z0-9_]+$/', $key) || !is_scalar($value)) continue;
        if (getenv($key) === false || getenv($key) === '') putenv($key . '=' . (string)$value);
    }
}

aec_load_private_config();
