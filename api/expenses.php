<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'list');

function aec_expense_actor(PDO $db): array {
    $actor = aec_require_permission('expenses.manage');
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
function aec_expense_finance_role(array $actor): void {
    if (!in_array($actor['role'], ['admin','accountant'], true)) aec_fail('Only administrators and accountants may approve or pay expenses.', 403);
}
function aec_expense_branch(PDO $db, array $actor, mixed $requested): int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid branch is required for each expense.', 422);
    $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) aec_fail('Select an active branch.', 422);
    return (int)$id;
}
function aec_expense_date(mixed $value): string {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) aec_fail('Expense date must use YYYY-MM-DD format.', 422);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) aec_fail('Expense date is invalid.', 422);
    return $date->format('Y-m-d');
}
function aec_expense_audit(PDO $db, array $actor, string $event, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, 'expense', $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45) ?: null,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

if ($action === 'list') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    $sql = 'SELECT e.id, e.branch_id, b.name AS branch_name, e.category, e.description, e.vendor, esl.supplier_id, COALESCE(s.supplier_name, e.vendor) AS vendor_name, e.amount,
                   e.currency, e.expense_date, e.status, e.rejection_reason, e.payment_method, e.payment_reference, j.reference AS journal_reference,
                   e.requested_by, requester.name AS requester_name, e.approved_by, approver.name AS approver_name,
                   e.approved_at, e.paid_by, payer.name AS payer_name, e.paid_at, e.created_at
            FROM expense_records e JOIN branches b ON b.id = e.branch_id
            JOIN users requester ON requester.id = e.requested_by
            LEFT JOIN users approver ON approver.id = e.approved_by
            LEFT JOIN users payer ON payer.id = e.paid_by
            LEFT JOIN expense_record_suppliers esl ON esl.expense_id = e.id
            LEFT JOIN expense_suppliers s ON s.id = esl.supplier_id
            LEFT JOIN expense_journal_transactions j ON j.expense_id = e.id WHERE 1=1';
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND e.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' AND e.branch_id = ?'; $params[] = aec_expense_branch($db, $actor, $_GET['branch_id']); }
    if (isset($_GET['status']) && in_array($_GET['status'], ['pending','approved','rejected','paid'], true)) { $sql .= ' AND e.status = ?'; $params[] = $_GET['status']; }
    $sql .= ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'branches') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    aec_ok($stmt->fetchAll());
}
if ($action === 'summary') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    $sql = 'SELECT status, COUNT(*) AS record_count, COALESCE(SUM(amount),0) AS total_amount FROM expense_records';
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' WHERE branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' WHERE branch_id = ?'; $params[] = aec_expense_branch($db, $actor, $_GET['branch_id']); }
    $sql .= ' GROUP BY status ORDER BY status';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'suppliers') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    $sql = 'SELECT s.id, s.branch_id, b.name AS branch_name, s.supplier_name, s.contact_person, s.phone, s.email, s.address, s.tax_id, s.notes, s.is_active, s.created_at FROM expense_suppliers s JOIN branches b ON b.id = s.branch_id WHERE 1=1';
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND s.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' AND s.branch_id = ?'; $params[] = aec_expense_branch($db, $actor, $_GET['branch_id']); }
    if (($_GET['active'] ?? '') === '1') $sql .= ' AND s.is_active = 1';
    $sql .= ' ORDER BY s.supplier_name LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'supplier_create') {
    aec_method(['POST']);
    $actor = aec_expense_actor($db); aec_csrf_check(); $input = aec_input();
    if (!in_array($actor['role'], ['admin','accountant','branch_manager'], true)) aec_fail('You cannot manage suppliers.', 403);
    $branchId = aec_expense_branch($db, $actor, $input['branch_id'] ?? null);
    $name = trim((string)($input['supplier_name'] ?? ''));
    $contact = trim((string)($input['contact_person'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $taxId = trim((string)($input['tax_id'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160 || mb_strlen($contact) > 160 || mb_strlen($phone) > 40 || mb_strlen($email) > 190
        || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || mb_strlen($address) > 500 || mb_strlen($taxId) > 80) aec_fail('Enter valid supplier details.', 422);
    try {
        $stmt = $db->prepare('INSERT INTO expense_suppliers (branch_id, supplier_name, contact_person, phone, email, address, tax_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$branchId,$name,$contact ?: null,$phone ?: null,$email ?: null,$address ?: null,$taxId ?: null,(int)$actor['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') aec_fail('A supplier with this name already exists in this branch.', 409);
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    aec_expense_audit($db,$actor,'expense.supplier_created',$id,['branch_id'=>$branchId]);
    aec_ok(['id'=>$id], 'Supplier created.', 201);
}
if ($action === 'supplier_archive') {
    aec_method(['POST']);
    $actor = aec_expense_actor($db); aec_csrf_check(); $input = aec_input();
    if (!in_array($actor['role'], ['admin','accountant','branch_manager'], true)) aec_fail('You cannot manage suppliers.', 403);
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid supplier id is required.', 422);
    $stmt = $db->prepare('SELECT id, branch_id FROM expense_suppliers WHERE id = ?'); $stmt->execute([$id]); $supplier = $stmt->fetch();
    if (!$supplier) aec_fail('Supplier not found.',404);
    if (in_array($actor['role'], ['branch_manager','support_staff'], true) && (int)$supplier['branch_id'] !== (int)$actor['branch_id']) aec_fail('You cannot manage another branch supplier.',403);
    $db->prepare('UPDATE expense_suppliers SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
    aec_expense_audit($db,$actor,'expense.supplier_archived',(int)$id);
    aec_ok(['id'=>(int)$id,'is_active'=>0], 'Supplier archived.');
}
if ($action === 'budgets') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    $month = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $month)) aec_fail('Budget month must use YYYY-MM format.',422);
    $monthDate = $month . '-01';
    $sql = "SELECT b.id, b.branch_id, br.name AS branch_name, b.category, b.budget_month, b.budget_amount, b.notes,
                   COALESCE(SUM(CASE WHEN e.status IN ('approved','paid') THEN e.amount ELSE 0 END),0) AS committed_amount,
                   GREATEST(b.budget_amount - COALESCE(SUM(CASE WHEN e.status IN ('approved','paid') THEN e.amount ELSE 0 END),0),0) AS remaining_amount
            FROM expense_monthly_budgets b JOIN branches br ON br.id = b.branch_id
            LEFT JOIN expense_records e ON e.branch_id = b.branch_id AND e.category = b.category
              AND e.expense_date >= b.budget_month AND e.expense_date < DATE_ADD(b.budget_month, INTERVAL 1 MONTH)
            WHERE b.budget_month = ?";
    $params = [$monthDate];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND b.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' AND b.branch_id = ?'; $params[] = aec_expense_branch($db, $actor, $_GET['branch_id']); }
    $sql .= ' GROUP BY b.id, b.branch_id, br.name, b.category, b.budget_month, b.budget_amount, b.notes ORDER BY br.name, b.category';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'budget_save') {
    aec_method(['POST']);
    $actor = aec_expense_actor($db); aec_csrf_check(); $input = aec_input();
    if (!in_array($actor['role'], ['admin','accountant'], true)) aec_fail('Only administrators and accountants may set budgets.',403);
    $branchId = aec_expense_branch($db,$actor,$input['branch_id'] ?? null);
    $category = (string)($input['category'] ?? '');
    $categories = ['rent','utilities','salary','marketing','transport','hostel','library','supplies','other'];
    $month = (string)($input['budget_month'] ?? '');
    $amount = trim((string)($input['budget_amount'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    if (!in_array($category,$categories,true) || !preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/',$month)
        || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/',$amount) || (float)$amount <= 0 || mb_strlen($notes)>500) {
        aec_fail('Enter a valid branch, category, month and positive budget amount.',422);
    }
    $monthDate = $month . '-01';
    $stmt = $db->prepare('INSERT INTO expense_monthly_budgets (branch_id,category,budget_month,budget_amount,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE budget_amount=VALUES(budget_amount),notes=VALUES(notes),updated_by=VALUES(updated_by),updated_at=NOW()');
    $stmt->execute([$branchId,$category,$monthDate,number_format((float)$amount,2,'.',''),$notes ?: null,(int)$actor['id'],(int)$actor['id']]);
    $idStmt = $db->prepare('SELECT id FROM expense_monthly_budgets WHERE branch_id=? AND category=? AND budget_month=?');
    $idStmt->execute([$branchId,$category,$monthDate]); $id=(int)$idStmt->fetchColumn();
    aec_expense_audit($db,$actor,'expense.budget_saved',$id,['branch_id'=>$branchId,'category'=>$category,'month'=>$month,'budget_amount'=>$amount]);
    aec_ok(['id'=>$id], 'Monthly budget saved.');
}

if ($action === 'recurring_list') {
    aec_method(['GET']);
    $actor = aec_expense_actor($db);
    $sql = "SELECT t.id,t.branch_id,b.name AS branch_name,t.category,t.description,t.vendor,t.supplier_id,
                   s.supplier_name,t.amount,t.day_of_month,t.is_active,t.created_at,
                   (SELECT COUNT(*) FROM expense_recurring_runs rr WHERE rr.template_id=t.id) AS generated_count
            FROM expense_recurring_templates t JOIN branches b ON b.id=t.branch_id
            LEFT JOIN expense_suppliers s ON s.id=t.supplier_id WHERE 1=1";
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND t.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') { $sql .= ' AND t.branch_id = ?'; $params[] = aec_expense_branch($db,$actor,$_GET['branch_id']); }
    $sql .= ' ORDER BY t.is_active DESC,b.name,t.description LIMIT 1000';
    $stmt=$db->prepare($sql);$stmt->execute($params);aec_ok($stmt->fetchAll());
}
if ($action === 'recurring_create') {
    aec_method(['POST']);
    $actor=aec_expense_actor($db);aec_csrf_check();$input=aec_input();
    if (!in_array($actor['role'],['admin','accountant'],true)) aec_fail('Only administrators and accountants may manage recurring expenses.',403);
    $branchId=aec_expense_branch($db,$actor,$input['branch_id']??null);
    $category=(string)($input['category']??'');$categories=['rent','utilities','salary','marketing','transport','hostel','library','supplies','other'];
    $description=trim((string)($input['description']??''));$vendor=trim((string)($input['vendor']??''));
    $amount=trim((string)($input['amount']??''));$day=filter_var($input['day_of_month']??1,FILTER_VALIDATE_INT);
    $supplierId=filter_var($input['supplier_id']??null,FILTER_VALIDATE_INT);
    if(!in_array($category,$categories,true)||$description===''||mb_strlen($description)>255||mb_strlen($vendor)>160
       ||!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/',$amount)||(float)$amount<=0||$day===false||$day<1||$day>28) aec_fail('Enter a valid category, description, amount and day from 1 to 28.',422);
    if($supplierId){$check=$db->prepare('SELECT supplier_name FROM expense_suppliers WHERE id=? AND branch_id=? AND is_active=1');$check->execute([$supplierId,$branchId]);$supplierName=$check->fetchColumn();if(!$supplierName)aec_fail('Select an active supplier from this branch.',422);$vendor=(string)$supplierName;}else $supplierId=null;
    $stmt=$db->prepare('INSERT INTO expense_recurring_templates (branch_id,category,description,vendor,supplier_id,amount,day_of_month,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$branchId,$category,$description,$vendor?:null,$supplierId,number_format((float)$amount,2,'.',''),$day,(int)$actor['id']]);
    $id=(int)$db->lastInsertId();aec_expense_audit($db,$actor,'expense.recurring_created',$id,['branch_id'=>$branchId,'category'=>$category]);
    aec_ok(['id'=>$id],'Recurring expense template created.',201);
}
if ($action === 'recurring_archive') {
    aec_method(['POST']);
    $actor=aec_expense_actor($db);aec_csrf_check();
    if(!in_array($actor['role'],['admin','accountant'],true)) aec_fail('Only administrators and accountants may archive recurring expenses.',403);
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id||$id<1)aec_fail('A valid recurring template id is required.',422);
    $stmt=$db->prepare('UPDATE expense_recurring_templates SET is_active=0,updated_at=NOW() WHERE id=?');$stmt->execute([$id]);
    if($stmt->rowCount()===0){$check=$db->prepare('SELECT id,is_active FROM expense_recurring_templates WHERE id=?');$check->execute([$id]);$row=$check->fetch();if(!$row)aec_fail('Recurring template not found.',404);}
    aec_expense_audit($db,$actor,'expense.recurring_archived',(int)$id);
    aec_ok(['id'=>(int)$id,'is_active'=>0],'Recurring template archived.');
}
if ($action === 'recurring_generate') {
    aec_method(['POST']);
    $actor=aec_expense_actor($db);aec_csrf_check();$input=aec_input();
    if(!in_array($actor['role'],['admin','accountant'],true)) aec_fail('Only administrators and accountants may generate recurring expenses.',403);
    $month=(string)($input['expense_month']??'');
    if(!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/',$month)) aec_fail('Expense month must use YYYY-MM format.',422);
    $branchId=isset($input['branch_id'])?aec_expense_branch($db,$actor,$input['branch_id']):null;
    $monthDate=$month.'-01';
    $db->beginTransaction();
    $sql='SELECT * FROM expense_recurring_templates WHERE is_active=1';$params=[];
    if($branchId!==null){$sql.=' AND branch_id=?';$params[]=$branchId;}
    $sql.=' ORDER BY id FOR UPDATE';
    $stmt=$db->prepare($sql);$stmt->execute($params);$templates=$stmt->fetchAll();
    $created=0;$skipped=0;
    try{
        $exists=$db->prepare('SELECT id FROM expense_recurring_runs WHERE template_id=? AND expense_month=?');
        $insertExpense=$db->prepare('INSERT INTO expense_records (branch_id,category,description,vendor,amount,expense_date,requested_by) VALUES (?,?,?,?,?,?,?)');
        $insertRun=$db->prepare('INSERT INTO expense_recurring_runs (template_id,expense_month,expense_id,generated_by) VALUES (?,?,?,?)');
        foreach($templates as $template){
            $exists->execute([(int)$template['id'],$monthDate]);
            if($exists->fetchColumn()){$skipped++;continue;}
            $branchCheck=$db->prepare('SELECT id FROM branches WHERE id=? AND is_active=1');$branchCheck->execute([(int)$template['branch_id']]);
            if(!$branchCheck->fetchColumn()){$skipped++;continue;}
            $expenseDate=sprintf('%s-%02d',$month,(int)$template['day_of_month']);
            $insertExpense->execute([(int)$template['branch_id'],$template['category'],$template['description'],$template['vendor'],number_format((float)$template['amount'],2,'.',''),$expenseDate,(int)$actor['id']]);
            $expenseId=(int)$db->lastInsertId();
            if (!empty($template['supplier_id'])) $db->prepare('INSERT INTO expense_record_suppliers (expense_id,supplier_id) VALUES (?,?)')->execute([$expenseId,(int)$template['supplier_id']]);
            $insertRun->execute([(int)$template['id'],$monthDate,$expenseId,(int)$actor['id']]);
            aec_expense_audit($db,$actor,'expense.recurring_generated',$expenseId,['template_id'=>(int)$template['id'],'expense_month'=>$month]);
            $created++;
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    aec_ok(['created_count'=>$created,'skipped_count'=>$skipped,'expense_month'=>$month],'Recurring expense requests generated.');
}

if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_expense_actor($db); aec_csrf_check(); $input = aec_input();
    if (!in_array($actor['role'], ['admin','accountant','branch_manager'], true)) aec_fail('You cannot submit expense requests.', 403);
    $branchId = aec_expense_branch($db, $actor, $input['branch_id'] ?? null);
    $category = (string)($input['category'] ?? 'other');
    $categories = ['rent','utilities','salary','marketing','transport','hostel','library','supplies','other'];
    $description = trim((string)($input['description'] ?? ''));
    $vendor = trim((string)($input['vendor'] ?? ''));
    $amount = trim((string)($input['amount'] ?? ''));
    if (!in_array($category, $categories, true) || $description === '' || mb_strlen($description) > 255 || mb_strlen($vendor) > 160
        || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $amount) || (float)$amount <= 0) {
        aec_fail('Enter a valid category, description, vendor and positive amount (up to two decimals).', 422);
    }
    $date = aec_expense_date($input['expense_date'] ?? date('Y-m-d'));
    $stmt = $db->prepare('INSERT INTO expense_records (branch_id, category, description, vendor, amount, expense_date, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $supplierId = filter_var($input['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    if ($supplierId) {
        $supplierStmt = $db->prepare('SELECT supplier_name FROM expense_suppliers WHERE id = ? AND branch_id = ? AND is_active = 1');
        $supplierStmt->execute([$supplierId,$branchId]); $supplierName = $supplierStmt->fetchColumn();
        if (!$supplierName) aec_fail('Select an active supplier from this branch.',422);
        $vendor = (string)$supplierName;
    } else $supplierId = null;
    $db->beginTransaction();
    try {
        $stmt->execute([$branchId, $category, $description, $vendor !== '' ? $vendor : null, number_format((float)$amount,2,'.',''), $date, (int)$actor['id']]);
        $id = (int)$db->lastInsertId();
        if ($supplierId) $db->prepare('INSERT INTO expense_record_suppliers (expense_id,supplier_id) VALUES (?,?)')->execute([$id,$supplierId]);
        aec_expense_audit($db, $actor, 'expense.requested', $id, ['branch_id'=>$branchId,'category'=>$category,'amount'=>$amount,'supplier_id'=>$supplierId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_ok(['id'=>$id], 'Expense request submitted.', 201);
}
if (in_array($action, ['approve','reject','pay'], true)) {
    aec_method(['POST']);
    $actor = aec_expense_actor($db); aec_expense_finance_role($actor); aec_csrf_check(); $input = aec_input();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid expense id is required.', 422);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM expense_records WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]); $expense = $stmt->fetch();
        if (!$expense) { $db->rollBack(); aec_fail('Expense request not found.', 404); }
        if ($action === 'approve') {
            if ($expense['status'] !== 'pending') { $db->rollBack(); aec_fail('Only pending expense requests can be approved.', 409); }
            $db->prepare("UPDATE expense_records SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([(int)$actor['id'], $id]);
            aec_expense_audit($db, $actor, 'expense.approved', (int)$id);
        } elseif ($action === 'reject') {
            if (!in_array($expense['status'], ['pending','approved'], true)) { $db->rollBack(); aec_fail('This expense request can no longer be rejected.', 409); }
            $reason = trim((string)($input['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 500) { $db->rollBack(); aec_fail('A rejection reason is required.', 422); }
            $db->prepare("UPDATE expense_records SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$reason, (int)$actor['id'], $id]);
            aec_expense_audit($db, $actor, 'expense.rejected', (int)$id, ['reason'=>$reason]);
        } else {
            if ($expense['status'] !== 'approved') { $db->rollBack(); aec_fail('Only approved expenses can be paid.', 409); }
            $method = (string)($input['payment_method'] ?? '');
            $reference = trim((string)($input['payment_reference'] ?? ''));
            $creditCode = ['cash'=>'ASSET_CASH_ON_HAND','bank_transfer'=>'ASSET_BANK_CLEARING','bKash'=>'ASSET_BKASH_NAGAD_CLEARING','Nagad'=>'ASSET_BKASH_NAGAD_CLEARING'][$method] ?? null;
            if ($creditCode === null || $reference === '' || mb_strlen($reference) > 140) { $db->rollBack(); aec_fail('Select a payment method and enter the actual payment reference.', 422); }
            $expenseCode = [
                'rent'=>'EXPENSE_RENT','utilities'=>'EXPENSE_UTILITIES','salary'=>'EXPENSE_SALARIES',
                'marketing'=>'EXPENSE_MARKETING','transport'=>'EXPENSE_TRANSPORT','hostel'=>'EXPENSE_HOSTEL',
                'library'=>'EXPENSE_LIBRARY','supplies'=>'EXPENSE_SUPPLIES','other'=>'EXPENSE_GENERAL',
            ][$expense['category']] ?? 'EXPENSE_GENERAL';
            $accountStmt = $db->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1');
            $accountStmt->execute([$expenseCode]); $debitAccount = $accountStmt->fetchColumn();
            $accountStmt->execute([$creditCode]); $creditAccount = $accountStmt->fetchColumn();
            if (!$debitAccount || !$creditAccount) { $db->rollBack(); aec_fail('Required accounting accounts are missing. Apply the latest database migrations.', 503); }
            $journalRef = 'EXP-' . $id . '-' . strtoupper(bin2hex(random_bytes(4)));
            $journal = $db->prepare('INSERT INTO expense_journal_transactions (expense_id, reference, description, created_by) VALUES (?, ?, ?, ?)');
            $journal->execute([$id, $journalRef, 'Expense payment: ' . $expense['description'], (int)$actor['id']]);
            $journalId = (int)$db->lastInsertId();
            $amountValue = number_format((float)$expense['amount'],2,'.','');
            $entry = $db->prepare('INSERT INTO expense_journal_entries (transaction_id, account_id, debit, credit, currency) VALUES (?, ?, ?, ?, ?)');
            $entry->execute([$journalId, (int)$debitAccount, $amountValue, '0.00', 'BDT']);
            $entry->execute([$journalId, (int)$creditAccount, '0.00', $amountValue, 'BDT']);
            $db->prepare("UPDATE expense_records SET status = 'paid', payment_method = ?, payment_reference = ?, paid_by = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$method, $reference, (int)$actor['id'], $id]);
            aec_expense_audit($db, $actor, 'expense.paid', (int)$id, ['journal_reference'=>$journalRef,'payment_method'=>$method,'payment_reference'=>$reference]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') aec_fail('This payment reference or journal entry already exists.', 409);
        throw $e;
    }
    aec_ok(['id'=>(int)$id,'status'=>$action === 'pay' ? 'paid' : ($action === 'approve' ? 'approved' : 'rejected')], 'Expense request updated.');
}
aec_fail('Unknown expense action.', 404);
