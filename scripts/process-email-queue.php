<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/_bootstrap.php';
require_once __DIR__ . '/../api/_email.php';

$limit = 50;
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--limit=([0-9]{1,3})$/', $argument, $match) || (int)$match[1] < 1 || (int)$match[1] > 100) {
        fwrite(STDERR, "Usage: php scripts/process-email-queue.php [--limit=1..100]\n");
        exit(2);
    }
    $limit = (int)$match[1];
}

$from = trim((string)aec_env('AEC_MAIL_FROM', ''));
if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
    fwrite(STDERR, "AEC_MAIL_FROM must be a valid sender email in private configuration.\n");
    exit(1);
}
try {
    aec_email_encryption_key();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$db = aec_db();
$lock = $db->query("SELECT GET_LOCK('aec_email_outbox_worker', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    fwrite(STDERR, "Another email queue worker is already running.\n");
    exit(1);
}

$sent = 0;
$failed = 0;
$claimed = 0;
try {
    // Recover leases left behind by a terminated worker. SMTP delivery is at-least-once.
    $db->exec("UPDATE email_outbox SET status = IF(attempts >= max_attempts, 'failed', 'pending'), locked_at = NULL,
        next_attempt_at = NOW(), last_error_code = 'worker_lease_expired'
        WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");

    $db->beginTransaction();
    $rows = $db->query("SELECT * FROM email_outbox
        WHERE status = 'pending' AND next_attempt_at <= NOW() AND attempts < max_attempts
        ORDER BY id ASC LIMIT {$limit} FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $db->prepare("UPDATE email_outbox SET status = 'processing', attempts = attempts + 1, locked_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([(int)$row['id']]);
    }
    $db->commit();
    $claimed = count($rows);

    foreach ($rows as $row) {
        $attempt = (int)$row['attempts'] + 1;
        try {
            $body = aec_email_decrypt_body($row);
            $recipient = (string)$row['recipient_email'];
            $subject = (string)$row['subject'];
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
                throw new RuntimeException('invalid_message_headers');
            }
            if (aec_env('AEC_ENV') === 'testing' && ($sink = aec_env('AEC_TEST_MAIL_LOG')) !== null) {
                $delivered = file_put_contents($sink, json_encode([
                    'email' => $recipient, 'subject' => $subject, 'body' => $body,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
            } else {
                $headers = "From: Alom's English Center <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
                $delivered = mail($recipient, $subject, $body, $headers);
            }
            if (!$delivered) throw new RuntimeException('transport_rejected');
            $db->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), locked_at=NULL, last_error_code=NULL WHERE id=? AND status='processing'")
                ->execute([(int)$row['id']]);
            $sent++;
        } catch (Throwable $error) {
            $code = in_array($error->getMessage(), ['transport_rejected','invalid_message_headers'], true)
                ? $error->getMessage() : 'message_decryption_failed';
            if ($attempt >= (int)$row['max_attempts']) {
                $db->prepare("UPDATE email_outbox SET status='failed', locked_at=NULL, last_error_code=? WHERE id=? AND status='processing'")
                    ->execute([$code,(int)$row['id']]);
                $failed++;
            } else {
                $delay = min(60, 2 ** min(6, $attempt));
                $db->exec("UPDATE email_outbox SET status='pending', locked_at=NULL, next_attempt_at=DATE_ADD(NOW(), INTERVAL {$delay} MINUTE), last_error_code="
                    . $db->quote($code) . " WHERE id=" . (int)$row['id'] . " AND status='processing'");
            }
            error_log('AEC email outbox delivery attempt failed for message ' . (int)$row['id'] . ' (' . $code . ').');
        }
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('aec_email_outbox_worker')")->fetchColumn();
}

echo "Email outbox: {$claimed} claimed, {$sent} accepted by transport, {$failed} permanently failed.\n";
