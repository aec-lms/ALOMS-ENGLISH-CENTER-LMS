<?php
declare(strict_types=1);

/**
 * Runs every SQL migration against a disposable MySQL database and verifies
 * the core schema, constraints, seed permissions, and rerun safety.
 */
$host = getenv('AEC_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('AEC_TEST_DB_PORT') ?: '3306';
$name = getenv('AEC_TEST_DB_NAME') ?: 'aec_test';
$user = getenv('AEC_TEST_DB_USER') ?: 'root';
$pass = getenv('AEC_TEST_DB_PASS') ?: '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status:int,output:string} */
function run_migration_runner(array $arguments = []): array
{
    $command = array_merge([PHP_BINARY, __DIR__ . '/migrate.php'], $arguments);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start the CLI migration runner.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($process), 'output' => $stdout . $stderr];
}

function run_migrations(PDO $pdo): void
{
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    assert_true(count($files) > 0, 'No SQL migration files were found.');

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        assert_true($sql !== false, 'Unable to read migration: ' . basename($file));

        // Remove SQL comments before splitting statements. Migrations in this
        // repository use standalone -- comments and block comments.
        $sql = preg_replace('~/\\*.*?\\*/~s', '', $sql) ?? $sql;
        $sql = preg_replace('/^\\s*(?:--|#).*$/m', '', $sql) ?? $sql;

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        echo 'PASS migration: ' . basename($file) . PHP_EOL;
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
}

$firstRun = run_migration_runner();
assert_true($firstRun['status'] === 0, 'CLI migration runner failed: ' . $firstRun['output']);
assert_true(str_contains($firstRun['output'], 'SUCCESS 001_core_schema.sql'), 'CLI migration runner did not apply the first migration.');
$secondRun = run_migration_runner();
assert_true($secondRun['status'] === 0 && str_contains($secondRun['output'], 'already up to date'), 'CLI migration runner was not idempotent: ' . $secondRun['output']);
$dryRun = run_migration_runner(['--dry-run']);
assert_true($dryRun['status'] === 0 && str_contains($dryRun['output'], '0 migration(s) would be applied'), 'CLI migration dry-run did not report an up-to-date database.');

$firstMigrationPath = (glob(__DIR__ . '/../database/migrations/*.sql') ?: [])[0] ?? null;
assert_true(is_string($firstMigrationPath), 'Could not locate a migration for checksum drift testing.');
$firstMigrationName = basename($firstMigrationPath);
$firstMigrationChecksum = hash_file('sha256', $firstMigrationPath);
assert_true(is_string($firstMigrationChecksum), 'Could not calculate migration checksum for drift test.');
$pdo->prepare('UPDATE aec_schema_migrations SET checksum = ? WHERE migration = ?')->execute([str_repeat('0', 64), $firstMigrationName]);
$driftRun = run_migration_runner(['--status']);
assert_true($driftRun['status'] !== 0 && str_contains($driftRun['output'], 'Checksum mismatch'), 'CLI migration runner did not reject an edited applied migration.');
$pdo->prepare('UPDATE aec_schema_migrations SET checksum = ? WHERE migration = ?')->execute([$firstMigrationChecksum, $firstMigrationName]);
echo "PASS CLI migration runner applies, skips, dry-runs and rejects checksum drift" . PHP_EOL;

// Re-run SQL directly as an additional idempotency and seed-override check.
run_migrations($pdo);

