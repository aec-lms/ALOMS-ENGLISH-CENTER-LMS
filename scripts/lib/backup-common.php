<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/_config.php';

function aec_backup_fail(string $message, int $status = 1): never {
    throw new RuntimeException($message, $status);
}

function aec_backup_bin(string ...$candidates): string {
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        foreach ($candidates as $candidate) {
            $full = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($full) && is_executable($full)) return $full;
        }
    }
    aec_backup_fail('Required command is unavailable: ' . implode(' or ', $candidates));
}

function aec_backup_config(): array {
    $config = [
        'host' => getenv('AEC_DB_HOST') ?: '',
        'name' => getenv('AEC_DB_NAME') ?: '',
        'user' => getenv('AEC_DB_USER') ?: '',
        'pass' => getenv('AEC_DB_PASS') ?: '',
        'port' => getenv('AEC_DB_PORT') ?: '',
        'storage' => getenv('AEC_PRIVATE_STORAGE_DIR') ?: '',
        'document_root' => getenv('AEC_DOCUMENT_ROOT') ?: '',
        'passphrase' => getenv('AEC_BACKUP_PASSPHRASE') ?: '',
    ];
    if ($config['host'] === '' || $config['name'] === '' || $config['user'] === '') aec_backup_fail('Database configuration is incomplete; require AEC_DB_HOST, AEC_DB_NAME and AEC_DB_USER.');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $config['name'])) aec_backup_fail('Database name contains unsupported characters.');
    if ($config['port'] !== '' && (!ctype_digit($config['port']) || (int)$config['port'] < 1 || (int)$config['port'] > 65535)) aec_backup_fail('AEC_DB_PORT is invalid.');
    if (strlen($config['passphrase']) < 32) aec_backup_fail('Set AEC_BACKUP_PASSPHRASE to a random secret of at least 32 characters in the private config.');
    if ($config['storage'] === '' || !is_dir($config['storage'])) aec_backup_fail('AEC_PRIVATE_STORAGE_DIR must point to an existing private storage directory.');
    $storage = realpath($config['storage']);
    if ($storage === false) aec_backup_fail('Cannot resolve private storage directory.');
    $config['storage'] = $storage;
    $docRoot = $config['document_root'] !== '' ? realpath($config['document_root']) : false;
    if ($docRoot === false) {
        $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }
    if ($docRoot === false) aec_backup_fail('AEC_DOCUMENT_ROOT must be set to the real public document root in the private config.');
    $config['document_root'] = $docRoot;
    aec_backup_assert_outside($config['storage'], $config['document_root'], 'Private storage');
    return $config;
}

function aec_backup_assert_outside(string $path, string $documentRoot, string $label): void {
    $path = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
    $root = rtrim(realpath($documentRoot) ?: $documentRoot, DIRECTORY_SEPARATOR);
    if ($path === $root || str_starts_with($path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
        aec_backup_fail($label . ' must be outside the public document root.');
    }
}

function aec_backup_private_stats(string $root): array {
    $files = 0; $bytes = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isLink()) aec_backup_fail('Private storage contains a symbolic link; remove/review it before backup.');
        if (preg_match('/[\\x00-\\x1F\\x7F]/', $entry->getFilename()) || str_contains($entry->getFilename(), '\\\\')) aec_backup_fail('Private storage contains a filename with control characters or backslashes; rename it before backup.');
        if ($entry->isFile()) {
            $stat = @stat($entry->getPathname());
            if (is_array($stat) && (int)($stat['nlink'] ?? 1) > 1) aec_backup_fail('Private storage contains a hard-linked file; remove/review it before backup.');
            $files++; $bytes += $entry->getSize();
        } elseif (!$entry->isDir()) {
            aec_backup_fail('Private storage contains a special file; remove/review it before backup.');
        }
    }
    return ['file_count' => $files, 'total_bytes' => $bytes];
}

