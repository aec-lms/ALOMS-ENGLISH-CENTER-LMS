<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'list');

function aec_facility_fee_actor(PDO $db): array {
    $actor = aec_require_permission('facility.fees.manage');
    aec_assert_active_branch($db, $actor);
    if ($actor['role'] === 'support_staff') {
        $branchId = filter_var($actor['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$branchId || $branchId < 1) aec_fail('Your account is not assigned to a branch.', 403);
        $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$branchId]);
        if (!$stmt->fetchColumn()) aec_fail('Your assigned branch is inactive. Contact an administrator.', 403);
    }
    return $actor;
}
function aec_facility_fee_branch(PDO $db, array $actor, mixed $requested): int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('Select a valid branch.', 422);
    $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) aec_fail('Select an active branch.', 422);
    return (int)$id;
}
function aec_facility_fee_month(mixed $value): array {
    if (!is_string($value) || !preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $value)) aec_fail('Billing month must use YYYY-MM format.', 422);
    $first = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
    $errors = DateTimeImmutable::getLastErrors();
    if (!$first || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) aec_fail('Billing month is invalid.', 422);
    return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
}
function aec_facility_fee_audit(PDO $db, array $actor, string $event, string $entity, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, $entity, $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45) ?: null,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function aec_facility_fee_rows(PDO $db, string $where, array $params): array {
    $sql = "SELECT i.id, i.service_type, i.hostel_allocation_id, i.transport_assignment_id, i.branch_id,
                   br.name AS branch_name, i.student_user_id, u.name AS student_name, u.email,
                   i.billing_month, i.description, i.amount, i.due_date, i.status,
                   COALESCE(p.paid_amount,0) AS paid_amount,
                   GREATEST(i.amount - COALESCE(p.paid_amount,0),0) AS outstanding_amount
            FROM facility_fee_invoices i
            JOIN branches br ON br.id = i.branch_id
            JOIN users u ON u.id = i.student_user_id
            LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid_amount FROM facility_fee_payments GROUP BY invoice_id) p ON p.invoice_id = i.id
            {$where}
            ORDER BY i.billing_month DESC, i.id DESC LIMIT 1500";
    $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}