$requiredTables = [
    'aec_schema_migrations', 'users', 'role_permissions', 'login_attempts', 'course_categories',
    'courses', 'content_items', 'course_content', 'audit_logs',
    'course_enrollments', 'course_payments', 'accounting_accounts',
    'ledger_transactions', 'ledger_entries', 'payment_refunds', 'course_lesson_progress', 'exams', 'exam_questions', 'exam_attempts', 'question_bank_items', 'exam_question_bank_sources', 'attendance_records', 'site_settings', 'cms_posts', 'cms_testimonials', 'admission_applications', 'certificates', 'account_action_tokens', 'account_action_attempts', 'branches', 'notifications', 'ebook_orders', 'product_orders', 'ebook_files', 'media_assets', 'marksheets', 'course_commission_rules', 'commission_payout_requests', 'batches', 'batch_students', 'class_routine_slots', 'library_books', 'library_book_loans', 'transport_routes', 'transport_assignments', 'hostel_rooms', 'hostel_allocations', 'expense_records', 'expense_journal_transactions', 'expense_journal_entries', 'library_fine_payments', 'library_fine_journal_transactions', 'library_fine_journal_entries', 'facility_fee_invoices', 'facility_fee_payments', 'facility_fee_journal_transactions', 'facility_fee_journal_entries', 'commission_payout_journal_transactions', 'commission_payout_journal_entries', 'expense_suppliers', 'expense_monthly_budgets', 'expense_record_suppliers', 'expense_recurring_templates', 'expense_recurring_runs', 'saas_plans', 'saas_tenants', 'saas_tenant_domains', 'saas_subscriptions',
    'notice_board_items', 'notice_board_reads', 'promotion_campaigns', 'promotion_campaign_events', 'phone_verification_tokens', 'email_outbox',
];

foreach ($requiredTables as $table) {
    assert_true(table_exists($pdo, $table), "Required table is missing: {$table}");
}
$sessionVersionColumn = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$sessionVersionColumn->execute(['users', 'session_version']);
assert_true((int)$sessionVersionColumn->fetchColumn() === 1, 'User session_version column is missing.');
$sessionVersionColumn->execute(['users', 'phone_verified_at']);
assert_true((int)$sessionVersionColumn->fetchColumn() === 1, 'User phone_verified_at column is missing.');
foreach (['deleted_at','deleted_by','purge_after'] as $mediaColumn) {
    $sessionVersionColumn->execute(['media_assets', $mediaColumn]);
    assert_true((int)$sessionVersionColumn->fetchColumn() === 1, "Media retention column is missing: {$mediaColumn}");
}
$mediaRetentionIndex = $pdo->query("SHOW INDEX FROM media_assets WHERE Key_name = 'idx_media_retention'")->fetchAll();
assert_true(count($mediaRetentionIndex) >= 2, 'Media retention index must cover deleted_at and purge_after.');
echo 'PASS user session revocation column' . PHP_EOL;

$branchColumns = [
    ['users', 'branch_id'],
    ['course_categories', 'branch_id'],
    ['courses', 'branch_id'],
    ['course_enrollments', 'branch_id'],
    ['course_payments', 'branch_id'],
    ['attendance_records', 'branch_id'],
    ['certificates', 'branch_id'],
    ['live_classes', 'branch_id'],
];
foreach ($branchColumns as [$table, $column]) {
    $sessionVersionColumn->execute([$table, $column]);
    assert_true((int)$sessionVersionColumn->fetchColumn() === 1, "Branch ownership column is missing: {$table}.{$column}");
}
echo 'PASS branch ownership columns and registry' . PHP_EOL;

