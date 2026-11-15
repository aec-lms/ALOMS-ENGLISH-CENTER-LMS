<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
aec_method(['GET']);
$type = (string)($_GET['type'] ?? 'revenue');
$reportPermission = match ($type) { 'expenses' => 'expenses.manage', 'facility_fees' => 'facility.fees.manage', 'trial_balance', 'income_statement' => 'accounting.reports.view', default => 'payments.manage' };
$actor = aec_require_permission($reportPermission);
aec_assert_active_branch($db, $actor);
$period = (string)($_GET['period'] ?? 'current_month');
$allowedPeriods = ['current_month', 'last_month', 'last_30_days', 'current_year'];
if (!in_array($period, $allowedPeriods, true)) aec_fail('Invalid reporting period.', 422);
$dateSql = match ($period) {
    'current_month' => "DATE_FORMAT(NOW(), '%Y-%m-01')",
    'last_month' => "DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')",
    'last_30_days' => 'DATE_SUB(CURDATE(), INTERVAL 29 DAY)',
    'current_year' => "DATE_FORMAT(NOW(), '%Y-01-01')",
};
$from = $period === 'last_month'
    ? "DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')"
    : $dateSql;
$to = $period === 'last_month'
    ? "LAST_DAY(DATE_SUB(NOW(), INTERVAL 1 MONTH))"
    : 'CURDATE()';
$branchId = null;
if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) {
    if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
    $branchId = (int)$actor['branch_id'];
} elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== 'all') {
    $branchId = filter_var($_GET['branch_id'], FILTER_VALIDATE_INT);
    if (!$branchId || $branchId < 1) aec_fail('Invalid branch filter.', 422);
}
$params = [];
$branchClause = '';
if ($branchId !== null) {
    $branchClause = ' AND %s.branch_id = ?';
    $params[] = $branchId;
}

if (in_array($type, ['trial_balance','income_statement'], true)) {
    $journalRows = "(
        SELECT le.account_id, le.debit, le.credit, lt.created_at AS posted_at, COALESCE(p.branch_id,0) AS branch_id
        FROM ledger_entries le
        INNER JOIN ledger_transactions lt ON lt.id = le.transaction_id
        LEFT JOIN payment_refunds rf ON rf.id = lt.refund_id
        LEFT JOIN course_payments p ON p.id = COALESCE(lt.payment_id,rf.payment_id)
        UNION ALL
        SELECT eje.account_id, eje.debit, eje.credit, ejt.created_at AS posted_at, e.branch_id
        FROM expense_journal_entries eje
        INNER JOIN expense_journal_transactions ejt ON ejt.id = eje.transaction_id
        INNER JOIN expense_records e ON e.id = ejt.expense_id
        UNION ALL
        SELECT lfe.account_id, lfe.debit, lfe.credit, lfjt.created_at AS posted_at, lfp.branch_id
        FROM library_fine_journal_entries lfe
        INNER JOIN library_fine_journal_transactions lfjt ON lfjt.id = lfe.transaction_id
        INNER JOIN library_fine_payments lfp ON lfp.id = lfjt.fine_payment_id
        UNION ALL
        SELECT ffe.account_id, ffe.debit, ffe.credit, ffjt.created_at AS posted_at, ffp.branch_id
        FROM facility_fee_journal_entries ffe
        INNER JOIN facility_fee_journal_transactions ffjt ON ffjt.id = ffe.transaction_id
        INNER JOIN facility_fee_payments ffp ON ffp.id = ffjt.payment_id
        UNION ALL
        SELECT cpe.account_id, cpe.debit, cpe.credit, cpjt.created_at AS posted_at, COALESCE(t.branch_id,0) AS branch_id
        FROM commission_payout_journal_entries cpe
        INNER JOIN commission_payout_journal_transactions cpjt ON cpjt.id = cpe.transaction_id
        INNER JOIN commission_payout_requests cpr ON cpr.id = cpjt.payout_request_id
        INNER JOIN users t ON t.id = cpr.teacher_user_id
    )";
    $journalScope = $branchClause !== '' ? sprintf($branchClause, 'j') : '';
    if ($type === 'trial_balance') {
        $sql = "SELECT a.account_code, a.account_name, a.account_type,
                       COALESCE(SUM(j.debit),0) AS total_debit,
                       COALESCE(SUM(j.credit),0) AS total_credit,
                       GREATEST(COALESCE(SUM(j.debit - j.credit),0),0) AS debit_balance,
                       GREATEST(COALESCE(SUM(j.credit - j.debit),0),0) AS credit_balance
                FROM accounting_accounts a
                LEFT JOIN {$journalRows} j ON j.account_id = a.id
                  AND j.posted_at < DATE_ADD({$to}, INTERVAL 1 DAY){$journalScope}
                GROUP BY a.id, a.account_code, a.account_name, a.account_type
                ORDER BY a.account_type, a.account_code";
    } else {
        $sql = "SELECT a.account_code, a.account_name, a.account_type,
                       COALESCE(SUM(CASE WHEN a.account_type = 'income' THEN j.credit - j.debit
                                         WHEN a.account_type = 'expense' THEN j.debit - j.credit ELSE 0 END),0) AS period_amount
                FROM {$journalRows} j
                INNER JOIN accounting_accounts a ON a.id = j.account_id
                WHERE j.posted_at >= {$from} AND j.posted_at < DATE_ADD({$to}, INTERVAL 1 DAY){$journalScope}
                  AND a.account_type IN ('income','expense')
                GROUP BY a.id, a.account_code, a.account_name, a.account_type
                ORDER BY a.account_type, a.account_code";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type'=>$type,'period'=>$period,'rows'=>$stmt->fetchAll()]);
}