function aec_backup_pdo(array $config, ?string $database = null): PDO {
    $name = $database ?? $config['name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) aec_backup_fail('Target database name contains unsupported characters.');
    $dsn = 'mysql:host=' . $config['host'] . ($config['port'] !== '' ? ';port=' . $config['port'] : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function aec_backup_make_temp_dir(): string {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if ($home === '' || !is_dir($home)) aec_backup_fail('Cannot resolve hosting account HOME directory.');
    $base = rtrim($home, DIRECTORY_SEPARATOR) . '/.aec-lms/tmp';
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) aec_backup_fail('Cannot create private temporary directory.');
    @chmod($base, 0700);
    $path = $base . '/backup-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700)) aec_backup_fail('Cannot create private temporary workspace.');
    return $path;
}

function aec_backup_write_secret(string $path, string $secret): void {
    if (str_contains($secret, "\n") || str_contains($secret, "\r") || str_contains($secret, "\0")) aec_backup_fail('A private configuration value contains an invalid line break.');
    $content = $secret;
    if (file_put_contents($path, $content . PHP_EOL, LOCK_EX) === false || !chmod($path, 0600)) aec_backup_fail('Cannot write private temporary secret file.');
}

function aec_backup_mysql_defaults(string $path, array $config): void {
    foreach (['host','user','pass','port'] as $key) {
        if (str_contains((string)$config[$key], "\n") || str_contains((string)$config[$key], "\r") || str_contains((string)$config[$key], "\0")) aec_backup_fail('Database config contains an invalid line break.');
    }
    $quote = static fn(string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    $content = "[client]\nhost=" . $quote($config['host']) . "\nuser=" . $quote($config['user']) . "\npassword=" . $quote($config['pass']) . "\n";
    if ($config['port'] !== '') $content .= 'port=' . (int)$config['port'] . "\n";
    $content .= "default-character-set=utf8mb4\n";
    if (file_put_contents($path, $content, LOCK_EX) === false || !chmod($path, 0600)) aec_backup_fail('Cannot create a private temporary MySQL credential file.');
}

function aec_backup_write_all($stream, string $data): void {
    $offset = 0; $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) aec_backup_fail('Could not stream backup data to the encryption process.');
        $offset += $written;
    }
}

function aec_backup_encrypt_stream(array $producerCommand, string $outputPath, string $passFile, string $tempDir, bool $gzipInput = false): void {
    $gpg = aec_backup_bin('gpg');
    $producerError = $tempDir . '/producer-' . bin2hex(random_bytes(4)) . '.err';
    $gpgError = $tempDir . '/gpg-' . bin2hex(random_bytes(4)) . '.err';
    $producer = proc_open($producerCommand, [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['file',$producerError,'w']], $producerPipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($producer)) aec_backup_fail('Could not start backup data producer.');
    $gpgCommand = [$gpg,'--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$passFile,'--symmetric','--cipher-algo','AES256','--output',$outputPath,'-'];
    $encryptor = proc_open($gpgCommand, [0 => ['pipe','r'], 1 => ['file','/dev/null','w'], 2 => ['file',$gpgError,'w']], $gpgPipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($encryptor)) {
        proc_terminate($producer);
        aec_backup_fail('Could not start backup encryption process.');
    }
    $deflater = $gzipInput ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    if ($gzipInput && $deflater === false) aec_backup_fail('Could not initialize streaming gzip compression.');
    try {
        while (!feof($producerPipes[1])) {
            $chunk = fread($producerPipes[1], 65536);
            if ($chunk === false) throw new RuntimeException('Could not read backup data stream.');
            if ($chunk === '' && feof($producerPipes[1])) break;
            $out = $gzipInput ? deflate_add($deflater, $chunk, ZLIB_SYNC_FLUSH) : $chunk;
            if ($out === false) throw new RuntimeException('Could not compress database dump.');
            if ($out !== '') aec_backup_write_all($gpgPipes[0], $out);
        }
        if ($gzipInput) {
            $tail = deflate_add($deflater, '', ZLIB_FINISH);
            if ($tail === false) throw new RuntimeException('Could not finish database compression.');
            if ($tail !== '') aec_backup_write_all($gpgPipes[0], $tail);
        }
    } catch (Throwable $error) {
        proc_terminate($producer); proc_terminate($encryptor);
        throw $error;
    } finally {
        fclose($producerPipes[1]);
        fclose($gpgPipes[0]);
    }
    $producerCode = proc_close($producer);
    $gpgCode = proc_close($encryptor);
    if ($producerCode !== 0) aec_backup_fail('Backup data producer failed: ' . trim((string)@file_get_contents($producerError)));
    if ($gpgCode !== 0 || !is_file($outputPath) || filesize($outputPath) < 1) aec_backup_fail('GPG encryption failed: ' . trim((string)@file_get_contents($gpgError)));
    @chmod($outputPath, 0600);
}

function aec_backup_encrypt_string(string $data, string $outputPath, string $passFile, string $tempDir): void {
    $gpg = aec_backup_bin('gpg');
    $errorFile = $tempDir . '/gpg-string-' . bin2hex(random_bytes(4)) . '.err';
    $proc = proc_open([$gpg,'--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$passFile,'--symmetric','--cipher-algo','AES256','--output',$outputPath,'-'],
        [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$errorFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc)) aec_backup_fail('Could not start manifest encryption.');
    aec_backup_write_all($pipes[0],$data);
    fclose($pipes[0]);
    $code=proc_close($proc);
    if ($code!==0 || !is_file($outputPath) || filesize($outputPath)<1) aec_backup_fail('Manifest encryption failed: '.trim((string)@file_get_contents($errorFile)));
    @chmod($outputPath,0600);
}

function aec_backup_verify_gpg(string $file, string $passFile, string $tempDir): void {
    $gpg=aec_backup_bin('gpg'); $errorFile=$tempDir.'/verify-'.bin2hex(random_bytes(4)).'.err';
    $proc=proc_open([$gpg,'--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$passFile,'--decrypt','--output','/dev/null',$file],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$errorFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc) || proc_close($proc)!==0) aec_backup_fail('Encrypted backup verification failed for '.basename($file).': '.trim((string)@file_get_contents($errorFile)));
}

function aec_backup_decrypt_to_file(string $file, string $outputPath, string $passFile, string $tempDir): void {
    $gpg=aec_backup_bin('gpg'); $errorFile=$tempDir.'/decrypt-'.bin2hex(random_bytes(4)).'.err';
    $proc=proc_open([$gpg,'--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$passFile,'--decrypt','--output',$outputPath,$file],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$errorFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc) || proc_close($proc)!==0 || !is_file($outputPath)) aec_backup_fail('Could not decrypt '.basename($file).': '.trim((string)@file_get_contents($errorFile)));
    @chmod($outputPath,0600);
}

function aec_backup_decrypt_string(string $file, string $passFile, string $tempDir): string {
    $gpg=aec_backup_bin('gpg'); $errorFile=$tempDir.'/manifest-'.bin2hex(random_bytes(4)).'.err';
    $proc=proc_open([$gpg,'--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$passFile,'--decrypt',$file],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$errorFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc)) aec_backup_fail('Could not start manifest decryption.');
    $data=stream_get_contents($pipes[1],2_000_000);
    fclose($pipes[1]);
    $code=proc_close($proc);
    if ($code!==0 || !is_string($data)) aec_backup_fail('Manifest decryption failed: '.trim((string)@file_get_contents($errorFile)));
    return $data;
}

function aec_backup_run(array $command, string $stderrFile): void {
    $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$stderrFile,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc) || proc_close($proc)!==0) aec_backup_fail('Command failed: '.basename((string)($command[0] ?? 'process')).' '.trim((string)@file_get_contents($stderrFile)));
}

function aec_backup_remove_tree(string $path): void {
    if (!is_dir($path) || is_link($path)) return;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($path);
}