$noticeIndexes = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
foreach ([['notice_board_items','idx_notice_visibility'],['notice_board_items','idx_notice_audience'],['promotion_campaigns','idx_promotion_live'],['phone_verification_tokens','uq_phone_verification_hash']] as [$table, $index]) {
    $noticeIndexes->execute([$table, $index]);
    assert_true((int)$noticeIndexes->fetchColumn() > 0, "Marketing/notice index is missing: {$table}.{$index}");
}
echo 'PASS notice, promotion and phone verification indexes' . PHP_EOL;
$saasRateLimitColumns = ['challenge_window_started_at','challenge_request_count','verification_window_started_at','verification_attempt_count'];
foreach ($saasRateLimitColumns as $column) {
    $sessionVersionColumn->execute(['saas_tenant_domains', $column]);
    assert_true((int)$sessionVersionColumn->fetchColumn() === 1, "SaaS domain rate-limit column is missing: {$column}");
}
echo 'PASS SaaS domain verification rate-limit schema' . PHP_EOL;
$communicationForeignKeys = [
    ['notice_board_items', 'fk_notice_created_by'],
    ['notice_board_items', 'fk_notice_branch'],
    ['notice_board_reads', 'fk_notice_read_item'],
    ['notice_board_reads', 'fk_notice_read_user'],
    ['promotion_campaigns', 'fk_promotion_created_by'],
    ['phone_verification_tokens', 'fk_phone_verification_user'],
];
$communicationForeignKeyCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?');
foreach ($communicationForeignKeys as [$table, $constraint]) {
    $communicationForeignKeyCheck->execute([$table, $constraint, 'FOREIGN KEY']);
    assert_true((int)$communicationForeignKeyCheck->fetchColumn() === 1, "Communication feature foreign key is missing: {$table}.{$constraint}");
}
echo 'PASS notice, promotion and phone verification foreign keys' . PHP_EOL;
$aiTables = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
foreach (['ai_action_proposals','ai_action_backups'] as $table) {
    $aiTables->execute([$table]);
    assert_true((int)$aiTables->fetchColumn() === 1, "AI action approval table is missing: {$table}");
}
$aiBackupIndex = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
$aiBackupIndex->execute(['ai_action_backups','uq_ai_action_backup_proposal']);
assert_true((int)$aiBackupIndex->fetchColumn() === 1, 'AI action backup proposal uniqueness is missing.');
$aiForeignKeys = $pdo->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?');
foreach ([['ai_action_proposals','fk_ai_proposal_creator'],['ai_action_proposals','fk_ai_proposal_approver'],['ai_action_backups','fk_ai_backup_proposal'],['ai_action_backups','fk_ai_backup_rollback_user']] as [$table,$constraint]) {
    $aiForeignKeys->execute([$table,$constraint,'FOREIGN KEY']);
    assert_true((int)$aiForeignKeys->fetchColumn() === 1, "AI action foreign key is missing: {$table}.{$constraint}");
}
$emailOutboxIndex = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
$emailOutboxIndex->execute(['email_outbox','uq_email_outbox_idempotency']);
assert_true((int)$emailOutboxIndex->fetchColumn() === 1, 'Email outbox idempotency index is missing.');
foreach (['body_ciphertext','body_nonce','body_tag','status','attempts','next_attempt_at','last_error_code'] as $column) {
    $sessionVersionColumn->execute(['email_outbox', $column]);
    assert_true((int)$sessionVersionColumn->fetchColumn() === 1, "Email outbox column is missing: {$column}");
}
echo 'PASS encrypted email outbox schema' . PHP_EOL;

echo 'PASS AI action proposal and backup schema' . PHP_EOL;

$branchForeignKeys = [
    ['users', 'fk_users_branch'],
    ['course_categories', 'fk_categories_branch'],
    ['courses', 'fk_courses_branch'],
    ['course_enrollments', 'fk_enrollments_branch'],
    ['course_payments', 'fk_course_payments_branch'],
    ['attendance_records', 'fk_attendance_branch'],
    ['certificates', 'fk_certificates_branch'],
    ['live_classes', 'fk_live_classes_branch'],
];
$foreignKeyCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?');
foreach ($branchForeignKeys as [$table, $constraint]) {
    $foreignKeyCheck->execute([$table, $constraint, 'FOREIGN KEY']);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Branch ownership foreign key is missing: {$table}.{$constraint}");
}
echo 'PASS branch ownership foreign keys' . PHP_EOL;

$notificationForeignKeys = [
    ['notifications', 'fk_notification_recipient'],
    ['notifications', 'fk_notification_sender'],
];
foreach ($notificationForeignKeys as [$table, $constraint]) {
    $foreignKeyCheck->execute([$table, $constraint, 'FOREIGN KEY']);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Notification foreign key is missing: {$table}.{$constraint}");
}

