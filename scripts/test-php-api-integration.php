<?php
declare(strict_types=1);

/**
 * End-to-end HTTP integration checks for the PHP API.
 * Runs only against a disposable test database and a local PHP built-in server.
 */

$root = dirname(__DIR__);
$privateStorageRoot = sys_get_temp_dir() . '/aec-private-storage-' . bin2hex(random_bytes(8));
putenv('AEC_PRIVATE_STORAGE_DIR=' . $privateStorageRoot);
$host = getenv('AEC_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('AEC_TEST_DB_PORT') ?: '3306';
$name = getenv('AEC_TEST_DB_NAME') ?: 'aec_test';
$user = getenv('AEC_TEST_DB_USER') ?: 'root';
$pass = getenv('AEC_TEST_DB_PASS') ?: '';
$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$db = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

 // Migration rerun validation deliberately changes this permission to verify preservation.
 // Restore the isolated admin permission fixture before exercising protected endpoints.
$db->prepare("UPDATE role_permissions SET allowed = 1 WHERE role = 'admin' AND permission_key = 'courses.manage'")->execute();
check((int)$db->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'admin' AND permission_key = 'courses.manage' AND allowed = 1")->fetchColumn() > 0, 'Admin permission fixture did not restore before protected endpoint checks.');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_status(array $response, int $status, string $label): array
{
    check($response['status'] === $status, "{$label}: expected HTTP {$status}, got {$response['status']}; body=" . json_encode($response['body']));
    echo "PASS {$label} (HTTP {$status})" . PHP_EOL;
    return $response['body'];
}

/** @return void */
function expect_raw_status(array $response, int $status, string $label): void
{
    if (($response['status'] ?? 0) !== $status) {
        throw new RuntimeException("{$label}: expected HTTP {$status}, got " . (int)($response['status'] ?? 0));
    }
    echo "PASS {$label}" . PHP_EOL;
}


function api_upload_request(string $base, int $contentId, string $filename, string $mime, string $contents, ?string &$cookie, ?string $csrf, array $extraHeaders = []): array
{
    $boundary = '----AEC' . bin2hex(random_bytes(12));
    $body = '';
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"content_item_id\"\r\n\r\n{$contentId}\r\n";
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . addslashes($filename) . "\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n{$contents}\r\n";
    $body .= "--{$boundary}--\r\n";
    $headers = [
        'Accept: application/json',
        "Content-Type: multipart/form-data; boundary={$boundary}",
        'Content-Length: ' . strlen($body),
    ];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    foreach ($extraHeaders as $header) $headers[] = $header;
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => $body, 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $raw = file_get_contents($base . '/api/media.php?action=upload', false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\\S+\\s+(\\d{3})/', $header, $match)) $status = (int)$match[1];
        if (stripos($header, 'Set-Cookie: AEC_LMS_SESSION=') === 0) {
            $cookie = trim(explode(';', substr($header, strlen('Set-Cookie:')), 2)[0]);
        }
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'headers' => $responseHeaders];
}

function api_request(string $base, string $path, string $method = 'GET', ?array $payload = null, ?string &$cookie = null, ?string $csrf = null, array $extraHeaders = []): array
{
    $headers = ['Accept: application/json'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];
    $context = stream_context_create($options);
    $raw = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\\S+\\s+(\\d{3})/', $header, $match)) $status = (int)$match[1];
        if (stripos($header, 'Set-Cookie: AEC_LMS_SESSION=') === 0) {
            $cookie = trim(explode(';', substr($header, strlen('Set-Cookie:')), 2)[0]);
        }
    }
    $body = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'body' => is_array($body) ? $body : [], 'headers' => $responseHeaders];
}

/** @return array{status:int,body:array,headers:array} */
function api_upload_pdf(string $base, string $path, string $ebookId, string $filePath, ?string &$cookie = null, ?string $csrf = null): array
{
    $boundary = '----AECUpload' . bin2hex(random_bytes(12));
    $pdf = file_get_contents($filePath);
    if (!is_string($pdf)) throw new RuntimeException('Could not read test PDF fixture.');
    $body = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"ebook_id\"\r\n\r\n{$ebookId}\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"pdf\"; filename=\"integration-test.pdf\"\r\n"
        . "Content-Type: application/pdf\r\n\r\n{$pdf}\r\n"
        . "--{$boundary}--\r\n";
    $headers = ['Accept: application/json', 'Content-Type: multipart/form-data; boundary=' . $boundary];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\\S+\\s+(\\d{3})/', $header, $match)) $status = (int)$match[1];
        if (stripos($header, 'Set-Cookie: AEC_LMS_SESSION=') === 0) {
            $cookie = trim(explode(';', substr($header, strlen('Set-Cookie:')), 2)[0]);
        }
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'headers' => $responseHeaders];
}