if ($type === 'facility_fees') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'p') : '';
    $sql = "SELECT DATE(p.received_at) AS report_date, i.service_type,
                   COUNT(*) AS payment_count, COALESCE(SUM(p.amount),0) AS collected_amount
            FROM facility_fee_payments p
            INNER JOIN facility_fee_invoices i ON i.id = p.invoice_id
            WHERE p.received_at >= {$from} AND p.received_at < DATE_ADD({$to}, INTERVAL 1 DAY){$scope}
            GROUP BY DATE(p.received_at), i.service_type
            ORDER BY report_date ASC, i.service_type ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type'=>$type,'period'=>$period,'rows'=>$stmt->fetchAll()]);
}

if ($type === 'expenses') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'e') : '';
    $sql = "SELECT DATE(e.expense_date) AS report_date, e.category, e.status,
                   COUNT(*) AS record_count, COALESCE(SUM(e.amount),0) AS total_amount
            FROM expense_records e
            WHERE e.expense_date >= {$from} AND e.expense_date <= {$to}{$scope}
            GROUP BY DATE(e.expense_date), e.category, e.status
            ORDER BY report_date ASC, e.category ASC, e.status ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type'=>$type,'period'=>$period,'rows'=>$stmt->fetchAll()]);
}

if ($type === 'revenue') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'p') : '';
    $sql = "SELECT DATE(p.reviewed_at) AS report_date, COUNT(*) AS payment_count,
                   COALESCE(SUM(p.amount), 0) AS gross_amount,
                   COALESCE(SUM(COALESCE(r.refunded_amount, 0)), 0) AS refunded_amount,
                   COALESCE(SUM(GREATEST(p.amount - COALESCE(r.refunded_amount, 0), 0)), 0) AS net_amount
            FROM course_payments p
            LEFT JOIN (
              SELECT payment_id, SUM(amount) AS refunded_amount
              FROM payment_refunds WHERE status = 'completed' GROUP BY payment_id
            ) r ON r.payment_id = p.id
            WHERE p.status IN ('verified','refunded')
              AND p.reviewed_at >= {$from} AND p.reviewed_at < DATE_ADD({$to}, INTERVAL 1 DAY){$scope}
            GROUP BY DATE(p.reviewed_at) ORDER BY report_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type' => $type, 'period' => $period, 'rows' => $stmt->fetchAll()]);
}

if ($type === 'admission') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'c') : '';
    $sql = "SELECT a.status, COUNT(*) AS application_count
            FROM admission_applications a
            INNER JOIN courses c ON c.id = a.course_id
            WHERE a.created_at >= {$from} AND a.created_at < DATE_ADD({$to}, INTERVAL 1 DAY){$scope}
            GROUP BY a.status ORDER BY a.status";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type' => $type, 'period' => $period, 'rows' => $stmt->fetchAll()]);
}

if ($type === 'attendance') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'ar') : '';
    $sql = "SELECT ar.attendance_date AS report_date,
                   COUNT(*) AS marked_count,
                   SUM(ar.status = 'present') AS present_count,
                   SUM(ar.status = 'absent') AS absent_count,
                   SUM(ar.status = 'late') AS late_count,
                   SUM(ar.status = 'excused') AS excused_count
            FROM attendance_records ar
            WHERE ar.attendance_date >= {$from} AND ar.attendance_date <= {$to}{$scope}
            GROUP BY ar.attendance_date ORDER BY ar.attendance_date ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type' => $type, 'period' => $period, 'rows' => $stmt->fetchAll()]);
}

if ($type === 'exams') {
    $scope = $branchClause !== '' ? sprintf($branchClause, 'c') : '';
    $sql = "SELECT e.id AS exam_id, e.title AS exam_title, COUNT(a.id) AS attempt_count,
                   SUM(a.status = 'submitted') AS submitted_count,
                   SUM(a.status = 'submitted' AND a.passed = 1) AS passed_count,
                   ROUND(AVG(CASE WHEN a.status = 'submitted' THEN a.percentage END), 2) AS average_percentage
            FROM exams e
            LEFT JOIN courses c ON c.id = e.course_id
            LEFT JOIN exam_attempts a ON a.exam_id = e.id
              AND a.submitted_at >= {$from} AND a.submitted_at < DATE_ADD({$to}, INTERVAL 1 DAY)
            WHERE e.deleted_at IS NULL{$scope}
            GROUP BY e.id, e.title ORDER BY e.title ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok(['type' => $type, 'period' => $period, 'rows' => $stmt->fetchAll()]);
}

aec_fail('Unknown report type.', 404);
