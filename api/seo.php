<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'meta');
$stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'marketing' LIMIT 1");
$stmt->execute();
$raw = $stmt->fetchColumn();
$settings = is_string($raw) ? json_decode($raw, true) : [];
$settings = is_array($settings) ? $settings : [];
$base = rtrim((string)(getenv('AEC_APP_URL') ?: 'https://alomsenglishcenter.com'), '/');
$parts = parse_url($base);
if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
    $base = 'https://alomsenglishcenter.com';
}

if ($action === 'meta') {
    aec_method(['GET']);
    aec_ok([
        'title' => (string)($settings['siteTitleSeo'] ?? ''),
        'description' => (string)($settings['siteMetaDescription'] ?? ''),
        'canonical' => (string)((($_GET['path'] ?? '/') === '/') && !empty($settings['canonicalUrl']) ? $settings['canonicalUrl'] : $base . (preg_match('~^/[A-Za-z0-9/_-]*$~', (string)($_GET['path'] ?? '/')) ? (string)$_GET['path'] : '/')),
        'googleVerification' => (string)($settings['googleSearchConsoleToken'] ?? ''),
        'ga4Id' => (string)($settings['googleAnalyticsId'] ?? ''),
        'gtmId' => (string)($settings['googleTagManagerId'] ?? ''),
        'metaPixelId' => (string)($settings['metaPixelId'] ?? ''),
    ]);
}

if ($action === 'sitemap') {
    aec_method(['GET']);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $urls = [$base . '/', $base . '/courses', $base . '/learn', $base . '/instructors', $base . '/products', $base . '/ebooks', $base . '/live-classes', $base . '/exams', $base . '/admission', $base . '/about', $base . '/contact'];
    $courses = $db->query("SELECT id, updated_at FROM courses WHERE status = 'published' AND deleted_at IS NULL ORDER BY id DESC LIMIT 5000")->fetchAll();
    foreach ($courses as $course) $urls[] = $base . '/courses';
    $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
    foreach (array_unique($urls) as $url) $xml[] = '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
    $xml[] = '</urlset>';
    echo implode("\n", $xml);
    exit;
}

if ($action === 'robots') {
    aec_method(['GET']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $robots = trim((string)($settings['robotsTxtContent'] ?? ''));
    if ($robots === '') $robots = "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /dashboard\nSitemap: {$base}/sitemap.xml";
    if (!str_contains($robots, 'Sitemap:')) $robots .= "\nSitemap: {$base}/sitemap.xml";
    echo $robots . "\n";
    exit;
}

aec_fail('Unknown SEO action.', 404);
