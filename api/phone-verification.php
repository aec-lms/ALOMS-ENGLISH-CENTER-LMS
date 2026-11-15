<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? '');

function aec_sms_send(string $phone, string $message): bool {
    $endpoint = trim((string)(getenv('AEC_SMS_API_URL') ?: ''));
    $apiKey = trim((string)(getenv('AEC_SMS_API_KEY') ?: ''));
    $sender = trim((string)(getenv('AEC_SMS_SENDER_ID') ?: ''));
    $parts = $endpoint !== '' ? parse_url($endpoint) : false;
    if (!$apiKey || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        aec_fail('SMS delivery is not configured. Admin must set a trusted HTTPS AEC_SMS_API_URL and AEC_SMS_API_KEY privately.', 503);
    }
    $payload = json_encode(['to' => $phone, 'message' => $message, 'sender_id' => $sender ?: null]);
    $headerLines = ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer {$apiKey}"];
    if (function_exists('curl_init')) {
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if ($response === false || $curlError !== 0 || $status < 200 || $status >= 300) {
            error_log('AEC SMS provider delivery failed; HTTP status=' . $status . '; transport_error=' . $curlError);
            return false;
        }
        return true;
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        error_log('AEC SMS delivery unavailable: cURL and allow_url_fopen are disabled.');
        return false;
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\\r\\n", $headerLines), 'content' => $payload,
        'timeout' => 12, 'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $response = @file_get_contents($endpoint, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\\s2\\d\\d\\s/', $statusLine)) {
        error_log('AEC SMS provider delivery failed; status=' . substr($statusLine, 0, 80));
        return false;
    }
    return true;
}

if ($action === 'test') {
    aec_method(['POST']);
    $actor = aec_current_user();
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can test the SMS gateway.', 403);
    aec_csrf_check();
    $input = aec_input();
    $phone = trim((string)($input['phone'] ?? ''));
    if (!preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone)) aec_fail('Enter a valid phone number in international E.164 format.', 422);
    if (!aec_sms_send($phone, "Alom's English Center LMS SMS gateway test. Your SMS provider connection is working.")) {
        aec_fail('SMS gateway test delivery failed. Check the provider response and private configuration.', 502);
    }
    $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'sms.gateway_test_sent', 'sms_gateway', 0, ?, ?)")
        ->execute([(int)$actor['id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['destination' => substr($phone, 0, 4) . '***' . substr($phone, -3)])]);
    aec_ok(['sent' => true], 'SMS gateway test message sent.');
}

if ($action === 'request') {
    aec_method(['POST']);
    $actor = aec_current_user();
    aec_csrf_check();
    $input = aec_input();
    $phone = trim((string)($input['phone'] ?? ''));
    if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) aec_fail('Enter a valid phone number in international E.164 format, for example +8801XXXXXXXXX.', 422);
    $userId = (int)$actor['id'];
    $rate = $db->prepare('SELECT COUNT(*) FROM phone_verification_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $rate->execute([$userId]);
    if ((int)$rate->fetchColumn() >= 3) aec_fail('Too many OTP requests. Try again after 30 minutes.', 429);
    $db->prepare('UPDATE phone_verification_tokens SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL')->execute([$userId]);
    $otp = (string)random_int(100000, 999999);
    $hash = hash('sha256', $userId . '|' . $phone . '|' . $otp);
    $expires = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $db->prepare('INSERT INTO phone_verification_tokens (user_id, phone_e164, token_hash, expires_at, requested_ip) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $phone, $hash, $expires, $ip]);
    $db->prepare('UPDATE users SET phone = ?, phone_verified_at = NULL, updated_at = NOW() WHERE id = ?')->execute([$phone, $userId]);
    if (!aec_sms_send($phone, "Your Alom's English Center verification code is {$otp}. It expires in 5 minutes.")) {
        $db->prepare('UPDATE phone_verification_tokens SET consumed_at = NOW() WHERE user_id = ? AND token_hash = ?')->execute([$userId, $hash]);
        aec_fail('SMS provider could not deliver the verification code. Check the private SMS gateway configuration.', 502);
    }
    aec_ok(['phone' => $phone, 'expires_in_seconds' => 300], 'Verification code sent.');
}

if ($action === 'verify') {
    aec_method(['POST']);
    $actor = aec_current_user();
    aec_csrf_check();
    $input = aec_input();
    $otp = trim((string)($input['code'] ?? ''));
    if (!preg_match('/^[0-9]{6}$/', $otp)) aec_fail('Enter the 6-digit verification code.', 422);
    $userId = (int)$actor['id'];
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, phone_e164, token_hash, attempts FROM phone_verification_tokens WHERE user_id = ? AND consumed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId]);
        $token = $stmt->fetch();
        if (!$token || (int)$token['attempts'] >= 5) {
            $db->rollBack();
            aec_fail('Verification code is invalid, expired or locked. Request a new code.', 422);
        }
        $expected = hash('sha256', $userId . '|' . $token['phone_e164'] . '|' . $otp);
        if (!hash_equals((string)$token['token_hash'], $expected)) {
            $db->prepare('UPDATE phone_verification_tokens SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$token['id']]);
            $db->commit();
            aec_fail('Incorrect verification code.', 422);
        }
        $db->prepare('UPDATE phone_verification_tokens SET consumed_at = NOW() WHERE id = ?')->execute([(int)$token['id']]);
        $db->prepare('UPDATE users SET phone = ?, phone_verified_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([(string)$token['phone_e164'], $userId]);
        $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'user.phone_verified', 'user', ?, ?, ?)")
            ->execute([$userId, $userId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['method' => 'sms_otp'])]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    aec_ok(['verified' => true], 'Phone number verified.');
}

aec_fail('Unknown phone verification action.', 404);