if ($action === 'my') {
    aec_method(['GET']);
    $actor = aec_current_user();
    if ($actor['role'] !== 'student') aec_fail('This endpoint is for student accounts only.', 403);
    aec_ok(aec_facility_fee_rows($db, 'WHERE i.student_user_id = ?', [(int)$actor['id']]));
}
if ($action === 'branches') {
    aec_method(['GET']);
    $actor = aec_facility_fee_actor($db);
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    aec_ok($stmt->fetchAll());
}
if ($action === 'list') {
    aec_method(['GET']);
    $actor = aec_facility_fee_actor($db);
    $where = 'WHERE 1=1'; $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $where .= ' AND i.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $where .= ' AND i.branch_id = ?'; $params[] = aec_facility_fee_branch($db, $actor, $_GET['branch_id']); }
    if (isset($_GET['service_type']) && in_array($_GET['service_type'], ['hostel','transport'], true)) { $where .= ' AND i.service_type = ?'; $params[] = $_GET['service_type']; }
    if (isset($_GET['status']) && in_array($_GET['status'], ['unpaid','partial','paid','void'], true)) { $where .= ' AND i.status = ?'; $params[] = $_GET['status']; }
    aec_ok(aec_facility_fee_rows($db, $where, $params));
}
if ($action === 'payments') {
    aec_method(['GET']);
    $actor = aec_facility_fee_actor($db);
    $sql = 'SELECT p.id, p.invoice_id, p.branch_id, br.name AS branch_name, p.student_user_id,
                   u.name AS student_name, i.service_type, i.billing_month, p.amount, p.payment_method,
                   p.payment_reference, p.received_at, receiver.name AS received_by,
                   j.reference AS journal_reference
            FROM facility_fee_payments p
            JOIN facility_fee_invoices i ON i.id = p.invoice_id
            JOIN branches br ON br.id = p.branch_id
            JOIN users u ON u.id = p.student_user_id
            JOIN users receiver ON receiver.id = p.received_by
            LEFT JOIN facility_fee_journal_transactions j ON j.payment_id = p.id
            WHERE 1=1';
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND p.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' AND p.branch_id = ?'; $params[] = aec_facility_fee_branch($db, $actor, $_GET['branch_id']); }
    $sql .= ' ORDER BY p.received_at DESC, p.id DESC LIMIT 1500';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'generate') {
    aec_method(['POST']);
    $actor = aec_facility_fee_actor($db); aec_csrf_check(); $input = aec_input();
    $service = (string)($input['service_type'] ?? '');
    if (!in_array($service, ['hostel','transport'], true)) aec_fail('Choose hostel or transport billing.', 422);
    $branchId = aec_facility_fee_branch($db, $actor, $input['branch_id'] ?? null);
    [$monthStart,$monthEnd] = aec_facility_fee_month($input['billing_month'] ?? null);
    $db->beginTransaction();
    try {
        if ($service === 'hostel') {
            $sql = "INSERT IGNORE INTO facility_fee_invoices
                    (service_type, hostel_allocation_id, branch_id, student_user_id, billing_month, description, amount, due_date, generated_by)
                    SELECT 'hostel', a.id, a.branch_id, a.student_user_id, ?, CONCAT('Hostel accommodation - ', r.building_name, ' / ', r.room_number, ' - ', ?),
                           a.monthly_rent_snapshot, ?, ?
                    FROM hostel_allocations a JOIN hostel_rooms r ON r.id = a.room_id
                    WHERE a.branch_id = ? AND a.start_date <= ? AND (a.end_date IS NULL OR a.end_date >= ?)
                      AND a.monthly_rent_snapshot > 0";
        } else {
            $sql = "INSERT IGNORE INTO facility_fee_invoices
                    (service_type, transport_assignment_id, branch_id, student_user_id, billing_month, description, amount, due_date, generated_by)
                    SELECT 'transport', a.id, a.branch_id, a.student_user_id, ?, CONCAT('Student transport - ', r.route_name, ' / ', r.vehicle_number, ' - ', ?),
                           a.monthly_fee_snapshot, ?, ?
                    FROM transport_assignments a JOIN transport_routes r ON r.id = a.route_id
                    WHERE a.branch_id = ? AND a.start_date <= ? AND (a.end_date IS NULL OR a.end_date >= ?)
                      AND a.monthly_fee_snapshot > 0";
        }
        $stmt = $db->prepare($sql);
        $descriptionMonth = substr($monthStart,0,7);
        $stmt->execute([$monthStart,$descriptionMonth,$monthStart,(int)$actor['id'],$branchId,$monthEnd,$monthStart]);
        $created = $stmt->rowCount();
        aec_facility_fee_audit($db,$actor,'facility_fee.invoices_generated','facility_fee_batch',$branchId,[
            'service_type'=>$service,'branch_id'=>$branchId,'billing_month'=>$descriptionMonth,'created_count'=>$created
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_ok(['created_count'=>$created,'service_type'=>$service,'branch_id'=>$branchId,'billing_month'=>substr($monthStart,0,7)],
        'Monthly facility invoices generated.', 201);
}
if ($action === 'pay') {
    aec_method(['POST']);
    $actor = aec_facility_fee_actor($db); aec_csrf_check(); $input = aec_input();
    if (!in_array($actor['role'], ['admin','accountant','branch_manager'], true)) aec_fail('You cannot record facility fee payments.', 403);
    $invoiceId = filter_var($input['invoice_id'] ?? null, FILTER_VALIDATE_INT);
    $amountRaw = trim((string)($input['amount'] ?? ''));
    $method = (string)($input['payment_method'] ?? '');
    $reference = trim((string)($input['payment_reference'] ?? ''));
    if (!$invoiceId || $invoiceId < 1 || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/', $amountRaw)
        || (float)$amountRaw <= 0 || !in_array($method,['cash','bank_transfer','bKash','Nagad'],true)
        || $reference === '' || mb_strlen($reference) > 140) aec_fail('Enter a valid invoice, amount, payment method and actual reference.',422);
    $creditCode = ['hostel'=>'INCOME_HOSTEL_FEES','transport'=>'INCOME_TRANSPORT_FEES'];
    $debitCode = ['cash'=>'ASSET_CASH_ON_HAND','bank_transfer'=>'ASSET_BANK_CLEARING','bKash'=>'ASSET_BKASH_NAGAD_CLEARING','Nagad'=>'ASSET_BKASH_NAGAD_CLEARING'][$method];
    $db->beginTransaction();
    try {
        $sql = 'SELECT * FROM facility_fee_invoices WHERE id = ?' . (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$invoiceId];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $invoice = $stmt->fetch();
        if (!$invoice) { $db->rollBack(); aec_fail('Facility fee invoice not found.',404); }
        if ($invoice['status'] === 'void') { $db->rollBack(); aec_fail('Voided invoices cannot receive payments.',409); }
        $paidStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM facility_fee_payments WHERE invoice_id = ?');
        $paidStmt->execute([$invoiceId]); $paid = (float)$paidStmt->fetchColumn();
        $outstanding = round((float)$invoice['amount'] - $paid,2); $amount = round((float)$amountRaw,2);
        if ($outstanding <= 0 || $amount > $outstanding + 0.00001) { $db->rollBack(); aec_fail('Payment exceeds the outstanding invoice balance.',409,['outstanding_amount'=>number_format(max(0,$outstanding),2,'.','')]); }
        $db->prepare('INSERT INTO facility_fee_payments (invoice_id,branch_id,student_user_id,amount,payment_method,payment_reference,received_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$invoiceId,(int)$invoice['branch_id'],(int)$invoice['student_user_id'],number_format($amount,2,'.',''),$method,$reference,(int)$actor['id']]);
        $paymentId=(int)$db->lastInsertId();
        $account=$db->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1');
        $account->execute([$debitCode]);$debitAccount=$account->fetchColumn();
        $account->execute([$creditCode[$invoice['service_type']]]);$creditAccount=$account->fetchColumn();
        if(!$debitAccount||!$creditAccount){$db->rollBack();aec_fail('Required facility fee accounting accounts are missing. Apply the latest migrations.',503);}
        $journalRef='FACFEE-'.$paymentId.'-'.strtoupper(bin2hex(random_bytes(4)));
        $db->prepare('INSERT INTO facility_fee_journal_transactions (payment_id,reference,description,created_by) VALUES (?,?,?,?)')
            ->execute([$paymentId,$journalRef,$invoice['description'],(int)$actor['id']]);
        $journalId=(int)$db->lastInsertId();
        $entry=$db->prepare('INSERT INTO facility_fee_journal_entries (transaction_id,account_id,debit,credit,currency) VALUES (?,?,?,?,?)');
        $entry->execute([$journalId,(int)$debitAccount,number_format($amount,2,'.',''),'0.00','BDT']);
        $entry->execute([$journalId,(int)$creditAccount,'0.00',number_format($amount,2,'.',''),'BDT']);
        $newPaid=round($paid+$amount,2);
        $newStatus=$newPaid+0.00001 >= (float)$invoice['amount']?'paid':'partial';
        $db->prepare('UPDATE facility_fee_invoices SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus,$invoiceId]);
        aec_facility_fee_audit($db,$actor,'facility_fee.payment_received','facility_fee_invoice',$invoiceId,[
            'payment_id'=>$paymentId,'amount'=>number_format($amount,2,'.',''),'method'=>$method,'reference'=>$reference,'journal_reference'=>$journalRef
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        if($e instanceof PDOException && $e->getCode()==='23000')aec_fail('This facility fee payment reference has already been recorded.',409);
        throw $e;
    }
    aec_ok(['payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'status'=>$newStatus,'outstanding_amount'=>number_format(max(0,$outstanding-$amount),2,'.',''),'journal_reference'=>$journalRef],
        'Facility fee payment received and journal posted.',201);
}
aec_fail('Unknown facility fee action.',404);
