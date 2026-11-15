<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'summary');

function aec_commission_admin(array $actor): bool {
    return in_array((string)$actor['role'], ['admin', 'accountant'], true);
}
function aec_commission_audit(PDO $db, array $actor, string $event, string $entity, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, $entity, $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function aec_commission_money(mixed $value): string {
    $raw = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $raw) || (float)$raw <= 0) aec_fail('Enter a valid positive amount with up to two decimal places.', 422);
    return number_format((float)$raw, 2, '.', '');
}
function aec_commission_balance(PDO $db, int $teacherId, ?int $excludePayoutId = null): array {
    $stmt = $db->prepare("SELECT
        COALESCE(SUM(ROUND(GREATEST(p.amount - COALESCE(r.refunded_amount,0), 0) * cr.commission_rate / 100, 2)),0) AS gross_commission
      FROM course_commission_rules cr
      JOIN course_payments p ON p.course_id = cr.course_id AND p.status = 'verified'
      LEFT JOIN (SELECT payment_id, SUM(amount) AS refunded_amount FROM payment_refunds WHERE status = 'completed' GROUP BY payment_id) r ON r.payment_id = p.id
      WHERE cr.teacher_user_id = ? AND p.reviewed_at >= cr.created_at AND (cr.closed_at IS NULL OR p.reviewed_at < cr.closed_at)");
    $stmt->execute([$teacherId]);
    $gross = (float)$stmt->fetchColumn();
    $paidStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM commission_payout_requests WHERE teacher_user_id = ? AND status = 'completed'");
    $paidStmt->execute([$teacherId]);
    $paid = (float)$paidStmt->fetchColumn();
    $reservedSql = "SELECT COALESCE(SUM(amount),0) FROM commission_payout_requests WHERE teacher_user_id = ? AND status IN ('pending','approved')";
    $reservedParams = [$teacherId];
    if ($excludePayoutId !== null) {
        $reservedSql .= ' AND id <> ?';
        $reservedParams[] = $excludePayoutId;
    }
    $reservedStmt = $db->prepare($reservedSql);
    $reservedStmt->execute($reservedParams);
    $reserved = (float)$reservedStmt->fetchColumn();
    return ['gross_commission' => round($gross, 2), 'paid_commission' => round($paid, 2),
      'reserved_payout' => round($reserved, 2), 'available_balance' => round(max(0, $gross - $paid - $reserved), 2)];
}

if ($action === 'summary') {
    aec_method(['GET']);
    $actor = aec_current_user();
    if (!aec_commission_admin($actor) && !in_array($actor['role'], ['teacher','assistant_teacher'], true)) aec_fail('You cannot view commission data.', 403);
    $teacherId = (int)($actor['id']);
    if (aec_commission_admin($actor) && isset($_GET['teacher_id'])) {
        $requested = filter_var($_GET['teacher_id'], FILTER_VALIDATE_INT);
        if (!$requested || $requested < 1) aec_fail('Invalid teacher id.', 422);
        $teacherId = (int)$requested;
    }
    $balance = aec_commission_balance($db, $teacherId);
    $stmt = $db->prepare("SELECT cr.id, cr.course_id, c.title AS course_title, cr.teacher_user_id,
        u.name AS teacher_name, cr.commission_rate, cr.is_active, cr.created_at, cr.closed_at,
        COUNT(DISTINCT p.id) AS verified_payment_count,
        COALESCE(SUM(GREATEST(p.amount - COALESCE(r.refunded_amount,0),0)),0) AS net_course_revenue,
        COALESCE(SUM(ROUND(GREATEST(p.amount - COALESCE(r.refunded_amount,0),0) * cr.commission_rate / 100,2)),0) AS gross_commission
      FROM course_commission_rules cr
      JOIN courses c ON c.id = cr.course_id
      JOIN users u ON u.id = cr.teacher_user_id
      LEFT JOIN course_payments p ON p.course_id = cr.course_id AND p.status = 'verified' AND p.reviewed_at >= cr.created_at AND (cr.closed_at IS NULL OR p.reviewed_at < cr.closed_at)
      LEFT JOIN (SELECT payment_id, SUM(amount) AS refunded_amount FROM payment_refunds WHERE status = 'completed' GROUP BY payment_id) r ON r.payment_id = p.id
      WHERE cr.teacher_user_id = ?
      GROUP BY cr.id, cr.course_id, c.title, cr.teacher_user_id, u.name, cr.commission_rate ORDER BY c.title");
    $stmt->execute([$teacherId]);
    aec_ok(['balance' => $balance, 'courses' => $stmt->fetchAll()]);
}

if ($action === 'rules') {
    aec_method(['GET']);
    $actor = aec_require_unscoped_permission('commissions.manage');
    $stmt = $db->query("SELECT cr.id, cr.course_id, c.title AS course_title, cr.teacher_user_id, u.name AS teacher_name,
        cr.commission_rate, cr.is_active, cr.created_at, cr.updated_at
      FROM course_commission_rules cr JOIN courses c ON c.id = cr.course_id JOIN users u ON u.id = cr.teacher_user_id
      ORDER BY c.title");
    aec_ok($stmt->fetchAll());
}

if ($action === 'set_rule') {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('commissions.manage');
    aec_csrf_check();
    $input = aec_input();
    $courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
    $teacherId = filter_var($input['teacher_user_id'] ?? null, FILTER_VALIDATE_INT);
    $rate = filter_var($input['commission_rate'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!$courseId || $courseId < 1 || !$teacherId || $teacherId < 1 || $rate === false || $rate < 0 || $rate > 100) aec_fail('Course, teacher and a commission rate from 0 to 100 are required.', 422);
    $check = $db->prepare("SELECT u.id FROM users u JOIN courses c ON c.id = ? WHERE u.id = ? AND u.role IN ('teacher','assistant_teacher') AND u.is_active = 1");
    $check->execute([$courseId, $teacherId]);
    if (!$check->fetchColumn()) aec_fail('Select an active teacher and an existing course.', 422);
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id FROM courses WHERE id = ? FOR UPDATE');
        $lock->execute([$courseId]);
        if (!$lock->fetchColumn()) { $db->rollBack(); aec_fail('Course not found.', 404); }
        $db->prepare('UPDATE course_commission_rules SET is_active = 0, closed_at = NOW(6), updated_at = NOW() WHERE course_id = ? AND is_active = 1')->execute([$courseId]);
        $insert = $db->prepare('INSERT INTO course_commission_rules (course_id, teacher_user_id, commission_rate, is_active, created_by) VALUES (?, ?, ?, 1, ?)');
        $insert->execute([$courseId, $teacherId, number_format((float)$rate, 2, '.', ''), (int)$actor['id']]);
        $id = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_commission_audit($db, $actor, 'commission.rule_saved', 'course_commission_rule', $id, ['course_id' => $courseId, 'teacher_user_id' => $teacherId, 'commission_rate' => $rate]);
    aec_ok(['id' => $id], 'Commission rule saved.');
}

if ($action === 'request_payout') {
    aec_method(['POST']);
    $actor = aec_current_user();
    if (!in_array($actor['role'], ['teacher','assistant_teacher'], true)) aec_fail('Only teachers can request their own payout.', 403);
    aec_csrf_check();
    $input = aec_input();
    $amount = aec_commission_money($input['amount'] ?? '');
    $method = (string)($input['payout_method'] ?? '');
    $account = trim((string)($input['account_number'] ?? ''));
    $bank = trim((string)($input['bank_name'] ?? ''));
    $bankBranch = trim((string)($input['bank_branch'] ?? ''));
    if (!in_array($method, ['bKash','Nagad','Bank Transfer'], true) || $account === '' || strlen($account) > 80) aec_fail('A valid payout method and account number are required.', 422);
    if ($method === 'Bank Transfer' && ($bank === '' || $bankBranch === '' || strlen($bank) > 120 || strlen($bankBranch) > 120)) aec_fail('Bank and branch names are required for bank transfer.', 422);
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
        $lock->execute([(int)$actor['id']]);
        if (!$lock->fetchColumn()) { $db->rollBack(); aec_fail('Teacher account is unavailable.', 403); }
        $balance = aec_commission_balance($db, (int)$actor['id']);
        if ((float)$amount > $balance['available_balance']) { $db->rollBack(); aec_fail('Payout request exceeds your available commission balance.', 422, ['available_balance' => $balance['available_balance']]); }
        $stmt = $db->prepare("INSERT INTO commission_payout_requests (teacher_user_id, amount, payout_method, account_number, bank_name, bank_branch, remarks)
          VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([(int)$actor['id'], $amount, $method, $account, $method === 'Bank Transfer' ? $bank : null, $method === 'Bank Transfer' ? $bankBranch : null,
          mb_substr(trim((string)($input['remarks'] ?? '')), 0, 500)]);
        $id = (int)$db->lastInsertId();
        aec_commission_audit($db, $actor, 'commission.payout_requested', 'commission_payout_request', $id, ['amount' => $amount, 'method' => $method]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_ok(['id' => $id], 'Payout request submitted.', 201);
}

if ($action === 'payouts') {
    aec_method(['GET']);
    $actor = aec_current_user();
    if (!aec_commission_admin($actor) && !in_array($actor['role'], ['teacher','assistant_teacher'], true)) aec_fail('You cannot view payout requests.', 403);
    if (aec_commission_admin($actor)) {
        $stmt = $db->query("SELECT p.id, p.teacher_user_id, u.name AS teacher_name, p.amount, p.payout_method,
            p.account_number, p.bank_name, p.bank_branch, p.status, p.transaction_reference, p.remarks,
            p.created_at, p.reviewed_at, p.completed_at, j.reference AS journal_reference
          FROM commission_payout_requests p JOIN users u ON u.id = p.teacher_user_id
          LEFT JOIN commission_payout_journal_transactions j ON j.payout_request_id = p.id ORDER BY p.created_at DESC");
        aec_ok($stmt->fetchAll());
    }
    $stmt = $db->prepare("SELECT id, teacher_user_id, amount, payout_method, account_number, bank_name, bank_branch, status,
        transaction_reference, remarks, created_at, reviewed_at, completed_at,
        (SELECT j.reference FROM commission_payout_journal_transactions j WHERE j.payout_request_id = commission_payout_requests.id) AS journal_reference
      FROM commission_payout_requests WHERE teacher_user_id = ? ORDER BY created_at DESC");
    $stmt->execute([(int)$actor['id']]);
    aec_ok($stmt->fetchAll());
}

if (in_array($action, ['approve','reject','complete'], true)) {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('commissions.manage');
    aec_csrf_check();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid payout request id is required.', 422);
    $input = aec_input();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM commission_payout_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request) { $db->rollBack(); aec_fail('Payout request not found.', 404); }
        if ($action === 'approve' && $request['status'] !== 'pending') { $db->rollBack(); aec_fail('Only pending payout requests can be approved.', 409); }
        if ($action === 'reject' && !in_array($request['status'], ['pending','approved'], true)) { $db->rollBack(); aec_fail('This payout request can no longer be rejected.', 409); }
        if ($action === 'complete' && $request['status'] !== 'approved') { $db->rollBack(); aec_fail('Only approved payouts can be marked completed.', 409); }
        $reviewer = (int)$actor['id'];
        if ($action === 'approve') {
            $db->prepare("UPDATE commission_payout_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$reviewer, $id]);
        } elseif ($action === 'reject') {
            $reason = trim((string)($input['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 500) { $db->rollBack(); aec_fail('A rejection reason is required.', 422); }
            $db->prepare("UPDATE commission_payout_requests SET status = 'rejected', remarks = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$reason, $reviewer, $id]);
        } else {
            $reference = trim((string)($input['transaction_reference'] ?? ''));
            if ($reference === '' || strlen($reference) > 140) { $db->rollBack(); aec_fail('Enter the actual bank or mobile-financial-service transaction reference.', 422); }
            $teacherLock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $teacherLock->execute([(int)$request['teacher_user_id']]);
            $currentBalance = aec_commission_balance($db, (int)$request['teacher_user_id'], (int)$id);
            if ($currentBalance['available_balance'] + 0.0001 < (float)$request['amount']) {
                $db->rollBack();
                aec_fail('The available commission balance changed after this request. Reject or review the payout before marking it paid.', 409, ['available_balance' => $currentBalance['available_balance']]);
            }
            $debitStmt = $db->prepare("SELECT id FROM accounting_accounts WHERE account_code = 'EXPENSE_TEACHER_COMMISSION' AND is_active = 1 LIMIT 1");
            $debitStmt->execute(); $debitAccount = $debitStmt->fetchColumn();
            $creditCode = $request['payout_method'] === 'Bank Transfer' ? 'ASSET_BANK_CLEARING' : 'ASSET_BKASH_NAGAD_CLEARING';
            $creditStmt = $db->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1');
            $creditStmt->execute([$creditCode]); $creditAccount = $creditStmt->fetchColumn();
            if (!$debitAccount || !$creditAccount) {
                $db->rollBack();
                aec_fail('Required teacher commission accounting accounts are missing. Apply the latest database migrations.', 503);
            }
            $journalReference = 'TPAY-' . $id . '-' . strtoupper(bin2hex(random_bytes(4)));
            $db->prepare('INSERT INTO commission_payout_journal_transactions (payout_request_id, reference, description, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$id, $journalReference, 'Teacher commission payout: ' . $request['teacher_user_id'], $reviewer]);
            $journalId = (int)$db->lastInsertId();
            $amountValue = number_format((float)$request['amount'], 2, '.', '');
            $entry = $db->prepare('INSERT INTO commission_payout_journal_entries (transaction_id, account_id, debit, credit, currency) VALUES (?, ?, ?, ?, ?)');
            $entry->execute([$journalId, (int)$debitAccount, $amountValue, '0.00', 'BDT']);
            $entry->execute([$journalId, (int)$creditAccount, '0.00', $amountValue, 'BDT']);
            $db->prepare("UPDATE commission_payout_requests SET status = 'completed', transaction_reference = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$reference, $id]);

        }
        aec_commission_audit($db, $actor, 'commission.payout_' . $action, 'commission_payout_request', (int)$id, ['teacher_user_id' => (int)$request['teacher_user_id'], 'amount' => $request['amount'], 'journal_reference' => $action === 'complete' ? ($journalReference ?? null) : null]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') aec_fail('This transaction reference has already been used.', 409);
        throw $e;
    }
    aec_ok(['id' => (int)$id, 'status' => $action === 'complete' ? 'completed' : ($action === 'approve' ? 'approved' : 'rejected')], 'Payout request updated.');
}

aec_fail('Unknown commission action.', 404);