/** @return array{status:int,body:string,headers:array} */
function api_download_pdf(string $base, string $path, ?string $cookie = null): array
{
    $headers = ['Accept: application/pdf'];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\\S+\\s+(\\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $responseHeaders];
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($socket !== false, 'Could not reserve a local port: ' . $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$portNumber = (int)substr(strrchr((string)$address, ':'), 1);
$base = "http://127.0.0.1:{$portNumber}";
$rootRealPath = realpath($root) ?: $root;
check(!str_starts_with($privateStorageRoot, rtrim($rootRealPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR), 'Private integration storage must be outside the project document root.');
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$portNumber}", '-t', $root], $descriptors, $pipes, $root);
check(is_resource($server), 'Could not start PHP built-in server.');

$cookie = null;
$csrf = null;
$adminId = null;
$studentId = null;
$branchManagerId = null;
$branchId = null;
$branchManagedStudentId = null;
$branchManagedCourseId = null;
$otherBranchId = null;
$teacherId = null;
$liveClassId = null;
$branchManagerLiveClassId = null;
$courseIds = [];
$draftCourseId = null;
$categoryIds = [];
$contentId = null;
$draftCoursePreviewContentId = null;
$paidContentId = null;
$examId = null;
$marksheetId = null;
$questionBankId = null;
$cmsPostId = null;
$cmsTestimonialId = null;
$paymentId = null;
$certificateId = null;
$admissionApplicationId = null;
$managedStudentId = null;
$privatePdfFixture = null;
$invalidPdfFixture = null;
$mediaAssetId = null;
$libraryBookId = null;
$libraryLoanId = null;
$libraryFinePaymentIds = [];
$libraryFineJournalIds = [];
$transportRouteId = null;
$transportAssignmentId = null;
$hostelRoomId = null;
$hostelAllocationId = null;
$expenseId = null;
$rejectedExpenseId = null;
$expenseSupplierId = null;
$expenseBudgetId = null;
$recurringTemplateId = null;
$recurringExpenseId = null;
$expenseJournalId = null;
$facilityInvoiceIds = [];
$facilityPaymentIds = [];
$facilityJournalIds = [];

try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @file_get_contents($base . '/api/auth.php?action=me', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]));
        if ($probe !== false) { $ready = true; break; }
        usleep(250000);
    }
    check($ready, 'PHP API server did not become ready.');

    $suffix = bin2hex(random_bytes(5));
    $adminEmail = "api-admin-{$suffix}@example.test";
    $studentEmail = "api-student-{$suffix}@example.test";
    $adminPassword = 'TestAdmin!Pass123';
    $studentPassword = 'TestStudent!Pass123';

    $insertUser = $db->prepare('INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)');
    $insertUser->execute(['API Integration Admin', $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), 'admin']);
    $adminId = (int)$db->lastInsertId();
    $insertUser->execute(['API Integration Student', $studentEmail, password_hash($studentPassword, PASSWORD_DEFAULT), 'student']);
    $studentId = (int)$db->lastInsertId();
    $branchId = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM branches')->fetchColumn();
    $branchCode = 'TST-' . strtoupper($suffix);
    $db->prepare('INSERT INTO branches (id, code, name) VALUES (?, ?, ?)')->execute([$branchId, $branchCode, 'API Integration Branch']);
    $otherBranchId = $branchId + 1;
    $db->prepare('INSERT INTO branches (id, code, name) VALUES (?, ?, ?)')->execute([$otherBranchId, 'TST-OTHER-' . strtoupper($suffix), 'API Integration Other Branch']);
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$branchId, $studentId]);
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$branchId, $adminId]);
    $branchManagerEmail = "api-branch-manager-{$suffix}@example.test";
    $branchManagerPassword = 'TestBranchManager!Pass123';
    $insertBranchManager = $db->prepare('INSERT INTO users (name, email, password_hash, role, branch_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $insertBranchManager->execute(['API Integration Branch Manager', $branchManagerEmail, password_hash($branchManagerPassword, PASSWORD_DEFAULT), 'branch_manager', $branchId]);
    $branchManagerId = (int)$db->lastInsertId();
    $insertUser->execute(['API Integration Teacher', "api-teacher-{$suffix}@example.test", password_hash('TestTeacher!Pass123', PASSWORD_DEFAULT), 'teacher']);
    $teacherId = (int)$db->lastInsertId();
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$branchId, $teacherId]);

    // Exercise the authentication boundary across core operational modules.
    $protectedEndpoints = [
        ['/api/users.php?action=list', 'user management'],
        ['/api/branches.php?action=list', 'branch management'],
        ['/api/enrollments.php?action=my', 'student enrollments'],
        ['/api/attendance.php?action=my', 'student attendance'],
        ['/api/certificates.php?action=list', 'certificate records'],
        ['/api/marksheets.php?action=list', 'marksheet records'],
        ['/api/payments.php?action=my', 'student payments'],
        ['/api/live_classes.php?action=list', 'live classes'],
        ['/api/question_bank.php?action=list', 'question bank'],
        ['/api/expenses.php?action=list', 'expense accounting'],
        ['/api/facility-fees.php?action=list', 'facility fee billing'],
        ['/api/hostel.php?action=rooms', 'hostel management'],
        ['/api/library.php?action=list', 'library management'],
        ['/api/transport.php?action=list', 'transport management'],
        ['/api/saas-tenants.php?action=list', 'SaaS tenant control plane'],
    ];
    foreach ($protectedEndpoints as [$endpoint, $label]) {
        expect_status(api_request($base, $endpoint), 401, 'Unauthenticated ' . $label . ' request is rejected');
    }

    expect_status(api_request($base, '/api/notifications.php?action=list'), 401, 'Unauthenticated notification inbox is rejected');
    expect_status(api_request($base, '/api/content.php?action=list'), 401, 'Unauthenticated protected content request is rejected');
    expect_status(api_request($base, '/api/content.php?action=progress&course_id=1'), 401, 'Unauthenticated learning progress request is rejected');
    expect_status(api_request($base, '/api/exams.php?action=list'), 401, 'Unauthenticated exam list request is rejected');
    expect_status(api_request($base, '/api/cms.php?action=posts', 'GET', null, $cookie), 401, 'Unauthenticated CMS post management is rejected');
    expect_status(api_request($base, '/api/admissions.php?action=list'), 401, 'Unauthenticated admission application management is rejected');
    expect_status(api_request($base, '/api/reports.php?type=revenue'), 401, 'Unauthenticated reporting is rejected');
    expect_status(api_request($base, '/api/commissions.php?action=summary'), 401, 'Unauthenticated commission summary is rejected');
    expect_status(api_request($base, '/api/commissions.php?action=payouts'), 401, 'Unauthenticated payout history is rejected');
    expect_status(api_request($base, '/api/batches.php?action=list'), 401, 'Unauthenticated batch list is rejected');
    expect_status(api_request($base, '/api/batches.php?action=routines'), 401, 'Unauthenticated routine list is rejected');
    expect_status(api_request($base, '/api/cms.php?action=settings'), 200, 'Public website settings are readable without login');
    expect_status(api_request($base, '/api/cms.php?action=public_posts'), 200, 'Public blog list is readable without login');
    expect_status(api_request($base, '/api/cms.php?action=public_testimonials'), 200, 'Public testimonials are readable without login');
    expect_status(api_request($base, '/api/auth.php?action=login', 'POST', ['email' => $adminEmail, 'password' => 'wrong-password'], $cookie), 401, 'Invalid credentials are rejected');

    $login = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', ['email' => $adminEmail, 'password' => $adminPassword], $cookie), 200, 'Admin login');
    $adminCsrf = $login['data']['csrf_token'] ?? null;
    expect_status(api_request($base, '/api/marketing-settings.php?action=admin_get', 'GET', null, $cookie), 200, 'Admin reads persisted marketing settings');
    expect_status(api_request($base, '/api/marketing-settings.php?action=save', 'POST', [
        'settings' => [
            'googleAnalyticsId' => 'G-TEST1234',
            'googleTagManagerId' => 'GTM-TEST1234',
            'metaPixelId' => '123456789012345',
            'googleSearchConsoleToken' => 'test_verification_token_123',
            'siteTitleSeo' => 'AEC LMS Integration Test',
            'siteMetaDescription' => 'SEO settings integration test description.',
            'canonicalUrl' => 'https://example.test/',
            'robotsTxtContent' => "User-agent: *\\nDisallow: /private",
        ],
    ], $cookie, $adminCsrf), 200, 'Admin saves server-persisted marketing and SEO settings');
    $publicMarketing = expect_status(api_request($base, '/api/marketing-settings.php?action=public'), 200, 'Public marketing settings are readable');
    check(($publicMarketing['data']['metaPixelId'] ?? '') === '123456789012345'
        && !array_key_exists('smsApiKey', $publicMarketing['data'] ?? [])
        && !array_key_exists('metaConversionApiToken', $publicMarketing['data'] ?? []),
        'Public marketing settings must expose only non-secret tracking IDs.');
    $seoMeta = expect_status(api_request($base, '/api/seo.php?action=meta&path=%2Fcourses'), 200, 'Public SEO metadata endpoint responds');
    check(($seoMeta['data']['googleVerification'] ?? '') === 'test_verification_token_123', 'SEO metadata must expose the saved Search Console verification token.');
    check(str_ends_with((string)($seoMeta['data']['canonical'] ?? ''), '/courses'), 'SEO metadata must expose page-specific canonical URLs.');

    $originalSeoTitle = (string)($publicMarketing['data']['siteTitleSeo'] ?? '');
    $originalMarketingRow = $db->query("SELECT setting_value, updated_by, updated_at FROM site_settings WHERE setting_key = 'marketing'")->fetch(PDO::FETCH_ASSOC);
    expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', ['action_type' => 'update_marketing_settings', 'payload' => ['siteTitleSeo' => 'Should not apply']], $cookie), 419, 'AI action preview rejects missing CSRF');
    $aiPreview = expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', [
        'action_type' => 'update_marketing_settings',
        'payload' => ['siteTitleSeo' => 'AI Approval Integration Test'],
    ], $cookie, $adminCsrf), 200, 'Admin previews a typed AI marketing action');
    $aiProposalId = (int)($aiPreview['data']['proposal_id'] ?? 0);
    check($aiProposalId > 0 && ($aiPreview['data']['preview']['after']['settings']['siteTitleSeo'] ?? '') === 'AI Approval Integration Test',
        'AI preview must return an accurate before/after proposal without applying it.');
    $stillOriginal = expect_status(api_request($base, '/api/marketing-settings.php?action=public'), 200, 'Marketing settings remain unchanged before AI approval');
    check(($stillOriginal['data']['siteTitleSeo'] ?? '') === $originalSeoTitle, 'AI preview must not mutate live settings.');
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', ['proposal_id' => $aiProposalId, 'confirmation' => 'WRONG'], $cookie, $adminCsrf), 422, 'AI action rejects an incorrect approval phrase');
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', ['proposal_id' => $aiProposalId, 'confirmation' => 'APPLY'], $cookie, $adminCsrf), 200, 'Admin explicitly approves and applies the AI action');
    $appliedSettings = expect_status(api_request($base, '/api/marketing-settings.php?action=public'), 200, 'Read settings after AI action approval');
    check(($appliedSettings['data']['siteTitleSeo'] ?? '') === 'AI Approval Integration Test', 'Approved AI action must update the whitelisted setting.');
    expect_status(api_request($base, '/api/ai-actions.php?action=rollback', 'POST', ['proposal_id' => $aiProposalId, 'confirmation' => 'ROLLBACK'], $cookie, $adminCsrf), 200, 'Admin rolls back an AI action from its backup');
    $restoredSettings = expect_status(api_request($base, '/api/marketing-settings.php?action=public'), 200, 'Read settings after AI rollback');
    check(($restoredSettings['data']['siteTitleSeo'] ?? '') === $originalSeoTitle, 'AI rollback must restore the exact previous marketing setting.');
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', ['proposal_id' => $aiProposalId, 'confirmation' => 'APPLY'], $cookie, $adminCsrf), 409, 'AI proposal replay is rejected after rollback');
$restoredMarketingRow = $db->query("SELECT setting_value, updated_by, updated_at FROM site_settings WHERE setting_key = 'marketing'")->fetch(PDO::FETCH_ASSOC);
    check(is_array($originalMarketingRow) && is_array($restoredMarketingRow)
        && $restoredMarketingRow['setting_value'] === $originalMarketingRow['setting_value']
        && $restoredMarketingRow['updated_by'] === $originalMarketingRow['updated_by']
        && $restoredMarketingRow['updated_at'] === $originalMarketingRow['updated_at'],
        'AI rollback must restore the exact marketing settings value and metadata.');
    $aiBackupCount = $db->prepare('SELECT COUNT(*) FROM ai_action_backups WHERE proposal_id = ?');
    $aiBackupCount->execute([$aiProposalId]);
    check((int)$aiBackupCount->fetchColumn() === 1, 'AI action must retain exactly one before-image backup.');
    $aiAuditCount = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type='ai_action_proposal' AND entity_id=? AND action IN ('ai.action.previewed','ai.action.applied','ai.action.rolled_back')");
    $aiAuditCount->execute([$aiProposalId]);
    check((int)$aiAuditCount->fetchColumn() === 3, 'AI preview, apply and rollback must each be audited.');

    expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', [
        'action_type' => 'update_marketing_settings',
        'payload' => ['siteTitleSeo' => 'Valid field', 'unexpected_sql' => 'DROP TABLE users'],
    ], $cookie, $adminCsrf), 422, 'AI action rejects fields outside the strict allowlist');

    $crossOwnerPreview = expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', [
        'action_type' => 'update_marketing_settings',
        'payload' => ['siteTitleSeo' => 'Owner-bound proposal'],
    ], $cookie, $adminCsrf), 200, 'Admin creates an owner-bound proposal');
    $crossOwnerProposalId = (int)$crossOwnerPreview['data']['proposal_id'];
    $secondAdminEmail = "api-second-admin-{$suffix}@example.test";
    $secondAdminPassword = 'SecondAdmin!Pass123';
    $insertUser->execute(['Second API Integration Admin', $secondAdminEmail, password_hash($secondAdminPassword, PASSWORD_DEFAULT), 'admin']);
    $secondAdminCookie = null;
    $secondAdminLogin = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $secondAdminEmail, 'password' => $secondAdminPassword,
    ], $secondAdminCookie), 200, 'Second Admin login for proposal ownership test');
    $secondAdminCsrf = $secondAdminLogin['data']['csrf_token'] ?? null;
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', [
        'proposal_id' => $crossOwnerProposalId, 'confirmation' => 'APPLY',
    ], $secondAdminCookie, $secondAdminCsrf), 409, 'Another Admin cannot approve a proposal they did not create');
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', [
        'proposal_id' => $crossOwnerProposalId, 'confirmation' => 'APPLY',
    ], $cookie, $adminCsrf), 200, 'Proposal owner can approve after cross-owner rejection');
    expect_status(api_request($base, '/api/ai-actions.php?action=rollback', 'POST', [
        'proposal_id' => $crossOwnerProposalId, 'confirmation' => 'ROLLBACK',
    ], $cookie, $adminCsrf), 200, 'Owner rolls back cross-owner test proposal');

    $expiredPreview = expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', [
        'action_type' => 'update_marketing_settings',
        'payload' => ['siteTitleSeo' => 'Expired proposal must not apply'],
    ], $cookie, $adminCsrf), 200, 'Admin creates a proposal for expiry test');
    $expiredProposalId = (int)$expiredPreview['data']['proposal_id'];
    $db->prepare('UPDATE ai_action_proposals SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$expiredProposalId]);
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', [
        'proposal_id' => $expiredProposalId, 'confirmation' => 'APPLY',
    ], $cookie, $adminCsrf), 409, 'Expired AI proposal cannot be approved');
    $expiredStatus = $db->prepare('SELECT status FROM ai_action_proposals WHERE id = ?');
    $expiredStatus->execute([$expiredProposalId]);
    check($expiredStatus->fetchColumn() === 'expired', 'Expired AI proposal must be marked expired.');
