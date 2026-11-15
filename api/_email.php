<?php
declare(strict_types=1);

/** Encrypt queued message bodies with a dedicated 256-bit key from private config. */
function aec_email_encryption_key(): string
{
    $encoded = aec_env('AEC_MAIL_ENCRYPTION_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('AEC_MAIL_ENCRYPTION_KEY must be a base64-encoded 32-byte key in private configuration.');
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL encryption functions are required for the email outbox.');
    }
    return $key;
}

/** @return array{ciphertext:string,nonce:string,tag:string} */
function aec_email_encrypt_body(string $body, string $idempotencyKey): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($body, 'aes-256-gcm', aec_email_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, $idempotencyKey, 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Could not encrypt email body.');
    return ['ciphertext' => base64_encode($ciphertext), 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag)];
}

function aec_email_decrypt_body(array $row): string
{
    $ciphertext = base64_decode((string)($row['body_ciphertext'] ?? ''), true);
    $nonce = base64_decode((string)($row['body_nonce'] ?? ''), true);
    $tag = base64_decode((string)($row['body_tag'] ?? ''), true);
    if (!is_string($ciphertext) || !is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16) {
        throw new RuntimeException('Email outbox ciphertext metadata is invalid.');
    }
    $body = openssl_decrypt($ciphertext, 'aes-256-gcm', aec_email_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, (string)$row['idempotency_key']);
    if (!is_string($body)) throw new RuntimeException('Email outbox authentication failed.');
    return $body;
}

function aec_email_enqueue(PDO $db, string $recipient, string $subject, string $body): bool
{
    $recipient = strtolower(trim($recipient));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strlen($recipient) > 190
        || $subject === '' || strlen($subject) > 255 || preg_match('/[\\r\\n]/', $subject)
        || strlen($body) < 1 || strlen($body) > 20000) {
        throw new InvalidArgumentException('Email recipient, subject or body is invalid.');
    }
    $idempotencyKey = hash('sha256', $recipient . "\\0" . $subject . "\\0" . hash('sha256', $body));
    $encrypted = aec_email_encrypt_body($body, $idempotencyKey);
    $stmt = $db->prepare("INSERT INTO email_outbox
        (idempotency_key,recipient_email,subject,body_ciphertext,body_nonce,body_tag,status,attempts,max_attempts,next_attempt_at)
        VALUES (?,?,?,?,?,?,'pending',0,5,NOW())
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->execute([$idempotencyKey,$recipient,$subject,$encrypted['ciphertext'],$encrypted['nonce'],$encrypted['tag']]);
    return true;
}