$refundForeignKeys = [
    ['payment_refunds', 'fk_payment_refund_payment'],
    ['payment_refunds', 'fk_payment_refund_processor'],
    ['ledger_transactions', 'fk_ledger_refund'],
];
foreach ($refundForeignKeys as [$table, $constraint]) {
    $foreignKeyCheck->execute([$table, $constraint, 'FOREIGN KEY']);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Refund foreign key is missing: {$table}.{$constraint}");
}
$checkConstraints = [
    ['payment_refunds', 'chk_payment_refund_positive'],
    ['ledger_transactions', 'chk_ledger_transaction_source'],
    ['ledger_entries', 'chk_ledger_entry_debit_credit'],
    ['promotion_campaigns', 'chk_promotion_dates'],
];
foreach ($checkConstraints as [$table, $constraint]) {
    $foreignKeyCheck->execute([$table, $constraint, 'CHECK']);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Financial check constraint is missing: {$table}.{$constraint}");
}
$paymentStatusColumn = $pdo->prepare('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$paymentStatusColumn->execute(['course_payments', 'status']);
assert_true(str_contains((string)$paymentStatusColumn->fetchColumn(), 'refunded'), 'Payment status enum does not include refunded.');
$ledgerPaymentNullable = $pdo->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$ledgerPaymentNullable->execute(['ledger_transactions', 'payment_id']);
assert_true($ledgerPaymentNullable->fetchColumn() === 'YES', 'Ledger payment_id must allow refund-only journal entries.');
echo 'PASS refund schema, ledger source constraints and payment status' . PHP_EOL;

$storefrontConstraints = [
    ['ebook_orders', 'uq_ebook_orders_trx_id', 'UNIQUE'],
    ['ebook_orders', 'fk_ebook_order_user', 'FOREIGN KEY'],
    ['ebook_orders', 'fk_ebook_order_verifier', 'FOREIGN KEY'],
    ['product_orders', 'fk_product_order_user', 'FOREIGN KEY'],
    ['product_orders', 'fk_product_order_reviewer', 'FOREIGN KEY'],
    ['ebook_orders', 'chk_ebook_order_amount_positive', 'CHECK'],
    ['product_orders', 'chk_product_order_quantity_positive', 'CHECK'],
    ['product_orders', 'chk_product_order_amount_positive', 'CHECK'],
    ['ebook_files', 'uq_ebook_files_ebook_id', 'UNIQUE'],
    ['ebook_files', 'uq_ebook_files_storage_filename', 'UNIQUE'],
    ['ebook_files', 'fk_ebook_file_uploader', 'FOREIGN KEY'],
    ['ebook_files', 'chk_ebook_file_size_positive', 'CHECK'],
    ['media_assets', 'uq_media_storage_filename', 'UNIQUE'],
    ['media_assets', 'fk_media_content', 'FOREIGN KEY'],
    ['media_assets', 'fk_media_uploader', 'FOREIGN KEY'],
    ['media_assets', 'fk_media_deleted_by', 'FOREIGN KEY'],
    ['media_assets', 'chk_media_size', 'CHECK'],
];
foreach ($storefrontConstraints as [$table, $constraint, $type]) {
    $foreignKeyCheck->execute([$table, $constraint, $type]);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Storefront integrity constraint is missing: {$table}.{$constraint}");
}
$storefrontPermission = $pdo->prepare("SELECT allowed FROM role_permissions WHERE role = 'admin' AND permission_key = 'storefront.orders.manage'");
$storefrontPermission->execute();
assert_true((int)$storefrontPermission->fetchColumn() === 1, 'Admin storefront order permission was not seeded.');
$marksheetConstraints = [
    ['marksheets', 'uq_marksheet_number', 'UNIQUE'],
    ['marksheets', 'uq_marksheet_attempt', 'UNIQUE'],
    ['marksheets', 'fk_marksheet_attempt', 'FOREIGN KEY'],
    ['marksheets', 'fk_marksheet_exam', 'FOREIGN KEY'],
    ['marksheets', 'fk_marksheet_student', 'FOREIGN KEY'],
    ['marksheets', 'fk_marksheet_course', 'FOREIGN KEY'],
    ['marksheets', 'fk_marksheet_issuer', 'FOREIGN KEY'],
    ['marksheets', 'chk_marksheet_total_positive', 'CHECK'],
    ['marksheets', 'chk_marksheet_score_nonnegative', 'CHECK'],
    ['marksheets', 'chk_marksheet_percentage_range', 'CHECK'],
];
foreach ($marksheetConstraints as [$table, $constraint, $type]) {
    $foreignKeyCheck->execute([$table, $constraint, $type]);
    assert_true((int)$foreignKeyCheck->fetchColumn() === 1, "Marksheet integrity constraint is missing: {$table}.{$constraint}");
}
$marksheetPermission = $pdo->prepare("SELECT allowed FROM role_permissions WHERE role = 'admin' AND permission_key = 'marksheets.manage'");
$marksheetPermission->execute();
assert_true((int)$marksheetPermission->fetchColumn() === 1, 'Admin marksheet management permission was not seeded.');
$marksheetPermission->execute();
echo 'PASS persistent marksheet schema, attempt uniqueness, foreign keys and permission' . PHP_EOL;
echo 'PASS storefront order tables, constraints and admin permission' . PHP_EOL;

echo 'PASS required tables: ' . count($requiredTables) . PHP_EOL;
$saasPlanCount = (int)$pdo->query('SELECT COUNT(*) FROM saas_plans WHERE is_active = 1')->fetchColumn();
assert_true($saasPlanCount >= 5, 'SaaS plan catalog seed is incomplete.');
$saasPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
$saasPermission->execute(['admin', 'saas.tenants.manage']);
assert_true((int)$saasPermission->fetchColumn() === 1, 'Unified Admin SaaS tenant permission is missing.');
$saasPermission->execute(['super_admin', 'saas.tenants.manage']);
assert_true((int)$saasPermission->fetchColumn() === 0, 'Legacy super_admin role must not retain SaaS tenant management permission.');
echo 'PASS SaaS tenant registry, plan catalog and least-privilege permission seeds' . PHP_EOL;

$expectedPermissions = [
    'courses.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'content.manage' => ['admin' => 1, 'branch_manager' => 0, 'student' => 0],
    'users.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'enrollments.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'payments.manage' => ['admin' => 1, 'branch_manager' => 1, 'accountant' => 0],
    'attendance.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'live_classes.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'notifications.broadcast' => ['admin' => 1, 'branch_manager' => 0, 'student' => 0],
    'admissions.manage' => ['admin' => 1, 'branch_manager' => 0, 'student' => 0],
    'certificates.manage' => ['admin' => 1, 'branch_manager' => 1, 'student' => 0],
    'branches.manage' => ['admin' => 1, 'branch_manager' => 0, 'student' => 0],
];

$permissionQuery = $pdo->prepare(
    'SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ? LIMIT 1'
);
foreach ($expectedPermissions as $permission => $roles) {
    foreach ($roles as $role => $allowed) {
        $permissionQuery->execute([$role, $permission]);
        $actual = $permissionQuery->fetchColumn();
        assert_true($actual !== false && (int) $actual === $allowed, "Unexpected permission {$role}:{$permission}");
    }
}
echo 'PASS least-privilege permission seeds' . PHP_EOL;

// Verify INSERT IGNORE migrations preserve an administrator's explicit override.
$pdo->exec("UPDATE role_permissions SET allowed = 0 WHERE role = 'admin' AND permission_key = 'courses.manage'");
run_migrations($pdo);
$permissionQuery->execute(['admin', 'courses.manage']);
assert_true((int) $permissionQuery->fetchColumn() === 0, 'Migration rerun overwrote a customized permission.');
$permissionQuery->execute(['branch_manager', 'courses.manage']);
assert_true((int) $permissionQuery->fetchColumn() === 1, 'Migration rerun did not retain branch-scoped course management.');
$permissionQuery->execute(['branch_manager', 'content.manage']);
assert_true((int) $permissionQuery->fetchColumn() === 0, 'Migration rerun re-enabled unscoped branch-manager content access.');
$permissionQuery->execute(['branch_manager', 'branches.manage']);
assert_true((int) $permissionQuery->fetchColumn() === 0, 'Branch managers must not administer the global branch registry.');
echo 'PASS migration rerun preserves permission overrides and branch-scoped manager permissions' . PHP_EOL;

$examPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
$examPermission->execute(['admin', 'exams.manage']);
assert_true((int)$examPermission->fetchColumn() === 1, 'Admin exam management permission was not seeded.');
$examPermission->execute(['branch_manager', 'exams.manage']);
assert_true((int)$examPermission->fetchColumn() === 0, 'Branch manager must not receive unscoped exam management permission.');
$examPermission->execute(['admin', 'question_bank.manage']);
assert_true((int)$examPermission->fetchColumn() === 1, 'Admin question bank permission was not seeded.');
$examPermission->execute(['branch_manager', 'question_bank.manage']);
assert_true((int)$examPermission->fetchColumn() === 0, 'Branch manager must not receive unscoped question bank permission.');
$examPermission->execute(['admin', 'attendance.manage']);
assert_true((int)$examPermission->fetchColumn() === 1, 'Admin attendance permission was not seeded.');
$examPermission->execute(['branch_manager', 'attendance.manage']);
assert_true((int)$examPermission->fetchColumn() === 1, 'Branch manager must receive attendance permission only after branch scoping is installed.');
$examPermission->execute(['admin', 'cms.manage']);
assert_true((int)$examPermission->fetchColumn() === 1, 'Admin CMS permission was not seeded.');
$examPermission->execute(['branch_manager', 'cms.manage']);
assert_true((int)$examPermission->fetchColumn() === 0, 'Branch manager must not receive unscoped CMS permission.');
echo 'PASS exam, question bank, attendance and CMS permission seeds' . PHP_EOL;

// Verify important unique constraints exist in the installed schema.
$uniqueChecks = [
    ['course_content', 'uq_course_content'],
    ['course_enrollments', 'uq_enrollment_user_course'],
    ['course_payments', 'uq_course_payment_method_trx'],
    ['ledger_transactions', 'payment_id'],
    ['course_lesson_progress', 'uq_course_lesson_progress_user_course_content'],
    ['exam_questions', 'uq_exam_question_order'],
    ['exam_question_bank_sources', 'uq_exam_question_bank_exam_item'],
    ['exam_question_bank_sources', 'uq_exam_question_bank_exam_question'],
    ['attendance_records', 'uq_attendance_course_user_date'],
    ['cms_posts', 'uq_cms_posts_slug'],
    ['admission_applications', 'uq_admission_reference_code'],
    ['account_action_tokens', 'uq_account_action_token_hash'],
    ['course_commission_rules', 'uq_course_active_commission_rule'],
    ['commission_payout_requests', 'uq_commission_payout_reference'],
    ['batches', 'uq_batches_branch_code'],
    ['batch_students', 'uq_batch_student'],
];
$indexQuery = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0'
);
foreach ($uniqueChecks as [$table, $index]) {
    $indexQuery->execute([$table, $index]);
    assert_true((int) $indexQuery->fetchColumn() > 0, "Missing unique constraint {$table}.{$index}");
}
echo 'PASS critical unique constraints' . PHP_EOL;
$commissionPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
$commissionPermission->execute(['admin', 'commissions.manage']);
assert_true((int)$commissionPermission->fetchColumn() === 1, 'Admin commission management permission was not seeded.');
$commissionPermission->execute(['accountant', 'commissions.manage']);
assert_true((int)$commissionPermission->fetchColumn() === 1, 'Accountant commission management permission was not seeded.');
$commissionPermission->execute(['teacher', 'commissions.manage']);
assert_true((int)$commissionPermission->fetchColumn() === 0, 'Teachers must not receive commission rule/payout administration permission.');
$commissionPermission->execute(['branch_manager', 'commissions.manage']);
assert_true((int)$commissionPermission->fetchColumn() === 0, 'Branch managers must not receive global commission management permission.');
echo 'PASS teacher commission permissions' . PHP_EOL;
$batchPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
$batchPermission->execute(['admin', 'batches.manage']);
assert_true((int)$batchPermission->fetchColumn() === 1, 'Admin batch management permission was not seeded.');
$batchPermission->execute(['branch_manager', 'batches.manage']);
assert_true((int)$batchPermission->fetchColumn() === 1, 'Branch manager must receive branch-scoped batch management permission.');
$batchPermission->execute(['teacher', 'batches.manage']);
assert_true((int)$batchPermission->fetchColumn() === 0, 'Teachers must not receive batch administration permission.');
echo 'PASS batch and routine permissions' . PHP_EOL;
$libraryPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
$libraryPermission->execute(['admin', 'library.manage']);
assert_true((int)$libraryPermission->fetchColumn() === 1, 'Admin library management permission was not seeded.');
$libraryPermission->execute(['branch_manager', 'library.manage']);
assert_true((int)$libraryPermission->fetchColumn() === 1, 'Branch manager must receive branch-scoped library management permission.');
$libraryPermission->execute(['support_staff', 'library.manage']);
assert_true((int)$libraryPermission->fetchColumn() === 1, 'Support staff must receive branch-scoped library permission.');
$libraryPermission->execute(['teacher', 'library.manage']);
assert_true((int)$libraryPermission->fetchColumn() === 0, 'Teachers must not receive library administration permission.');
echo 'PASS library management permissions' . PHP_EOL;
$campusPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
foreach (['transport.manage','hostel.manage'] as $permission) {
    foreach (['admin','branch_manager','support_staff'] as $role) {
        $campusPermission->execute([$role,$permission]);
        assert_true((int)$campusPermission->fetchColumn() === 1, "{$role} must receive {$permission} permission.");
    }
    $campusPermission->execute(['teacher',$permission]);
    assert_true((int)$campusPermission->fetchColumn() === 0, "Teachers must not receive {$permission} permission.");
}
echo 'PASS hostel and transport permissions' . PHP_EOL;
$expensePermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
foreach (['admin','accountant','branch_manager'] as $role) {
    $expensePermission->execute([$role,'expenses.manage']);
    assert_true((int)$expensePermission->fetchColumn() === 1, "{$role} must receive expense workflow permission.");
}
$expensePermission->execute(['teacher','expenses.manage']);
assert_true((int)$expensePermission->fetchColumn() === 0, 'Teachers must not receive expense workflow permission.');
echo 'PASS expense accounting permissions' . PHP_EOL;
$finePermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
foreach (['admin','accountant','branch_manager'] as $role) {
    $finePermission->execute([$role,'library.fines.manage']);
    assert_true((int)$finePermission->fetchColumn() === 1, "{$role} must receive library fine collection permission.");
}
foreach (['support_staff','teacher','student'] as $role) {
    $finePermission->execute([$role,'library.fines.manage']);
    assert_true((int)$finePermission->fetchColumn() === 0, "{$role} must not receive library fine collection permission.");
}
$fineAccount = $pdo->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1');
$fineAccount->execute(['INCOME_LIBRARY_FINES']);
assert_true((bool)$fineAccount->fetchColumn(), 'Library fine income account is missing.');
echo 'PASS library fine collection schema and permissions' . PHP_EOL;
$facilityPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
foreach (['admin','accountant','branch_manager'] as $role) {
    $facilityPermission->execute([$role,'facility.fees.manage']);
    assert_true((int)$facilityPermission->fetchColumn() === 1, "{$role} must receive facility fee billing permission.");
}
foreach (['support_staff','teacher','student'] as $role) {
    $facilityPermission->execute([$role,'facility.fees.manage']);
    assert_true((int)$facilityPermission->fetchColumn() === 0, "{$role} must not receive facility fee billing permission.");
}
$facilityAccount = $pdo->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1');
foreach (['INCOME_HOSTEL_FEES','INCOME_TRANSPORT_FEES'] as $accountCode) {
    $facilityAccount->execute([$accountCode]);
    assert_true((bool)$facilityAccount->fetchColumn(), "Missing facility fee income account: {$accountCode}");
}
echo 'PASS facility fee billing schema and permissions' . PHP_EOL;
$accountingReportPermission = $pdo->prepare('SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ?');
foreach (['admin','accountant','branch_manager'] as $role) {
    $accountingReportPermission->execute([$role,'accounting.reports.view']);
    assert_true((int)$accountingReportPermission->fetchColumn() === 1, "{$role} must receive consolidated accounting report permission.");
}
foreach (['support_staff','teacher','student','guardian'] as $role) {
    $accountingReportPermission->execute([$role,'accounting.reports.view']);
    assert_true((int)$accountingReportPermission->fetchColumn() === 0, "{$role} must not receive consolidated accounting report permission.");
}
echo 'PASS consolidated accounting report permissions' . PHP_EOL;
$promotionDateCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'promotion_campaigns' AND constraint_name = 'chk_promotion_dates' AND constraint_type = 'CHECK'");
$promotionDateCheck->execute();
assert_true((int)$promotionDateCheck->fetchColumn() === 1, 'Promotion campaign end-date constraint is missing.');
$noticeIndexes = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notice_board_items' AND index_name = 'idx_notice_visibility'");
$noticeIndexes->execute();
assert_true((int)$noticeIndexes->fetchColumn() >= 1, 'Notice visibility index is missing.');
$phoneTokenUnique = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'phone_verification_tokens' AND index_name = 'uq_phone_verification_hash' AND non_unique = 0");
$phoneTokenUnique->execute();
assert_true((int)$phoneTokenUnique->fetchColumn() >= 1, 'Phone verification token hash must be unique.');
echo 'PASS notice board, promotion campaigns and phone verification schema' . PHP_EOL;
$commissionExpense = $pdo->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND account_type = ? AND is_active = 1');
$commissionExpense->execute(['EXPENSE_TEACHER_COMMISSION','expense']);
assert_true((bool)$commissionExpense->fetchColumn(), 'Teacher commission expense account is missing.');
echo 'PASS teacher commission accounting schema and account' . PHP_EOL;
assert_true(table_exists($pdo, 'expense_record_suppliers'), 'Expense-to-supplier relation table is missing.');
$budgetIndexes = $pdo->query('SHOW INDEX FROM expense_monthly_budgets')->fetchAll();
$budgetUniqueExists = false;
foreach ($budgetIndexes as $index) {
    if ($index['Key_name'] === 'uq_expense_budget_branch_category_month' && (int)$index['Non_unique'] === 0) {
        $budgetUniqueExists = true;
        break;
    }
}
assert_true($budgetUniqueExists, 'Monthly budget uniqueness constraint is missing.');
$supplierIndexes = $pdo->query('SHOW INDEX FROM expense_suppliers')->fetchAll();
$supplierUniqueExists = false;
foreach ($supplierIndexes as $index) {
    if ($index['Key_name'] === 'uq_expense_supplier_branch_name' && (int)$index['Non_unique'] === 0) {
        $supplierUniqueExists = true;
        break;
    }
}
assert_true($supplierUniqueExists, 'Branch supplier uniqueness constraint is missing.');
echo 'PASS monthly budget and supplier schema' . PHP_EOL;
$recurringIndexes = $pdo->query('SHOW INDEX FROM expense_recurring_runs')->fetchAll();
$recurringUniqueExists = false;
foreach ($recurringIndexes as $index) {
    if ($index['Key_name'] === 'uq_expense_recurring_template_month' && (int)$index['Non_unique'] === 0) {
        $recurringUniqueExists = true;
        break;
    }
}
assert_true($recurringUniqueExists, 'Recurring expense monthly idempotency constraint is missing.');
echo 'PASS recurring expense templates and run history schema' . PHP_EOL;










echo 'All MySQL migration integration checks passed.' . PHP_EOL;