$stalePreview = expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', [
        'action_type' => 'update_marketing_settings',
        'payload' => ['siteTitleSeo' => 'Stale proposal must not overwrite concurrent edits'],
    ], $cookie, $adminCsrf), 200, 'Admin previews an AI action before concurrent-edit test');
    $staleProposalId = (int)$stalePreview['data']['proposal_id'];
    $staleBefore = $db->query("SELECT setting_value, updated_by, updated_at FROM site_settings WHERE setting_key = 'marketing'")->fetch(PDO::FETCH_ASSOC);
    $concurrentSettings = json_decode((string)$staleBefore['setting_value'], true) ?: [];
    $concurrentSettings['siteTitleSeo'] = 'Concurrent Admin edit';
    $db->prepare("UPDATE site_settings SET setting_value=?, updated_at=NOW() WHERE setting_key='marketing'")
        ->execute([json_encode($concurrentSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    expect_status(api_request($base, '/api/ai-actions.php?action=approve', 'POST', [
        'proposal_id' => $staleProposalId, 'confirmation' => 'APPLY',
    ], $cookie, $adminCsrf), 409, 'AI approval refuses a stale preview after concurrent settings changes');
    $db->prepare("UPDATE site_settings SET setting_value=?, updated_by=?, updated_at=? WHERE setting_key='marketing'")
        ->execute([$staleBefore['setting_value'], $staleBefore['updated_by'], $staleBefore['updated_at']]);
    $concurrentValue = $db->query("SELECT setting_value FROM site_settings WHERE setting_key='marketing'")->fetchColumn();
    check($concurrentValue === $staleBefore['setting_value'], 'Stale-preview rejection must not overwrite or corrupt concurrent settings.');

    $noticeCreate = expect_status(api_request($base, '/api/notices.php?action=create', 'POST', [
        'title' => 'Integration test notice',
        'body' => 'Persistent notice board test body.',
        'audience_role' => 'all',
    ], $cookie, $adminCsrf), 201, 'Admin creates a persistent notice');
    $noticeId = (int)($noticeCreate['data']['id'] ?? 0);
    check($noticeId > 0, 'Notice creation must return a persistent database ID.');
    $adminNoticeList = expect_status(api_request($base, '/api/notices.php?action=list', 'GET', null, $cookie), 200, 'Admin lists persistent notices');
    check(count(array_filter($adminNoticeList['data'] ?? [], static fn(array $row): bool => (int)$row['id'] === $noticeId)) === 1,
        'New notice must be present in the persistent Admin notice list.');


    expect_status(api_request($base, '/api/saas-tenants.php?action=list', 'GET', null, $cookie), 200, 'Unified Admin can access SaaS tenant control plane');
    $superAdminEmail = "api-super-admin-{$suffix}@example.test";
    $superAdminPassword = 'TestSuperAdmin!Pass123';
    $insertUser->execute(['API Integration Super Admin', $superAdminEmail, password_hash($superAdminPassword, PASSWORD_DEFAULT), 'super_admin']);
    $superAdminId = (int)$db->lastInsertId();
    $superAdminCookie = null;
    $superAdminLogin = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $superAdminEmail, 'password' => $superAdminPassword,
    ], $superAdminCookie), 200, 'Super administrator login for SaaS control-plane test');
    $superAdminCsrf = $superAdminLogin['data']['csrf_token'] ?? null;
    $saasPlans = expect_status(api_request($base, '/api/saas-tenants.php?action=plans', 'GET', null, $superAdminCookie), 200, 'Super administrator lists active SaaS plans');
    check(in_array('professional', array_column($saasPlans['data'] ?? [], 'plan_key'), true), 'SaaS plan catalog must include the seeded professional plan.');
    $tenantKey = 'it-' . $suffix;
    $tenantDomain = 'tenant-' . $suffix . '.example.test';
    $tenantCreate = expect_status(api_request($base, '/api/saas-tenants.php?action=create', 'POST', [
        'tenant_key' => $tenantKey, 'name' => 'Integration Test Tenant', 'contact_email' => 'tenant-' . $suffix . '@example.test',
        'city' => 'Rangpur', 'plan_key' => 'professional', 'domain' => $tenantDomain,
    ], $superAdminCookie, $superAdminCsrf), 201, 'Super administrator registers SaaS tenant');
    $saasTenantId = (int)($tenantCreate['data']['id'] ?? 0);
    $domainChallenge = expect_status(api_request($base, '/api/saas-tenants.php?action=domain-challenge&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 200, 'Super administrator requests a DNS TXT domain challenge');
    check(($domainChallenge['data']['record_type'] ?? '') === 'TXT'
        && ($domainChallenge['data']['record_name'] ?? '') === '_aec-verification.' . $tenantDomain
        && preg_match('/^aec-verification=[a-f0-9]{64}$/', (string)($domainChallenge['data']['record_value'] ?? '')) === 1,
        'Domain challenge must return a DNS TXT record name and cryptographically random value.');
    expect_status(api_request($base, '/api/saas-tenants.php?action=verify-domain&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 409, 'Domain verification rejects a missing DNS TXT challenge');
    expect_status(api_request($base, '/api/saas-tenants.php?action=domain-challenge&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 200, 'Second domain challenge remains within the hourly limit');
    expect_status(api_request($base, '/api/saas-tenants.php?action=domain-challenge&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 200, 'Third domain challenge remains within the hourly limit');
    expect_status(api_request($base, '/api/saas-tenants.php?action=domain-challenge&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 429, 'Fourth domain challenge is rate limited');
    for ($attempt = 2; $attempt <= 10; $attempt++) {
        expect_status(api_request($base, '/api/saas-tenants.php?action=verify-domain&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 409, 'Missing DNS TXT record remains unverified within attempt budget');
    }
    expect_status(api_request($base, '/api/saas-tenants.php?action=verify-domain&id=' . $saasTenantId, 'POST', [], $superAdminCookie, $superAdminCsrf), 429, 'Eleventh DNS verification attempt is rate limited');
    check($saasTenantId > 0 && ($tenantCreate['data']['provisioned'] ?? true) === false, 'SaaS registry must not claim the tenant database is provisioned.');
    $tenantDirectory = expect_status(api_request($base, '/api/saas-tenants.php?action=list', 'GET', null, $superAdminCookie), 200, 'Super administrator lists SaaS tenant registry');
    check(count(array_filter($tenantDirectory['data'] ?? [], static fn(array $tenant): bool => (int)$tenant['id'] === $saasTenantId && ($tenant['domains'] ?? '') === $tenantDomain)) === 1,
        'SaaS tenant registry must return the registered tenant and domain.');
    expect_status(api_request($base, '/api/saas-tenants.php?action=create', 'POST', [
        'tenant_key' => 'it-duplicate-' . $suffix, 'name' => 'Duplicate Domain Tenant',
        'plan_key' => 'professional', 'domain' => $tenantDomain,
    ], $superAdminCookie, $superAdminCsrf), 409, 'Duplicate tenant domain is rejected');
    expect_status(api_request($base, '/api/saas-tenants.php?action=status&id=' . $saasTenantId, 'POST', [
        'status' => 'suspended',
    ], $superAdminCookie, $superAdminCsrf), 409, 'Pending tenant cannot be moved to suspended to bypass provisioning guard');
    expect_status(api_request($base, '/api/saas-tenants.php?action=status&id=' . $saasTenantId, 'POST', [
        'status' => 'active',
    ], $superAdminCookie, $superAdminCsrf), 409, 'Pending tenant cannot be activated before data-plane provisioning and domain verification');
    $tenantAfterRejectedTransitions = expect_status(api_request($base, '/api/saas-tenants.php?action=list', 'GET', null, $superAdminCookie), 200, 'Super administrator rechecks pending tenant after rejected status transitions');
    $pendingTenantRows = array_values(array_filter($tenantAfterRejectedTransitions['data'] ?? [], static fn(array $tenant): bool => (int)$tenant['id'] === $saasTenantId));
    check(count($pendingTenantRows) === 1 && $pendingTenantRows[0]['status'] === 'pending', 'Rejected status transitions must leave the tenant pending.');
    $db->prepare("UPDATE saas_tenants SET status = 'suspended' WHERE id = ?")->execute([$saasTenantId]);
    expect_status(api_request($base, '/api/saas-tenants.php?action=status&id=' . $saasTenantId, 'POST', [
        'status' => 'active',
    ], $superAdminCookie, $superAdminCsrf), 409, 'Suspended tenant cannot be reactivated before data-plane isolation is implemented');
    $suspendedTenantStatus = $db->prepare('SELECT status FROM saas_tenants WHERE id = ?');
    $suspendedTenantStatus->execute([$saasTenantId]);
    check($suspendedTenantStatus->fetchColumn() === 'suspended', 'Rejected reactivation must preserve suspended tenant status.');

    $branchManagerCookie = null;
    $branchManagerLogin = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $branchManagerEmail, 'password' => $branchManagerPassword,
    ], $branchManagerCookie), 200, 'Branch manager login for branch isolation test');
    $branchManagerCsrf = $branchManagerLogin['data']['csrf_token'] ?? null;
    expect_status(api_request($base, '/api/ai-actions.php?action=preview', 'POST', ['action_type' => 'update_marketing_settings', 'payload' => ['siteTitleSeo' => 'Forbidden']], $branchManagerCookie, $branchManagerCsrf), 403, 'Non-Admin cannot preview AI actions');
    $branchNotices = expect_status(api_request($base, '/api/notices.php?action=visible', 'GET', null, $branchManagerCookie), 200, 'Branch manager reads role-targeted notices');
    check(count(array_filter($branchNotices['data'] ?? [], static fn(array $row): bool => (int)$row['id'] === $noticeId)) === 1,
        'A notice addressed to all roles must be visible to branch managers.');
    expect_status(api_request($base, '/api/notices.php?action=mark_read', 'POST', ['notice_id' => $noticeId], $branchManagerCookie, $branchManagerCsrf), 200, 'Branch manager marks a notice as read');


    $otherBranchUserEmail = "api-other-branch-user-{$suffix}@example.test";
    $insertUser->execute(['API Integration Other Branch Student', $otherBranchUserEmail, password_hash('TestOtherBranch!Pass123', PASSWORD_DEFAULT), 'student']);
    $otherBranchUserId = (int)$db->lastInsertId();
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$otherBranchId, $otherBranchUserId]);
    $db->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES ('branch_manager', 'notifications.broadcast', 1) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)")->execute();
    $branchBroadcast = expect_status(api_request($base, '/api/notifications.php?action=broadcast', 'POST', [
        'title' => 'Branch-only notice', 'message' => 'This announcement must stay inside the assigned branch.',
        'type' => 'info', 'target_role' => 'all',
    ], $branchManagerCookie, $branchManagerCsrf), 201, 'Branch manager broadcasts only within the assigned branch');
    check((int)($branchBroadcast['data']['branch_id'] ?? 0) === $branchId && (int)($branchBroadcast['data']['recipient_count'] ?? 0) > 0,
        'Branch manager broadcast response must identify its branch and recipients.');
    $foreignBranchBroadcastCount = $db->prepare('SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.recipient_user_id WHERE n.sender_user_id = ? AND u.branch_id <> ?');
    $foreignBranchBroadcastCount->execute([$branchManagerId, $branchId]);
    check((int)$foreignBranchBroadcastCount->fetchColumn() === 0, 'Branch manager broadcast must not create notifications for another branch.');
    $branchUserList = expect_status(
        api_request($base, '/api/users.php?action=list', 'GET', null, $branchManagerCookie),
        200,
        'Branch manager lists users in the assigned branch'
    );
    $branchUserIds = array_map(static fn(array $user): int => (int)$user['id'], $branchUserList['data'] ?? []);
    check(!in_array($otherBranchUserId, $branchUserIds, true)
        && count(array_filter($branchUserList['data'] ?? [], static fn(array $user): bool => (int)$user['branch_id'] !== $branchId)) === 0,
        'Branch manager user list must exclude users from other branches.');

    $branchCreatedEmail = "api-branch-created-{$suffix}@example.test";
    $branchCreatedUser = expect_status(api_request($base, '/api/users.php?action=create', 'POST', [
        'name' => 'Branch Manager Created Student',
        'email' => $branchCreatedEmail,
        'password' => 'TestBranchCreated!Pass123',
        'role' => 'student',
        'branch_id' => $otherBranchId,
    ], $branchManagerCookie, $branchManagerCsrf), 201, 'Branch manager creates a user with an untrusted client branch ID');
    $branchCreatedUserId = (int)($branchCreatedUser['data']['id'] ?? 0);
    $createdUserBranch = $db->prepare('SELECT branch_id FROM users WHERE id = ?');
    $createdUserBranch->execute([$branchCreatedUserId]);
    check($branchCreatedUserId > 0 && (int)$createdUserBranch->fetchColumn() === $branchId,
        'Branch manager-created users must be forced into the manager assigned branch.');
    expect_status(api_request($base, '/api/users.php?action=update&id=' . $otherBranchUserId, 'POST', [
        'name' => 'Cross-branch unauthorized edit',
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot update a user from another branch');


    $branchOwnedCourseSlug = 'branch-owned-' . $suffix;
    $otherOwnedCourseSlug = 'other-owned-' . $suffix;
    $insertScopedCourse = $db->prepare("INSERT INTO courses (branch_id, title, slug, status, created_by) VALUES (?, ?, ?, 'published', ?)");
    $insertScopedCourse->execute([$branchId, 'Branch-scoped integration course', $branchOwnedCourseSlug, $adminId]);
    $branchOwnedCourseId = (int)$db->lastInsertId();
    $insertScopedCourse->execute([$otherBranchId, 'Other-branch integration course', $otherOwnedCourseSlug, $adminId]);
    $otherOwnedCourseId = (int)$db->lastInsertId();
    $branchCourseList = expect_status(
        api_request($base, '/api/courses.php?action=list', 'GET', null, $branchManagerCookie),
        200,
        'Branch manager lists courses in the assigned branch'
    );
    $branchCourseIds = array_map(static fn(array $course): int => (int)$course['id'], $branchCourseList['data'] ?? []);
    check(in_array($branchOwnedCourseId, $branchCourseIds, true) && !in_array($otherOwnedCourseId, $branchCourseIds, true),
        'Branch manager course list must include assigned-branch courses and exclude other-branch courses.');
    expect_status(
        api_request($base, '/api/courses.php?action=detail&id=' . $branchOwnedCourseId, 'GET', null, $branchManagerCookie),
        200,
        'Branch manager can read a course in the assigned branch'
    );
    expect_status(
        api_request($base, '/api/courses.php?action=detail&id=' . $otherOwnedCourseId, 'GET', null, $branchManagerCookie),
        404,
        'Branch manager cannot read a course from another branch'
    );


    // Grant deliberately unsafe permissions to prove endpoint-level guards cannot be
    // bypassed by a stale or manually edited role_permissions row.
    $db->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES
        ('branch_manager', 'question_bank.manage', 1), ('branch_manager', 'exams.manage', 1),
        ('branch_manager', 'marksheets.manage', 1), ('branch_manager', 'cms.manage', 1),
        ('branch_manager', 'content.manage', 1), ('branch_manager', 'commissions.manage', 1),
        ('branch_manager', 'admissions.manage', 1), ('branch_manager', 'storefront.orders.manage', 1),
        ('branch_manager', 'branches.manage', 1), ('branch_manager', 'batches.manage', 1),
        ('branch_manager', 'attendance.manage', 1), ('branch_manager', 'enrollments.manage', 1),
        ('branch_manager', 'certificates.manage', 1), ('branch_manager', 'payments.manage', 1),
        ('branch_manager', 'live_classes.manage', 1), ('branch_manager', 'hostel.manage', 1),
        ('branch_manager', 'transport.manage', 1), ('branch_manager', 'expenses.manage', 1),
        ('branch_manager', 'library.fines.manage', 1), ('branch_manager', 'facility.fees.manage', 1)
        ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)")->execute();
    $db->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES
        ('admin', 'batches.manage', 1), ('admin', 'attendance.manage', 1),
        ('admin', 'enrollments.manage', 1), ('admin', 'certificates.manage', 1),
        ('admin', 'payments.manage', 1), ('admin', 'live_classes.manage', 1),
        ('admin', 'hostel.manage', 1), ('admin', 'transport.manage', 1), ('admin', 'facility.fees.manage', 1)
        ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)")->execute();

    $otherTeacherEmail = "api-other-branch-teacher-{$suffix}@example.test";
    $insertUser->execute(['API Integration Other Branch Teacher', $otherTeacherEmail, password_hash('TestOtherTeacher!Pass123', PASSWORD_DEFAULT), 'teacher']);
    $otherTeacherId = (int)$db->lastInsertId();
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$otherBranchId, $otherTeacherId]);

    $enrollmentFixture = $db->prepare("INSERT INTO course_enrollments (user_id, course_id, branch_id, status, enrolled_by, completed_at)
        VALUES (?, ?, ?, 'completed', ?, NOW())
        ON DUPLICATE KEY UPDATE branch_id = VALUES(branch_id), status = 'completed', completed_at = NOW()");
    $enrollmentFixture->execute([$studentId, $branchOwnedCourseId, $branchId, $adminId]);
    $enrollmentFixture->execute([$otherBranchUserId, $otherOwnedCourseId, $otherBranchId, $adminId]);
    $branchEnrollmentIdStmt = $db->prepare('SELECT id FROM course_enrollments WHERE user_id = ? AND course_id = ?');
    $branchEnrollmentIdStmt->execute([$studentId, $branchOwnedCourseId]);
    $branchEnrollmentId = (int)$branchEnrollmentIdStmt->fetchColumn();
    $otherEnrollmentIdStmt = $db->prepare('SELECT id FROM course_enrollments WHERE user_id = ? AND course_id = ?');
    $otherEnrollmentIdStmt->execute([$otherBranchUserId, $otherOwnedCourseId]);
    $otherEnrollmentId = (int)$otherEnrollmentIdStmt->fetchColumn();

    $today = date('Y-m-d');
    $attendanceFixture = $db->prepare("INSERT INTO attendance_records (course_id, branch_id, user_id, attendance_date, status, remarks, marked_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'branch isolation fixture', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE branch_id = VALUES(branch_id), status = VALUES(status), marked_by = VALUES(marked_by)");
    $attendanceFixture->execute([$branchOwnedCourseId, $branchId, $studentId, $today, 'present', $adminId]);
    $attendanceFixture->execute([$otherOwnedCourseId, $otherBranchId, $otherBranchUserId, $today, 'absent', $adminId]);

    $batchFixture = $db->prepare("INSERT INTO batches (course_id, branch_id, instructor_id, name, batch_code, schedule, room_number, max_capacity, start_date, end_date, status, created_by)
        VALUES (?, ?, ?, ?, ?, 'Sun/Tue 10:00', 'R-101', 30, ?, ?, 'upcoming', ?)");
    $batchFixture->execute([$branchOwnedCourseId, $branchId, $teacherId, 'Assigned Branch Batch', 'BM-' . strtoupper($suffix), $today, date('Y-m-d', strtotime('+30 days')), $adminId]);
    $branchBatchId = (int)$db->lastInsertId();
    $batchFixture->execute([$otherOwnedCourseId, $otherBranchId, $otherTeacherId, 'Other Branch Batch', 'OT-' . strtoupper($suffix), $today, date('Y-m-d', strtotime('+30 days')), $adminId]);
    $otherBatchId = (int)$db->lastInsertId();
    $routineFixture = $db->prepare("INSERT INTO class_routine_slots (batch_id, branch_id, instructor_id, day_of_week, start_time, end_time, subject, room_number, created_by)
        VALUES (?, ?, ?, 'Sunday', '10:00:00', '11:00:00', ?, 'R-101', ?)");
    $routineFixture->execute([$branchBatchId, $branchId, $teacherId, 'Assigned Branch Routine', $adminId]);
    $branchRoutineId = (int)$db->lastInsertId();
    $routineFixture->execute([$otherBatchId, $otherBranchId, $otherTeacherId, 'Other Branch Routine', $adminId]);
    $otherRoutineId = (int)$db->lastInsertId();

    $paymentFixture = $db->prepare("INSERT INTO course_payments (user_id, course_id, branch_id, amount, payment_method, trx_id, sender_phone)
        VALUES (?, ?, ?, 10.00, 'bKash', ?, '01700000000')");
    $paymentFixture->execute([$studentId, $branchOwnedCourseId, $branchId, 'BM-PAY-' . strtoupper($suffix)]);
    $branchPaymentId = (int)$db->lastInsertId();
    $paymentFixture->execute([$otherBranchUserId, $otherOwnedCourseId, $otherBranchId, 'OT-PAY-' . strtoupper($suffix)]);
    $otherPaymentId = (int)$db->lastInsertId();

    $liveClassFixture = $db->prepare("INSERT INTO live_classes (course_id, instructor_id, branch_id, title, starts_at, meeting_platform, meeting_url, created_by)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), 'google_meet', 'https://meet.google.com/abc-defg-hij', ?)");
    $liveClassFixture->execute([$branchOwnedCourseId, $teacherId, $branchId, 'Assigned Branch Live Class', $adminId]);
    $branchLiveClassId = (int)$db->lastInsertId();
    $liveClassFixture->execute([$otherOwnedCourseId, $otherTeacherId, $otherBranchId, 'Other Branch Live Class', $adminId]);
    $otherLiveClassId = (int)$db->lastInsertId();

    $roomFixture = $db->prepare("INSERT INTO hostel_rooms (branch_id, building_name, room_number, room_type, monthly_rent, capacity, amenities, warden_name, warden_phone, created_by)
        VALUES (?, 'Test Building', ?, 'single', 1000.00, 4, '[]', 'Test Warden', '01700000000', ?)");
    $roomFixture->execute([$branchId, 'BR-' . strtoupper($suffix), $adminId]);
    $branchRoomId = (int)$db->lastInsertId();
    $roomFixture->execute([$otherBranchId, 'OT-' . strtoupper($suffix), $adminId]);
    $otherRoomId = (int)$db->lastInsertId();

    $routeFixture = $db->prepare("INSERT INTO transport_routes (branch_id, route_name, route_name_bn, vehicle_number, driver_name, driver_phone, monthly_fee, capacity, stops, created_by)
        VALUES (?, ?, 'পরীক্ষা রুট', ?, 'Test Driver', '01700000000', 500.00, 20, '[]', ?)");
    $routeFixture->execute([$branchId, 'Assigned Branch Route', 'BR-' . strtoupper($suffix), $adminId]);
    $branchRouteId = (int)$db->lastInsertId();
    $routeFixture->execute([$otherBranchId, 'Other Branch Route', 'OT-' . strtoupper($suffix), $adminId]);
    $otherRouteId = (int)$db->lastInsertId();

    $allocationFixture = $db->prepare("INSERT INTO hostel_allocations (room_id, branch_id, student_user_id, allocated_by, start_date, monthly_rent_snapshot)
        VALUES (?, ?, ?, ?, ?, 1000.00)");
    $allocationFixture->execute([$branchRoomId, $branchId, $studentId, $adminId, $today]);
    $branchHostelAllocationId = (int)$db->lastInsertId();
    $allocationFixture->execute([$otherRoomId, $otherBranchId, $otherBranchUserId, $adminId, $today]);
    $otherHostelAllocationId = (int)$db->lastInsertId();
    $transportAssignmentFixture = $db->prepare("INSERT INTO transport_assignments (route_id, branch_id, student_user_id, assigned_by, start_date, monthly_fee_snapshot)
        VALUES (?, ?, ?, ?, ?, 500.00)");
    $transportAssignmentFixture->execute([$branchRouteId, $branchId, $studentId, $adminId, $today]);
    $branchTransportAssignmentId = (int)$db->lastInsertId();
    $transportAssignmentFixture->execute([$otherRouteId, $otherBranchId, $otherBranchUserId, $adminId, $today]);
    $otherTransportAssignmentId = (int)$db->lastInsertId();

    $bookFixture = $db->prepare("INSERT INTO library_books (branch_id, isbn, title, author, category, shelf_location, total_copies, available_copies, daily_fine, created_by)
        VALUES (?, ?, ?, 'Integration Author', 'Testing', 'A-01', 1, 1, 5.00, ?)");
    $bookFixture->execute([$branchId, 'BR-' . strtoupper($suffix), 'Assigned Branch Library Book', $adminId]);
    $branchBookId = (int)$db->lastInsertId();
    $bookFixture->execute([$otherBranchId, 'OT-' . strtoupper($suffix), 'Other Branch Library Book', $adminId]);
    $otherBookId = (int)$db->lastInsertId();
    $loanFixture = $db->prepare("INSERT INTO library_book_loans (book_id, branch_id, student_user_id, issued_by, due_date, returned_at, daily_fine_snapshot, fine_amount, status)
        VALUES (?, ?, ?, ?, DATE_SUB(CURDATE(), INTERVAL 5 DAY), NOW(), 5.00, 25.00, 'returned')");
    $loanFixture->execute([$branchBookId, $branchId, $studentId, $adminId]);
    $branchLoanId = (int)$db->lastInsertId();
    $loanFixture->execute([$otherBookId, $otherBranchId, $otherBranchUserId, $adminId]);
    $otherLoanId = (int)$db->lastInsertId();

    $supplierFixture = $db->prepare("INSERT INTO expense_suppliers (branch_id, supplier_name, created_by) VALUES (?, ?, ?)");
    $supplierFixture->execute([$branchId, 'Assigned Branch Supplier ' . $suffix, $adminId]);
    $branchSupplierId = (int)$db->lastInsertId();
    $supplierFixture->execute([$otherBranchId, 'Other Branch Supplier ' . $suffix, $adminId]);
    $otherSupplierId = (int)$db->lastInsertId();
    $budgetMonth = date('Y-m-01');
    $budgetFixture = $db->prepare("INSERT INTO expense_monthly_budgets (branch_id, category, budget_month, budget_amount, created_by, updated_by)
        VALUES (?, 'supplies', ?, 1000.00, ?, ?)");
    $budgetFixture->execute([$branchId, $budgetMonth, $adminId, $adminId]);
    $branchBudgetId = (int)$db->lastInsertId();
    $budgetFixture->execute([$otherBranchId, $budgetMonth, $adminId, $adminId]);
    $otherBudgetId = (int)$db->lastInsertId();

    $billingMonth = date('Y-m');
    foreach ([$branchId, $otherBranchId] as $billingBranchId) {
        expect_status(api_request($base, '/api/facility-fees.php?action=generate', 'POST', [
            'service_type' => 'hostel', 'branch_id' => $billingBranchId, 'billing_month' => $billingMonth,
        ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin generates branch hostel fee invoices');
        expect_status(api_request($base, '/api/facility-fees.php?action=generate', 'POST', [
            'service_type' => 'transport', 'branch_id' => $billingBranchId, 'billing_month' => $billingMonth,
        ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin generates branch transport fee invoices');
    }
    $invoiceIds = $db->prepare('SELECT id FROM facility_fee_invoices WHERE branch_id = ? AND billing_month = ? ORDER BY id');
    $invoiceIds->execute([$branchId, $budgetMonth]);
    $branchFacilityInvoiceIds = array_map('intval', $invoiceIds->fetchAll(PDO::FETCH_COLUMN));
    $invoiceIds->execute([$otherBranchId, $budgetMonth]);
    $otherFacilityInvoiceIds = array_map('intval', $invoiceIds->fetchAll(PDO::FETCH_COLUMN));

    check(count($branchFacilityInvoiceIds) === 2 && count($otherFacilityInvoiceIds) === 2,
        'Each branch must receive exactly one hostel and one transport invoice for the month.');
    foreach ([$branchId, $otherBranchId] as $billingBranchId) {
        foreach (['hostel','transport'] as $serviceType) {
            $duplicate = expect_status(api_request($base, '/api/facility-fees.php?action=generate', 'POST', [
                'service_type' => $serviceType, 'branch_id' => $billingBranchId, 'billing_month' => $billingMonth,
            ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Repeated monthly billing request is idempotent');
            check((int)($duplicate['data']['created_count'] ?? -1) === 0, 'Repeated monthly billing must not create duplicate invoices.');
        }
    }

    $facilityInvoice = $db->prepare('SELECT id, service_type, amount FROM facility_fee_invoices WHERE branch_id = ? AND billing_month = ? AND service_type = ? LIMIT 1');
    $facilityInvoice->execute([$branchId, $budgetMonth, 'hostel']);
    $branchHostelInvoice = $facilityInvoice->fetch(PDO::FETCH_ASSOC);
    $facilityInvoice->execute([$branchId, $budgetMonth, 'transport']);
    $branchTransportInvoice = $facilityInvoice->fetch(PDO::FETCH_ASSOC);
    $facilityInvoice->execute([$otherBranchId, $budgetMonth, 'hostel']);
    $otherHostelInvoice = $facilityInvoice->fetch(PDO::FETCH_ASSOC);
    check(is_array($branchHostelInvoice) && is_array($branchTransportInvoice) && is_array($otherHostelInvoice),
        'Hostel and transport invoice fixtures must exist for both branches.');

    $hostelPartOne = expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => (int)$branchHostelInvoice['id'], 'amount' => '200.00', 'payment_method' => 'cash',
        'payment_reference' => 'E2E-HOSTEL-PART-ONE-' . $suffix,
    ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin records a partial hostel fee payment');
    check(($hostelPartOne['data']['status'] ?? '') === 'partial'
        && ($hostelPartOne['data']['outstanding_amount'] ?? '') === '800.00',
        'Partial hostel payment must leave the correct outstanding balance.');
    $hostelPartTwo = expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => (int)$branchHostelInvoice['id'], 'amount' => '800.00', 'payment_method' => 'bank_transfer',
        'payment_reference' => 'E2E-HOSTEL-PART-TWO-' . $suffix,
    ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin completes the hostel fee balance');
    check(($hostelPartTwo['data']['status'] ?? '') === 'paid'
        && ($hostelPartTwo['data']['outstanding_amount'] ?? '') === '0.00',
        'Full hostel payment must close the invoice balance.');

    expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => (int)$branchTransportInvoice['id'], 'amount' => '500.00', 'payment_method' => 'bKash',
        'payment_reference' => 'E2E-HOSTEL-PART-ONE-' . $suffix,
    ], $cookie, $login['data']['csrf_token'] ?? null), 409, 'Facility fee payment reference must be unique across invoices.');
    $transportPayment = expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => (int)$branchTransportInvoice['id'], 'amount' => '500.00', 'payment_method' => 'bKash',
        'payment_reference' => 'E2E-TRANSPORT-FULL-' . $suffix,
    ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin records full transport fee payment');

    foreach ([(int)$hostelPartOne['data']['payment_id'], (int)$hostelPartTwo['data']['payment_id'], (int)$transportPayment['data']['payment_id']] as $paymentId) {
        $journalBalance = $db->prepare('SELECT COALESCE(SUM(e.debit),0) AS debit_total, COALESCE(SUM(e.credit),0) AS credit_total
            FROM facility_fee_journal_entries e JOIN facility_fee_journal_transactions j ON j.id = e.transaction_id WHERE j.payment_id = ?');
        $journalBalance->execute([$paymentId]);
        $totals = $journalBalance->fetch(PDO::FETCH_ASSOC);
        check(round((float)$totals['debit_total'],2) === round((float)$totals['credit_total'],2)
            && (float)$totals['debit_total'] > 0,
            'Every facility fee collection journal must be balanced and non-zero.');
    }

    $branchFacilityRows = expect_status(api_request($base, '/api/facility-fees.php?action=list', 'GET', null, $branchManagerCookie), 200,
        'Branch Manager loads only its own facility invoices');
    check(count($branchFacilityRows['data'] ?? []) === 2
        && count(array_filter($branchFacilityRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Branch Manager facility invoice list must exclude every other branch.');
    expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => (int)$otherHostelInvoice['id'], 'amount' => '100.00', 'payment_method' => 'cash',
        'payment_reference' => 'CROSS-BRANCH-FEE-' . $suffix,
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch Manager cannot collect a fee for another branch invoice.');

    $branchCertificate = expect_status(api_request($base, '/api/certificates.php?action=issue', 'POST', [
        'student_user_id' => $studentId, 'course_id' => $branchOwnedCourseId,
    ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin issues certificate for assigned-branch fixture');
    $branchCertificateId = (int)$branchCertificate['data']['id'];
    $otherCertificate = expect_status(api_request($base, '/api/certificates.php?action=issue', 'POST', [
        'student_user_id' => $otherBranchUserId, 'course_id' => $otherOwnedCourseId,
    ], $cookie, $login['data']['csrf_token'] ?? null), 201, 'Admin issues certificate for other-branch fixture');
    $otherCertificateId = (int)$otherCertificate['data']['id'];

    $branchBatchRows = expect_status(api_request($base, '/api/batches.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped batches');
    check(in_array($branchBatchId, array_map(static fn(array $row): int => (int)$row['id'], $branchBatchRows['data'] ?? []), true)
        && !in_array($otherBatchId, array_map(static fn(array $row): int => (int)$row['id'], $branchBatchRows['data'] ?? []), true),
        'Branch manager batch list must exclude other branches.');
    $branchRoutineRows = expect_status(api_request($base, '/api/batches.php?action=routines', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped routines');
    check(in_array($branchRoutineId, array_map(static fn(array $row): int => (int)$row['id'], $branchRoutineRows['data'] ?? []), true)
        && !in_array($otherRoutineId, array_map(static fn(array $row): int => (int)$row['id'], $branchRoutineRows['data'] ?? []), true),
        'Branch manager routine list must exclude other branches.');
    expect_status(api_request($base, '/api/batches.php?action=deactivate&id=' . $otherBatchId, 'POST', [], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot deactivate another branch batch by ID.');
    expect_status(api_request($base, '/api/batches.php?action=routine_delete&id=' . $otherRoutineId, 'POST', [], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot delete another branch routine by ID.');

    $branchAttendanceRows = expect_status(api_request($base, '/api/attendance.php?action=list&date=' . $today, 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped attendance');
    check(count(array_filter($branchAttendanceRows['data'] ?? [], static fn(array $row): bool => (int)$row['course_id'] === $otherOwnedCourseId)) === 0,
        'Branch manager attendance list must exclude other branches.');
    expect_status(api_request($base, '/api/attendance.php?action=roster&course_id=' . $otherOwnedCourseId . '&date=' . $today, 'GET', null, $branchManagerCookie), 404,
        'Branch manager cannot read another branch attendance roster.');
    expect_status(api_request($base, '/api/attendance.php?action=save', 'POST', [
        'course_id' => $otherOwnedCourseId, 'attendance_date' => $today,
        'records' => [['user_id' => $otherBranchUserId, 'status' => 'present']],
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot write attendance to another branch course.');

    $branchEnrollmentRows = expect_status(api_request($base, '/api/enrollments.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped enrollments');
    check(count(array_filter($branchEnrollmentRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Branch manager enrollment list must exclude other branches.');
    expect_status(api_request($base, '/api/enrollments.php?action=update&id=' . $otherEnrollmentId, 'POST', ['status' => 'cancelled'], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot update another branch enrollment by ID.');

    $branchCertificateRows = expect_status(api_request($base, '/api/certificates.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped certificates');
    $branchCertificateIds = array_map(static fn(array $row): int => (int)$row['id'], $branchCertificateRows['data'] ?? []);
    check(in_array($branchCertificateId, $branchCertificateIds, true) && !in_array($otherCertificateId, $branchCertificateIds, true),
        'Branch manager certificate list must include assigned-branch and exclude other-branch certificates.');
    expect_status(api_request($base, '/api/certificates.php?action=revoke&id=' . $otherCertificateId, 'POST', ['reason' => 'branch isolation test'], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot revoke another branch certificate by ID.');

    $branchPaymentRows = expect_status(api_request($base, '/api/payments.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped payments');
    $branchPaymentIds = array_map(static fn(array $row): int => (int)$row['id'], $branchPaymentRows['data'] ?? []);
    check(in_array($branchPaymentId, $branchPaymentIds, true) && !in_array($otherPaymentId, $branchPaymentIds, true),
        'Branch manager payment list must include assigned-branch and exclude other-branch payments.');
    expect_status(api_request($base, '/api/payments.php?action=verify&id=' . $otherPaymentId, 'POST', [], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot verify another branch payment by ID.');

    $branchLiveClassRows = expect_status(api_request($base, '/api/live_classes.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped live classes');
    check(in_array($branchLiveClassId, array_map(static fn(array $row): int => (int)$row['id'], $branchLiveClassRows['data'] ?? []), true)
        && !in_array($otherLiveClassId, array_map(static fn(array $row): int => (int)$row['id'], $branchLiveClassRows['data'] ?? []), true),
        'Branch manager live class list must exclude other branches.');
    expect_status(api_request($base, '/api/live_classes.php?action=update&id=' . $otherLiveClassId, 'POST', ['status' => 'live'], $branchManagerCookie, $branchManagerCsrf), 404,
        'Branch manager cannot update another branch live class by ID.');

    $branchRoomRows = expect_status(api_request($base, '/api/hostel.php?action=rooms', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped hostel rooms');
    check(in_array($branchRoomId, array_map(static fn(array $row): int => (int)$row['id'], $branchRoomRows['data'] ?? []), true)
        && !in_array($otherRoomId, array_map(static fn(array $row): int => (int)$row['id'], $branchRoomRows['data'] ?? []), true),
        'Branch manager hostel room list must exclude other branches.');
    expect_status(api_request($base, '/api/hostel.php?action=allocate', 'POST', [
        'room_id' => $otherRoomId, 'student_user_id' => $otherBranchUserId, 'start_date' => $today,
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot allocate a student to another branch hostel room.');

    $branchRouteRows = expect_status(api_request($base, '/api/transport.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads branch-scoped transport routes');
    check(in_array($branchRouteId, array_map(static fn(array $row): int => (int)$row['id'], $branchRouteRows['data'] ?? []), true)
        && !in_array($otherRouteId, array_map(static fn(array $row): int => (int)$row['id'], $branchRouteRows['data'] ?? []), true),
        'Branch manager transport route list must exclude other branches.');
    expect_status(api_request($base, '/api/transport.php?action=assign', 'POST', [
        'route_id' => $otherRouteId, 'student_user_id' => $otherBranchUserId, 'start_date' => $today,
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot assign a student to another branch transport route.');

    $branchAllocationRows = expect_status(api_request($base, '/api/hostel.php?action=allocations', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped hostel allocations');
    check(!in_array($otherHostelAllocationId, array_map(static fn(array $row): int => (int)$row['id'], $branchAllocationRows['data'] ?? []), true),
        'Branch manager hostel allocations must exclude other branches.');
    $branchTransportAssignmentRows = expect_status(api_request($base, '/api/transport.php?action=assignments', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped transport assignments');
    check(!in_array($otherTransportAssignmentId, array_map(static fn(array $row): int => (int)$row['id'], $branchTransportAssignmentRows['data'] ?? []), true),
        'Branch manager transport assignments must exclude other branches.');

    $branchFacilityRows = expect_status(api_request($base, '/api/facility-fees.php?action=list', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped facility invoices');
    $branchFacilityIds = array_map(static fn(array $row): int => (int)$row['id'], $branchFacilityRows['data'] ?? []);
    $otherFacilityInvoiceId = $otherFacilityInvoiceIds[0] ?? 0;
    check(count($branchFacilityIds) === 2 && !in_array($otherFacilityInvoiceId, $branchFacilityIds, true)
        && count(array_filter($branchFacilityRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Branch manager facility invoices must exclude other branches.');
    expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => $otherFacilityInvoiceId, 'amount' => '1.00', 'payment_method' => 'cash',
        'payment_reference' => 'FAC-FOREIGN-' . strtoupper($suffix),
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot pay another branch facility invoice.');

    $branchSupplierRows = expect_status(api_request($base, '/api/expenses.php?action=suppliers', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped expense suppliers');
    check(in_array($branchSupplierId, array_map(static fn(array $row): int => (int)$row['id'], $branchSupplierRows['data'] ?? []), true)
        && !in_array($otherSupplierId, array_map(static fn(array $row): int => (int)$row['id'], $branchSupplierRows['data'] ?? []), true),
        'Branch manager supplier list must exclude other branches.');
    $branchBudgetRows = expect_status(api_request($base, '/api/expenses.php?action=budgets&month=' . $billingMonth, 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped expense budgets');
    check(in_array($branchBudgetId, array_map(static fn(array $row): int => (int)$row['id'], $branchBudgetRows['data'] ?? []), true)
        && !in_array($otherBudgetId, array_map(static fn(array $row): int => (int)$row['id'], $branchBudgetRows['data'] ?? []), true),
        'Branch manager budget list must exclude other branches.');
    $createdSupplier = expect_status(api_request($base, '/api/expenses.php?action=supplier_create', 'POST', [
        'branch_id' => $otherBranchId, 'supplier_name' => 'Branch Manager Scoped Supplier ' . $suffix,
    ], $branchManagerCookie, $branchManagerCsrf), 201, 'Branch manager supplier creation overrides an untrusted branch ID');
    $createdSupplierBranch = $db->prepare('SELECT branch_id FROM expense_suppliers WHERE id = ?');
    $createdSupplierBranch->execute([(int)$createdSupplier['data']['id']]);
    check((int)$createdSupplierBranch->fetchColumn() === $branchId, 'Branch manager-created supplier must belong to the assigned branch.');
    expect_status(api_request($base, '/api/expenses.php?action=supplier_archive&id=' . $otherSupplierId, 'POST', [],
        $branchManagerCookie, $branchManagerCsrf), 403, 'Branch manager cannot archive another branch supplier.');
    expect_status(api_request($base, '/api/expenses.php?action=budget_save', 'POST', [
        'branch_id' => $otherBranchId, 'category' => 'supplies', 'budget_month' => $billingMonth, 'budget_amount' => '2000.00',
    ], $branchManagerCookie, $branchManagerCsrf), 403, 'Branch manager cannot use the global budget-write endpoint.');

    $branchBookRows = expect_status(api_request($base, '/api/library.php?action=list', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped library books');
    check(in_array($branchBookId, array_map(static fn(array $row): int => (int)$row['id'], $branchBookRows['data'] ?? []), true)
        && !in_array($otherBookId, array_map(static fn(array $row): int => (int)$row['id'], $branchBookRows['data'] ?? []), true),
        'Branch manager library book list must exclude other branches.');
    $branchFineRows = expect_status(api_request($base, '/api/library.php?action=fine_list', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped library fines');
    check(in_array($branchLoanId, array_map(static fn(array $row): int => (int)$row['loan_id'], $branchFineRows['data'] ?? []), true)
        && !in_array($otherLoanId, array_map(static fn(array $row): int => (int)$row['loan_id'], $branchFineRows['data'] ?? []), true),
        'Branch manager library fine list must exclude other branches.');
    expect_status(api_request($base, '/api/library.php?action=fine_pay', 'POST', [
        'loan_id' => $otherLoanId, 'amount' => '1.00', 'payment_method' => 'cash',
        'payment_reference' => 'LIB-FOREIGN-' . strtoupper($suffix),
    ], $branchManagerCookie, $branchManagerCsrf), 404, 'Branch manager cannot collect a fine for another branch loan.');

    // Global modules without branch-scoped APIs remain explicitly denied to branch managers.
    foreach ([
        ['/api/question_bank.php?action=list', 'question-bank management'],
        ['/api/marksheets.php?action=eligible_attempts', 'marksheet issuance'],
        ['/api/cms.php?action=posts', 'global CMS management'],
        ['/api/media.php?action=list', 'global private-media management'],
        ['/api/content.php?action=list', 'global content management'],
        ['/api/commissions.php?action=rules', 'global teacher commission rules'],
        ['/api/admissions.php?action=list', 'global admissions management'],
        ['/api/storefront.php?action=ebook_orders', 'global e-book order management'],
        ['/api/storefront.php?action=product_orders', 'global product order management'],
    ] as [$endpoint, $label]) {
        expect_status(
            api_request($base, $endpoint, 'GET', null, $branchManagerCookie),
            403,
            'Branch manager is denied unscoped ' . $label
        );
    }

    $db->prepare("INSERT INTO exams (course_id, title, description, duration_minutes, pass_marks, negative_mark_per_wrong, max_attempts, status, created_by) VALUES (?, 'Foreign branch draft exam', '', 30, 5, 0, 1, 'draft', ?)")->execute([$otherOwnedCourseId, $adminId]);
    $foreignExamId = (int)$db->lastInsertId();
    $branchExamRows = expect_status(api_request($base, '/api/exams.php?action=list', 'GET', null, $branchManagerCookie), 200,
        'Branch manager receives only published exams available in its own branch');
    check(count($branchExamRows['data'] ?? []) === 0, 'Branch manager must not see another branch draft exam despite exams.manage permission.');
    expect_status(api_request($base, '/api/exams.php?action=detail&id=' . $foreignExamId, 'GET', null, $branchManagerCookie), 404,
        'Branch manager cannot read another branch draft exam by ID.');

    $branchList = expect_status(api_request($base, '/api/branches.php?action=list', 'GET', null, $branchManagerCookie), 200, 'Branch manager loads assigned branch');
    $visibleBranchIds = array_map(static fn(array $branch): int => (int)$branch['id'], $branchList['data'] ?? []);
    check($visibleBranchIds === [$branchId], 'Branch manager must see only their assigned branch.');
    expect_status(api_request($base, '/api/branches.php?action=update&id=' . $otherBranchId, 'POST', [
        'code' => 'TST-OTHER-' . strtoupper($suffix), 'name' => 'Unauthorized Branch Edit',
    ], $branchManagerCookie, $branchManagerCsrf), 403, 'Branch manager cannot edit branch administration settings');

    $branchRevenueReport = expect_status(
        api_request($base, '/api/reports.php?type=revenue&period=current_month', 'GET', null, $branchManagerCookie),
        200,
        'Branch manager can read the branch-scoped revenue report'
    );
    check(isset($branchRevenueReport['data']['rows']) && is_array($branchRevenueReport['data']['rows']),
        'Branch manager revenue report must return a rows array.');
    $branchAttendanceReport = expect_status(api_request($base, '/api/reports.php?type=attendance&period=current_month', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped attendance report');
    check(array_sum(array_map(static fn(array $row): int => (int)$row['marked_count'], $branchAttendanceReport['data']['rows'] ?? [])) === 1,
        'Branch manager attendance report must exclude other-branch attendance.');
    $branchExamReport = expect_status(api_request($base, '/api/reports.php?type=exams&period=current_month', 'GET', null, $branchManagerCookie), 200,
        'Branch manager loads branch-scoped exam report');
    check(!in_array($foreignExamId, array_map(static fn(array $row): int => (int)$row['exam_id'], $branchExamReport['data']['rows'] ?? []), true),
        'Branch manager exam report must exclude another branch exam.');

    // A support-staff role with explicitly granted finance read permissions must remain branch-scoped.
    $supportStaffEmail = "api-support-staff-{$suffix}@example.test";
    $supportStaffPassword = 'TestSupportStaff!Pass123';
    $insertBranchManager->execute(['API Integration Support Staff', $supportStaffEmail, password_hash($supportStaffPassword, PASSWORD_DEFAULT), 'support_staff', $branchId]);
    $supportStaffId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES
        ('support_staff', 'expenses.manage', 1), ('support_staff', 'facility.fees.manage', 1),
        ('support_staff', 'notifications.broadcast', 1), ('support_staff', 'question_bank.manage', 1),
        ('support_staff', 'exams.manage', 1), ('support_staff', 'marksheets.manage', 1),
        ('support_staff', 'cms.manage', 1), ('support_staff', 'content.manage', 1),
        ('support_staff', 'commissions.manage', 1), ('support_staff', 'admissions.manage', 1),
        ('support_staff', 'storefront.orders.manage', 1), ('support_staff', 'users.manage', 1),
        ('support_staff', 'courses.manage', 1), ('support_staff', 'branches.manage', 1),
        ('support_staff', 'library.fines.manage', 1), ('support_staff', 'batches.manage', 1),
        ('support_staff', 'attendance.manage', 1), ('support_staff', 'enrollments.manage', 1),
        ('support_staff', 'certificates.manage', 1), ('support_staff', 'payments.manage', 1),
        ('support_staff', 'live_classes.manage', 1), ('support_staff', 'hostel.manage', 1),
        ('support_staff', 'transport.manage', 1)
        ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)")->execute();
    $db->prepare("INSERT INTO expense_records (branch_id, category, description, amount, expense_date, requested_by) VALUES (?, 'supplies', 'Support scope fixture - assigned branch', 10.00, CURDATE(), ?), (?, 'supplies', 'Support scope fixture - other branch', 20.00, CURDATE(), ?)")->execute([$branchId, $adminId, $otherBranchId, $adminId]);
    $supportStaffCookie = null;
    $supportStaffLogin = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $supportStaffEmail, 'password' => $supportStaffPassword,
    ], $supportStaffCookie), 200, 'Support staff login for finance branch isolation test');
    $supportStaffCsrf = $supportStaffLogin['data']['csrf_token'] ?? null;
    $supportExamRows = expect_status(api_request($base, '/api/exams.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff receives only published exams available in its own branch');
    check(count($supportExamRows['data'] ?? []) === 0, 'Support staff must not see another branch draft exam despite exams.manage permission.');
    expect_status(api_request($base, '/api/exams.php?action=detail&id=' . $foreignExamId, 'GET', null, $supportStaffCookie), 404,
        'Support staff cannot read another branch draft exam by ID.');
    foreach ([
        ['/api/question_bank.php?action=list', 'question-bank management'],
        ['/api/marksheets.php?action=eligible_attempts', 'marksheet issuance'],
        ['/api/cms.php?action=posts', 'global CMS management'],
        ['/api/media.php?action=list', 'global private-media management'],
        ['/api/content.php?action=list', 'global content management'],
        ['/api/commissions.php?action=rules', 'global teacher commission rules'],
        ['/api/admissions.php?action=list', 'global admissions management'],
        ['/api/storefront.php?action=ebook_orders', 'global e-book order management'],
        ['/api/storefront.php?action=product_orders', 'global product order management'],
    ] as [$endpoint, $label]) {
        expect_status(api_request($base, $endpoint, 'GET', null, $supportStaffCookie), 403,
            'Support staff is denied unscoped ' . $label . ' even with an explicit permission row');
    }
    $supportUserRows = expect_status(api_request($base, '/api/users.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff can list users in its assigned branch');
    check(count(array_filter($supportUserRows['data'] ?? [], static fn(array $user): bool => (int)($user['branch_id'] ?? 0) !== $branchId)) === 0,
        'Support staff user list must exclude other branches.');
    $supportCourseRows = expect_status(api_request($base, '/api/courses.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff can list courses in its assigned branch');
    check(count(array_filter($supportCourseRows['data'] ?? [], static fn(array $course): bool => (int)($course['branch_id'] ?? 0) !== $branchId)) === 0,
        'Support staff course list must exclude other branches.');
    expect_status(api_request($base, '/api/courses.php?action=detail&id=' . $otherOwnedCourseId, 'GET', null, $supportStaffCookie), 404,
        'Support staff cannot read another branch course by ID');
    expect_status(api_request($base, '/api/accounts.php?action=admin_activate&id=' . $otherBranchUserId, 'POST', [],
        $supportStaffCookie, $supportStaffCsrf), 403, 'Support staff cannot use the unscoped account activation endpoint');
    expect_status(api_request($base, '/api/branches.php?action=update&id=' . $otherBranchId, 'POST', [
        'code' => 'TST-OTHER-' . strtoupper($suffix), 'name' => 'Unauthorized Support Branch Edit',
    ], $supportStaffCookie, $supportStaffCsrf), 403, 'Support staff cannot edit global branch administration settings');

    $supportBatchRows = expect_status(api_request($base, '/api/batches.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped batches');
    check(count(array_filter($supportBatchRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Support staff batch list must exclude other branches.');
    $supportRoutineRows = expect_status(api_request($base, '/api/batches.php?action=routines', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped routines');
    check(count(array_filter($supportRoutineRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Support staff routine list must exclude other branches.');
    $supportAttendanceRows = expect_status(api_request($base, '/api/attendance.php?action=list&date=' . $today, 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped attendance');
    check(count(array_filter($supportAttendanceRows['data'] ?? [], static fn(array $row): bool => (int)$row['course_id'] === $otherOwnedCourseId)) === 0,
        'Support staff attendance list must exclude other branches.');
    expect_status(api_request($base, '/api/attendance.php?action=roster&course_id=' . $otherOwnedCourseId . '&date=' . $today, 'GET', null, $supportStaffCookie), 404,
        'Support staff cannot read another branch attendance roster.');
    $supportEnrollmentRows = expect_status(api_request($base, '/api/enrollments.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped enrollments');
    check(count(array_filter($supportEnrollmentRows['data'] ?? [], static fn(array $row): bool => (int)$row['branch_id'] !== $branchId)) === 0,
        'Support staff enrollment list must exclude other branches.');
    expect_status(api_request($base, '/api/enrollments.php?action=update&id=' . $otherEnrollmentId, 'POST', ['status' => 'cancelled'],
        $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot update another branch enrollment by ID.');
    $supportCertificateRows = expect_status(api_request($base, '/api/certificates.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped certificates');
    $supportCertificateIds = array_map(static fn(array $row): int => (int)$row['id'], $supportCertificateRows['data'] ?? []);
    check(in_array($branchCertificateId, $supportCertificateIds, true) && !in_array($otherCertificateId, $supportCertificateIds, true),
        'Support staff certificate list must include assigned-branch and exclude other-branch certificates.');
    expect_status(api_request($base, '/api/certificates.php?action=revoke&id=' . $otherCertificateId, 'POST', ['reason' => 'branch isolation test'],
        $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot revoke another branch certificate by ID.');
    $supportPaymentRows = expect_status(api_request($base, '/api/payments.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped payments');
    $supportPaymentIds = array_map(static fn(array $row): int => (int)$row['id'], $supportPaymentRows['data'] ?? []);
    check(in_array($branchPaymentId, $supportPaymentIds, true) && !in_array($otherPaymentId, $supportPaymentIds, true),
        'Support staff payment list must include assigned-branch and exclude other-branch payments.');
    expect_status(api_request($base, '/api/payments.php?action=verify&id=' . $otherPaymentId, 'POST', [],
        $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot verify another branch payment by ID.');
    $supportLiveClassRows = expect_status(api_request($base, '/api/live_classes.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped live classes');
    check(!in_array($otherLiveClassId, array_map(static fn(array $row): int => (int)$row['id'], $supportLiveClassRows['data'] ?? []), true),
        'Support staff live class list must exclude other branches.');
    $supportRoomRows = expect_status(api_request($base, '/api/hostel.php?action=rooms', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped hostel rooms');
    check(!in_array($otherRoomId, array_map(static fn(array $row): int => (int)$row['id'], $supportRoomRows['data'] ?? []), true),
        'Support staff hostel room list must exclude other branches.');
    expect_status(api_request($base, '/api/hostel.php?action=allocate', 'POST', [
        'room_id' => $otherRoomId, 'student_user_id' => $otherBranchUserId, 'start_date' => $today,
    ], $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot allocate a student to another branch hostel room.');
    $supportRouteRows = expect_status(api_request($base, '/api/transport.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped transport routes');
    check(!in_array($otherRouteId, array_map(static fn(array $row): int => (int)$row['id'], $supportRouteRows['data'] ?? []), true),
        'Support staff transport route list must exclude other branches.');
    expect_status(api_request($base, '/api/transport.php?action=assign', 'POST', [
        'route_id' => $otherRouteId, 'student_user_id' => $otherBranchUserId, 'start_date' => $today,
    ], $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot assign a student to another branch transport route.');

    $supportFacilityRows = expect_status(api_request($base, '/api/facility-fees.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped facility invoices');
    $supportFacilityIds = array_map(static fn(array $row): int => (int)$row['id'], $supportFacilityRows['data'] ?? []);
    check(count($supportFacilityIds) === 2 && !in_array($otherFacilityInvoiceId, $supportFacilityIds, true),
        'Support staff facility invoice list must exclude other branches.');
    $supportSupplierRows = expect_status(api_request($base, '/api/expenses.php?action=suppliers', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped suppliers');
    check(!in_array($otherSupplierId, array_map(static fn(array $row): int => (int)$row['id'], $supportSupplierRows['data'] ?? []), true),
        'Support staff supplier list must exclude other branches.');
    $supportBudgetRows = expect_status(api_request($base, '/api/expenses.php?action=budgets&month=' . $billingMonth, 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped budgets');
    check(!in_array($otherBudgetId, array_map(static fn(array $row): int => (int)$row['id'], $supportBudgetRows['data'] ?? []), true),
        'Support staff budget list must exclude other branches.');
    expect_status(api_request($base, '/api/expenses.php?action=supplier_create', 'POST', [
        'branch_id' => $branchId, 'supplier_name' => 'Unauthorized Support Supplier ' . $suffix,
    ], $supportStaffCookie, $supportStaffCsrf), 403, 'Support staff cannot create expense suppliers without supplier-management privilege.');
    expect_status(api_request($base, '/api/expenses.php?action=budget_save', 'POST', [
        'branch_id' => $branchId, 'category' => 'supplies', 'budget_month' => $billingMonth, 'budget_amount' => '2000.00',
    ], $supportStaffCookie, $supportStaffCsrf), 403, 'Support staff cannot write global expense budgets.');
    expect_status(api_request($base, '/api/facility-fees.php?action=pay', 'POST', [
        'invoice_id' => $branchFacilityInvoiceIds[0] ?? 0, 'amount' => '1.00', 'payment_method' => 'cash',
        'payment_reference' => 'FAC-SUPPORT-' . strtoupper($suffix),
    ], $supportStaffCookie, $supportStaffCsrf), 403, 'Support staff cannot record facility fee payments.');
    $supportAllocationRows = expect_status(api_request($base, '/api/hostel.php?action=allocations', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped hostel allocations');
    check(!in_array($otherHostelAllocationId, array_map(static fn(array $row): int => (int)$row['id'], $supportAllocationRows['data'] ?? []), true),
        'Support staff hostel allocation list must exclude other branches.');
    $supportTransportAssignmentRows = expect_status(api_request($base, '/api/transport.php?action=assignments', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped transport assignments');
    check(!in_array($otherTransportAssignmentId, array_map(static fn(array $row): int => (int)$row['id'], $supportTransportAssignmentRows['data'] ?? []), true),
        'Support staff transport assignment list must exclude other branches.');
    $supportBookRows = expect_status(api_request($base, '/api/library.php?action=list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped library books');
    check(!in_array($otherBookId, array_map(static fn(array $row): int => (int)$row['id'], $supportBookRows['data'] ?? []), true),
        'Support staff library book list must exclude other branches.');

    $supportFineBranches = expect_status(api_request($base, '/api/library.php?action=fine_branches', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads only its assigned library fine branch');
    $supportFineBranchIds = array_map(static fn(array $branch): int => (int)$branch['id'], $supportFineBranches['data'] ?? []);
    check($supportFineBranchIds === [$branchId], 'Support staff library fine branch selector must not expose other branches.');
    $supportFineRows = expect_status(api_request($base, '/api/library.php?action=fine_list', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped library fines');
    $supportFineLoanIds = array_map(static fn(array $row): int => (int)$row['loan_id'], $supportFineRows['data'] ?? []);
    check(in_array($branchLoanId, $supportFineLoanIds, true) && !in_array($otherLoanId, $supportFineLoanIds, true),
        'Support staff library fine list must include assigned-branch and exclude other-branch loans.');
    expect_status(api_request($base, '/api/library.php?action=fine_pay', 'POST', [
        'loan_id' => $otherLoanId, 'amount' => '1.00', 'payment_method' => 'cash',
        'payment_reference' => 'LIB-SUPPORT-FOREIGN-' . strtoupper($suffix),
    ], $supportStaffCookie, $supportStaffCsrf), 404, 'Support staff cannot collect a fine for another branch loan.');

    $supportBroadcast = expect_status(api_request($base, '/api/notifications.php?action=broadcast', 'POST', [
        'title' => 'Support branch notice', 'message' => 'Support staff announcement is branch-limited.',
        'type' => 'info', 'target_role' => 'all',
    ], $supportStaffCookie, $supportStaffCsrf), 201, 'Support staff broadcasts only within the assigned branch');
    check((int)($supportBroadcast['data']['branch_id'] ?? 0) === $branchId && (int)($supportBroadcast['data']['recipient_count'] ?? 0) > 0,
        'Support staff broadcast response must identify its branch and recipients.');
    $supportForeignBroadcastCount = $db->prepare('SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.recipient_user_id WHERE n.sender_user_id = ? AND u.branch_id <> ?');
    $supportForeignBroadcastCount->execute([$supportStaffId, $branchId]);
    check((int)$supportForeignBroadcastCount->fetchColumn() === 0, 'Support staff broadcast must not create notifications for another branch.');
    $supportExpenseRows = expect_status(api_request($base, '/api/expenses.php?action=list', 'GET', null, $supportStaffCookie), 200, 'Support staff loads branch-scoped expense records');
    check(count($supportExpenseRows['data'] ?? []) === 1 && (int)$supportExpenseRows['data'][0]['branch_id'] === $branchId,
        'Support staff must only see expenses from their assigned branch.');
    $supportExpenseReport = expect_status(api_request($base, '/api/reports.php?type=expenses&period=current_month', 'GET', null, $supportStaffCookie), 200,
        'Support staff loads branch-scoped expense report');
    check(round(array_sum(array_map(static fn(array $row): float => (float)$row['total_amount'], $supportExpenseReport['data']['rows'] ?? [])), 2) === 10.0,
        'Support staff expense report must exclude other-branch expense totals.');
    $supportFacilityBranches = expect_status(api_request($base, '/api/facility-fees.php?action=branches', 'GET', null, $supportStaffCookie), 200, 'Support staff loads assigned facility-fee branch');
    $supportFacilityBranchIds = array_map(static fn(array $branch): int => (int)$branch['id'], $supportFacilityBranches['data'] ?? []);
    check($supportFacilityBranchIds === [$branchId], 'Support staff must only see their assigned facility-fee branch.');

    foreach (['revenue', 'admission', 'attendance', 'exams'] as $reportType) {
        $report = expect_status(
            api_request($base, '/api/reports.php?type=' . $reportType . '&period=current_month', 'GET', null, $cookie),
            200,
            'Administrator loads live ' . $reportType . ' report'
        );
        check(isset($report['data']['rows']) && is_array($report['data']['rows']), 'Report response contains a rows array for ' . $reportType);
    }
    expect_status(api_request($base, '/api/reports.php?type=unknown', 'GET', null, $cookie), 404, 'Unknown report type is rejected');
    expect_status(api_request($base, '/api/reports.php?type=revenue&period=invalid', 'GET', null, $cookie), 422, 'Invalid report period is rejected');
    $csrf = $login['data']['csrf_token'] ?? null;
    check(is_string($csrf) && strlen($csrf) >= 32, 'Login did not issue a CSRF token.');

    // Verify private course-media upload, entitlement checks and non-public storage.
    $mediaCourseSlug = 'media-it-' . $suffix;
    $db->prepare("INSERT INTO courses (branch_id, title, slug, status, created_by) VALUES (?, 'Private media integration course', ?, 'published', ?)")
        ->execute([$branchId, $mediaCourseSlug, $adminId]);
    $mediaCourseId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO content_items (title, content_type, body, status, created_by) VALUES ('Private media integration lesson', 'video', '', 'published', ?)")
        ->execute([$adminId]);
    $mediaContentId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO course_content (course_id, content_item_id, sort_order, is_preview) VALUES (?, ?, 1, 0)')
        ->execute([$mediaCourseId, $mediaContentId]);
    $db->prepare("INSERT INTO course_enrollments (user_id, course_id, branch_id, status, enrolled_by) VALUES (?, ?, ?, 'active', ?)")
        ->execute([$studentId, $mediaCourseId, $branchId, $adminId]);

    $mediaPdf = "%PDF-1.4\\n% AEC private media integration fixture\\n1 0 obj<</Type/Catalog>>endobj\\n%%EOF";
    $mediaUpload = expect_status(
        api_upload_request($base, $mediaContentId, 'private-course-media.pdf', 'application/pdf', $mediaPdf, $cookie, $csrf),
        201,
        'Administrator uploads private course media'
    );
    $mediaAssetId = (int)($mediaUpload['data']['id'] ?? 0);
    check($mediaAssetId > 0 && ($mediaUpload['data']['mime_type'] ?? '') === 'application/pdf', 'Private media upload must return its asset ID and detected MIME type.');
    expect_status(api_request($base, '/api/content.php?action=detail&id=' . $mediaContentId, 'GET', null, $branchManagerCookie), 404,
        'Branch manager cannot bypass enrollment to read private content in its branch');
    expect_status(api_request($base, '/api/content.php?action=detail&id=' . $mediaContentId, 'GET', null, $supportStaffCookie), 404,
        'Support staff cannot bypass enrollment to read private content in its branch');
    expect_raw_status(api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $branchManagerCookie), 404,
        'Branch manager cannot download private media by content ID');
    expect_raw_status(api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $supportStaffCookie), 404,
        'Support staff cannot download private media by content ID');

    expect_raw_status(
        api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId),
        401,
        'Anonymous visitor cannot download private course media'
    );

    $mediaStudentCookie = null;
    expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $studentEmail, 'password' => $studentPassword,
    ], $mediaStudentCookie), 200, 'Enrolled student login for private media download');
    $authorizedMediaDownload = api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $mediaStudentCookie);
    expect_raw_status($authorizedMediaDownload, 200, 'Enrolled student can download linked course media');
    check($authorizedMediaDownload['body'] === $mediaPdf, 'Authorized media download must return the exact uploaded bytes.');

    $unentitledEmail = "api-unentitled-{$suffix}@example.test";
    $unentitledPassword = 'TestUnentitled!Pass123';
    $insertUser->execute(['API Integration Unentitled Student', $unentitledEmail, password_hash($unentitledPassword, PASSWORD_DEFAULT), 'student']);
    $unentitledStudentId = (int)$db->lastInsertId();
    $db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$otherBranchId, $unentitledStudentId]);
    $unentitledCookie = null;
    expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $unentitledEmail, 'password' => $unentitledPassword,
    ], $unentitledCookie), 200, 'Unentitled student login for private media denial');
    expect_raw_status(
        api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $unentitledCookie),
        404,
        'Student without a course enrollment cannot download private course media'
    );

    expect_status(
        api_upload_request($base, $mediaContentId, 'not-allowed.txt', 'text/plain', 'not a supported media format', $cookie, $csrf),
        415,
        'Private media upload rejects a disallowed MIME type'
    );
    $mediaRecord = $db->prepare('SELECT storage_filename, sha256, size_bytes FROM media_assets WHERE id = ?');
    $mediaRecord->execute([$mediaAssetId]);
    $mediaMetadata = $mediaRecord->fetch();
    check(is_array($mediaMetadata), 'Private media metadata must be persisted.');
    $storedMediaPath = $privateStorageRoot . '/media/' . $mediaMetadata['storage_filename'];
    $resolvedMediaPath = realpath($storedMediaPath);
    $resolvedDocumentRoot = realpath($root);
    check($resolvedMediaPath !== false && is_file($resolvedMediaPath), 'Private media file must exist in private storage.');
    check($resolvedDocumentRoot !== false && !str_starts_with($resolvedMediaPath, rtrim($resolvedDocumentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR),
        'Private media file must be stored outside the public document root.');
    check(hash_file('sha256', $resolvedMediaPath) === $mediaMetadata['sha256']
        && filesize($resolvedMediaPath) === (int)$mediaMetadata['size_bytes'],
        'Private media file must match its stored SHA-256 and size metadata.');

    $uploadAudit = $db->prepare("SELECT action, metadata FROM audit_logs WHERE entity_type = 'media_asset' AND entity_id = ? ORDER BY id ASC");
    $uploadAudit->execute([$mediaAssetId]);
    $uploadAuditRows = $uploadAudit->fetchAll();
    check(count($uploadAuditRows) === 1 && $uploadAuditRows[0]['action'] === 'media.uploaded',
        'Private media upload must create one audit event.');
    $uploadAuditMetadata = json_decode((string)$uploadAuditRows[0]['metadata'], true);
    check(is_array($uploadAuditMetadata)
        && (int)($uploadAuditMetadata['content_item_id'] ?? 0) === $mediaContentId
        && ($uploadAuditMetadata['sha256'] ?? '') === $mediaMetadata['sha256'],
        'Private media upload audit must record the content item and file integrity metadata.');

    expect_status(
        api_request($base, '/api/media.php?action=delete', 'POST', ['id' => $mediaAssetId], $cookie, $csrf),
        200,
        'Administrator moves private course media to the 30-day trash'
    );
    clearstatcache(true, $resolvedMediaPath);
    check(is_file($resolvedMediaPath), 'Soft-deleting media must retain its private stored file during the recovery window.');
    $trashedRecord = $db->prepare('SELECT deleted_at, purge_after FROM media_assets WHERE id = ?');
    $trashedRecord->execute([$mediaAssetId]);
    $trashedMetadata = $trashedRecord->fetch();
    check(is_array($trashedMetadata) && !empty($trashedMetadata['deleted_at'])
        && strtotime((string)$trashedMetadata['purge_after']) > time(),
        'Soft-deleted media must record its deletion time and a future purge deadline.');
    expect_raw_status(
        api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $mediaStudentCookie),
        404,
        'Trashed private media is no longer downloadable'
    );
    $activeMedia = expect_status(api_request($base, '/api/media.php?action=list', 'GET', null, $cookie), 200, 'Admin lists active private media');
    check(!in_array($mediaAssetId, array_map(static fn($item) => (int)$item['id'], $activeMedia['data'] ?? []), true),
        'Trashed media must not appear in the active media list.');
    $trashMedia = expect_status(api_request($base, '/api/media.php?action=trash', 'GET', null, $cookie), 200, 'Admin lists retained private media');
    check(in_array($mediaAssetId, array_map(static fn($item) => (int)$item['id'], $trashMedia['data'] ?? []), true),
        'Trashed media must appear in the Admin recovery list.');
    expect_status(api_request($base, '/api/media.php?action=trash', 'GET', null, $branchManagerCookie), 403,
        'Branch manager cannot view the global private media trash');
    expect_status(api_request($base, '/api/media.php?action=purge', 'POST', ['id' => $mediaAssetId], $cookie, $csrf), 409,
        'Media cannot be permanently purged before its retention deadline');

    file_put_contents($storedMediaPath, 'tampered-media-content');
    expect_status(api_request($base, '/api/media.php?action=restore', 'POST', ['id' => $mediaAssetId], $cookie, $csrf), 409,
        'Restore refuses a private media file whose SHA-256 no longer matches its metadata');
    file_put_contents($storedMediaPath, $mediaPdf);
    expect_status(api_request($base, '/api/media.php?action=restore', 'POST', ['id' => $mediaAssetId], $cookie, $csrf), 200,
        'Admin restores private media within the retention window');
    expect_raw_status(api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $mediaStudentCookie), 200,
        'Restored media is downloadable again by its enrolled student');

    expect_status(api_request($base, '/api/media.php?action=delete', 'POST', ['id' => $mediaAssetId], $cookie, $csrf), 200,
        'Admin can move restored media to trash again');
    $db->prepare('UPDATE media_assets SET purge_after = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$mediaAssetId]);
    expect_status(api_request($base, '/api/media.php?action=purge', 'POST', ['id' => $mediaAssetId], $cookie, $csrf), 200,
        'Admin permanently purges private media after the retention deadline');
    clearstatcache(true, $resolvedMediaPath);
    check(!is_file($resolvedMediaPath), 'Expired media purge must remove the private stored file.');
    $purgedRecord = $db->prepare('SELECT COUNT(*) FROM media_assets WHERE id = ?');
    $purgedRecord->execute([$mediaAssetId]);
    check((int)$purgedRecord->fetchColumn() === 0, 'Expired media purge must remove its database metadata.');
    expect_raw_status(api_download_pdf($base, '/api/media.php?action=download&id=' . $mediaAssetId, $mediaStudentCookie), 404,
        'Permanently purged media cannot be downloaded');

    $mediaAudit = $db->prepare("SELECT action, metadata FROM audit_logs WHERE entity_type = 'media_asset' AND entity_id = ? ORDER BY id ASC");
    $mediaAudit->execute([$mediaAssetId]);
    $mediaAuditRows = $mediaAudit->fetchAll();
    $mediaAuditActions = array_column($mediaAuditRows, 'action');
    check($mediaAuditActions === ['media.uploaded','media.trashed','media.restored','media.trashed','media.purged'],
        'Upload, trash, restore and purge must each append an ordered audit event.');


    expect_status(api_request($base, '/api/notifications.php?action=broadcast', 'POST', [
        'title' => 'CSRF Guard Test', 'message' => 'A request without a CSRF token must be rejected.',
        'type' => 'info', 'target_role' => 'student', 'action_url' => 'courses',
    ], $cookie), 419, 'Notification broadcast rejects a missing CSRF token');

    expect_status(api_request($base, '/api/notifications.php?action=broadcast', 'POST', [
        'title' => 'Student Portal Notice', 'message' => 'Your mock exam timetable is available.',
        'type' => 'info', 'target_role' => 'student', 'action_url' => 'courses',
    ], $cookie, $csrf), 201, 'Administrator broadcasts a persistent student notification');
    $notificationStudentCookie = null;
    $notificationStudentLogin = expect_status(api_request($base, '/api/auth.php?action=login', 'POST', [
        'email' => $studentEmail, 'password' => $studentPassword,
    ], $notificationStudentCookie), 200, 'Student login for notification inbox test');
    $notificationStudentCsrf = $notificationStudentLogin['data']['csrf_token'] ?? null;
    expect_status(api_request($base, '/api/notifications.php?action=broadcast', 'POST', [
        'title' => 'Unauthorized Broadcast', 'message' => 'Students must not broadcast messages.',
        'type' => 'info', 'target_role' => 'all',
    ], $notificationStudentCookie, $notificationStudentCsrf), 403, 'Student cannot broadcast administrative notifications');
    $studentInbox = expect_status(api_request($base, '/api/notifications.php?action=list', 'GET', null, $notificationStudentCookie), 200, 'Student loads own notification inbox');
    $studentNotice = array_values(array_filter($studentInbox['data']['items'] ?? [], static fn(array $row): bool => ($row['title'] ?? '') === 'Student Portal Notice'));
    check(count($studentNotice) === 1 && ($studentNotice[0]['target_role'] ?? '') === 'student' && ($studentNotice[0]['action_url'] ?? '') === 'courses', 'Student notification was not persisted with its audience and action target.');
    $studentNoticeId = (int)$studentNotice[0]['id'];
    $unreadBeforeMarkRead = (int)($studentInbox['data']['unread_count'] ?? -1);
    check($unreadBeforeMarkRead >= 1, 'Student inbox must report the newly delivered notice as unread.');
    expect_status(api_request($base, '/api/notifications.php?action=mark_read&id=' . $studentNoticeId, 'POST', [], $notificationStudentCookie, $notificationStudentCsrf), 200, 'Student marks own notification as read');
    $studentInboxAfterRead = expect_status(api_request($base, '/api/notifications.php?action=list', 'GET', null, $notificationStudentCookie), 200, 'Student unread count refreshes');
    check((int)($studentInboxAfterRead['data']['unread_count'] ?? -1) === $unreadBeforeMarkRead - 1, 'Notification unread count did not decrease by one after mark-read.');

    expect_status(api_request($base, '/api/accounts.php?action=email_delivery', 'GET', null, $cookie), 200,
        'Admin can inspect masked email outbox delivery status');
    expect_status(api_request($base, '/api/accounts.php?action=email_delivery', 'GET', null, $notificationStudentCookie), 403,
        'Student cannot inspect email outbox delivery status');

    echo 'PASS API HTTP integration smoke suite (authentication, public CMS, reports and notifications).' . PHP_EOL;
} finally {
    if (isset($server) && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($privateStorageRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($privateStorageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($privateStorageRoot);
    }
}
