<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
putenv('AEC_MAIL_ENCRYPTION_KEY=' . base64_encode(str_repeat('k', 32)));
putenv('AEC_MAIL_FROM=outbox-test@example.test');
putenv('AEC_ENV=testing');
require_once __DIR__ . '/../api/_bootstrap.php';
require_once __DIR__ . '/../api/_email.php';

function outbox_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function run_outbox_worker(string $sink): void
{
    putenv('AEC_TEST_MAIL_LOG=' . $sink);
    $process = proc_open([PHP_BINARY, __DIR__ . '/process-email-queue.php', '--limit=10'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start email outbox worker.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    outbox_assert($status === 0, 'Email worker failed: ' . $stdout . $stderr);
}

$db = aec_db();
$recipient = 'outbox-' . bin2hex(random_bytes(5)) . '@example.test';
$subject = 'Encrypted outbox integration test';
$secretUrl = 'https://example.test/?account_action=verify_email&token=' . bin2hex(random_bytes(32));
$body = "Use this one-time link: {$secretUrl}\n";
$idempotencyKey = hash('sha256', strtolower($recipient) . "\\0" . $subject . "\\0" . hash('sha256', $body));
$mailLog = sys_get_temp_dir() . '/aec-email-outbox-' . bin2hex(random_bytes(6)) . '.jsonl';
$failedSink = '/dev/null/aec-email-outbox-blocked.log';

try {
    aec_email_enqueue($db, $recipient, $subject, $body);
    aec_email_enqueue($db, $recipient, $subject, $body);
    $stmt = $db->prepare('SELECT * FROM email_outbox WHERE idempotency_key = ?');
    $stmt->execute([$idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    outbox_assert(is_array($row), 'Queued email row was not stored.');
    outbox_assert((int)$db->query("SELECT COUNT(*) FROM email_outbox WHERE idempotency_key = " . $db->quote($idempotencyKey))->fetchColumn() === 1,
        'Idempotent enqueue created a duplicate email row.');
    outbox_assert(!str_contains((string)$row['body_ciphertext'], $secretUrl), 'One-time verification URL was stored in plaintext.');
    outbox_assert(aec_email_decrypt_body($row) === $body, 'Encrypted email body did not authenticate and decrypt.');

    run_outbox_worker($failedSink);
    $stmt->execute([$idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    outbox_assert($row['status'] === 'pending' && (int)$row['attempts'] === 1 && $row['last_error_code'] === 'transport_rejected',
        'A failed transport attempt was not returned to the retry queue.');

    $db->prepare('UPDATE email_outbox SET next_attempt_at = NOW() WHERE idempotency_key = ?')->execute([$idempotencyKey]);
    run_outbox_worker($mailLog);
    $stmt->execute([$idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    outbox_assert($row['status'] === 'sent' && (int)$row['attempts'] === 2 && $row['sent_at'] !== null,
        'A successful retry was not marked as accepted by the mail transport.');
    $logged = file_get_contents($mailLog) ?: '';
    outbox_assert(str_contains($logged, $secretUrl), 'The test mail transport did not receive the decrypted message body.');

    echo "PASS encrypted email outbox: idempotency, ciphertext-at-rest, retry and successful transport handoff.\n";
} finally {
    $db->prepare('DELETE FROM email_outbox WHERE idempotency_key = ?')->execute([$idempotencyKey]);
    if (is_file($mailLog)) unlink($mailLog);
}
