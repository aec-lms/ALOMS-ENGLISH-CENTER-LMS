<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'public');

function aec_marketing_settings(PDO $db): array {
    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'marketing' LIMIT 1");
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    $settings = is_string($raw) ? json_decode($raw, true) : [];
    return is_array($settings) ? $settings : [];
}

if ($action === 'public') {
    aec_method(['GET']);
    $settings = aec_marketing_settings($db);
    unset($settings['metaConversionApiToken'], $settings['smsApiKey'], $settings['smtpPassword']);
    aec_ok($settings);
}

if ($action === 'admin_get') {
    aec_method(['GET']);
    $actor = aec_current_user();
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can view marketing integration settings.', 403);
    $settings = aec_marketing_settings($db);
    unset($settings['metaConversionApiToken'], $settings['smsApiKey'], $settings['smtpPassword']);
    $settings['emailDeliveryConfigured'] = (bool)getenv('AEC_MAIL_FROM');
    $settings['smsGatewayConfigured'] = (bool)(getenv('AEC_SMS_API_URL') && getenv('AEC_SMS_API_KEY'));
    aec_ok($settings);
}

if ($action === 'save') {
    aec_method(['POST']);
    $actor = aec_current_user();
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can update marketing integration settings.', 403);
    aec_csrf_check();
    $input = aec_input();
    $data = $input['settings'] ?? null;
    if (!is_array($data)) aec_fail('A settings object is required.', 422);
    $current = aec_marketing_settings($db);
    $fields = ['googleAnalyticsId','googleTagManagerId','metaPixelId','googleSearchConsoleToken','siteTitleSeo','siteMetaDescription','canonicalUrl','robotsTxtContent'];
    $next = [];
    foreach ($fields as $field) {
        $value = trim((string)($data[$field] ?? $current[$field] ?? ''));
        if (mb_strlen($value) > 10000) aec_fail("{$field} is too long.", 422);
        $next[$field] = $value;
    }
    if ($next['googleAnalyticsId'] !== '' && !preg_match('/^G-[A-Z0-9]{4,20}$/i', $next['googleAnalyticsId'])) aec_fail('Invalid Google Analytics measurement ID.', 422);
    if ($next['googleTagManagerId'] !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/i', $next['googleTagManagerId'])) aec_fail('Invalid Google Tag Manager container ID.', 422);
    if ($next['metaPixelId'] !== '' && !preg_match('/^[0-9]{5,30}$/', $next['metaPixelId'])) aec_fail('Meta Pixel ID must contain 5 to 30 digits.', 422);
    if (str_starts_with($next['googleSearchConsoleToken'], 'google-site-verification=')) $next['googleSearchConsoleToken'] = substr($next['googleSearchConsoleToken'], strlen('google-site-verification='));
    if ($next['googleSearchConsoleToken'] !== '' && !preg_match('/^[A-Za-z0-9_=-]{10,250}$/', $next['googleSearchConsoleToken'])) aec_fail('Invalid Search Console verification token.', 422);
    if ($next['siteTitleSeo'] !== '' && mb_strlen($next['siteTitleSeo']) > 70) aec_fail('SEO title must not exceed 70 characters.', 422);
    if (mb_strlen($next['siteMetaDescription']) > 320) aec_fail('SEO description must not exceed 320 characters.', 422);
    if ($next['canonicalUrl'] !== '') {
        $parts = parse_url($next['canonicalUrl']);
        if (!filter_var($next['canonicalUrl'], FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            aec_fail('Canonical URL must be a valid HTTPS URL.', 422);
        }
    }
    $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('marketing', ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");
    $stmt->execute([$json, (int)$actor['id']]);
    $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'marketing.settings.updated', 'site_settings', 0, ?, ?)")
        ->execute([(int)$actor['id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['keys' => array_keys($next)])]);
    aec_ok(['settings' => $next], 'Marketing and SEO settings saved.');
}

aec_fail('Unknown marketing settings action.', 404);
