<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'list');

function aec_library_actor(PDO $db): array {
    $actor = aec_require_permission('library.manage');
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
function aec_library_branch(PDO $db, array $actor, mixed $requested): int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid branch is required for physical library inventory.', 422);
    $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) aec_fail('Select an active branch.', 422);
    return (int)$id;
}
function aec_library_audit(PDO $db, array $actor, string $event, string $entity, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, $entity, $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function aec_library_fine_actor(PDO $db): array {
    $actor = aec_require_permission('library.fines.manage');
    aec_assert_active_branch($db, $actor);
    return $actor;
}
function aec_library_fine_branch(array $actor, mixed $requested): ?int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    if ($requested === null || $requested === '' || $requested === 'all') return null;
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('Invalid branch filter.', 422);
    return (int)$id;
}
function aec_library_date(mixed $value): string {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) aec_fail('Due date must use YYYY-MM-DD format.', 422);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) aec_fail('Due date is invalid.', 422);
    return $date->format('Y-m-d');
}

if ($action === 'branches') {
    aec_method(['GET']);
    $actor = aec_library_actor($db);
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else {
        $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    }
    aec_ok($stmt->fetchAll());
}

if ($action === 'students') {
    aec_method(['GET']);
    $actor = aec_library_actor($db);
    $sql = "SELECT id, name, email, branch_id FROM users WHERE role = 'student' AND is_active = 1";
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id'])) { $sql .= ' AND branch_id = ?'; $params[] = aec_library_branch($db, $actor, $_GET['branch_id']); }
    $sql .= ' ORDER BY name ASC LIMIT 500';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}

if ($action === 'list') {
    aec_method(['GET']);
    $actor = aec_library_actor($db);
    $branchId = in_array($actor['role'], ['branch_manager','support_staff'], true) ? (int)$actor['branch_id'] : (isset($_GET['branch_id']) ? aec_library_branch($db, $actor, $_GET['branch_id']) : 0);
    $sql = 'SELECT b.id, b.branch_id, b.isbn, b.title, b.author, b.category, b.publisher, b.publication_year,
                   b.shelf_location, b.total_copies, b.available_copies, b.daily_fine, b.is_active,
                   (SELECT COUNT(*) FROM library_book_loans l WHERE l.book_id = b.id AND l.status = \'issued\') AS issued_copies
            FROM library_books b WHERE b.is_active = 1';
    $params = [];
    if ($branchId > 0) { $sql .= ' AND b.branch_id = ?'; $params[] = $branchId; }
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ?)';
        $term = '%' . mb_substr(trim((string)$_GET['q']), 0, 80) . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $sql .= ' ORDER BY b.title ASC LIMIT 500';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) foreach (['id','branch_id','total_copies','available_copies','issued_copies','publication_year'] as $field) {
        if ($row[$field] !== null) $row[$field] = (int)$row[$field];
    }
    unset($row);
    aec_ok($rows);
}

if ($action === 'loans') {
    aec_method(['GET']);
    $actor = aec_library_actor($db);
    $sql = 'SELECT l.id, l.book_id, l.branch_id, l.student_user_id, u.name AS student_name, u.email,
                   b.title AS book_title, b.isbn, l.issued_by, issuer.name AS issued_by_name,
                   l.issued_at, l.due_date, l.returned_at, l.daily_fine_snapshot, l.fine_amount, l.status, l.remarks
            FROM library_book_loans l JOIN users u ON u.id = l.student_user_id
            JOIN library_books b ON b.id = l.book_id JOIN users issuer ON issuer.id = l.issued_by
            WHERE 1=1';
    $params = [];
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) { $sql .= ' AND l.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    elseif (isset($_GET['branch_id'])) { $sql .= ' AND l.branch_id = ?'; $params[] = aec_library_branch($db, $actor, $_GET['branch_id']); }
    if (isset($_GET['status']) && in_array($_GET['status'], ['issued','returned','lost'], true)) { $sql .= ' AND l.status = ?'; $params[] = $_GET['status']; }
    $sql .= ' ORDER BY l.issued_at DESC, l.id DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}

if ($action === 'fine_branches') {
    aec_method(['GET']);
    $actor = aec_library_fine_actor($db);
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    aec_ok($stmt->fetchAll());
}
if ($action === 'fine_list') {
    aec_method(['GET']);
    $actor = aec_library_fine_actor($db);
    $branchId = aec_library_fine_branch($actor, $_GET['branch_id'] ?? null);
    $sql = "SELECT l.id AS loan_id, l.branch_id, br.name AS branch_name, l.student_user_id,
                   u.name AS student_name, u.email, b.title AS book_title, l.due_date, l.returned_at,
                   l.fine_amount AS assessed_amount, COALESCE(p.paid_amount,0) AS paid_amount,
                   GREATEST(l.fine_amount - COALESCE(p.paid_amount,0),0) AS outstanding_amount
            FROM library_book_loans l
            JOIN users u ON u.id = l.student_user_id
            JOIN branches br ON br.id = l.branch_id
            JOIN library_books b ON b.id = l.book_id
            LEFT JOIN (SELECT loan_id, SUM(amount) AS paid_amount FROM library_fine_payments GROUP BY loan_id) p ON p.loan_id = l.id
            WHERE l.status = 'returned' AND l.fine_amount > 0
              AND l.fine_amount > COALESCE(p.paid_amount,0)";
    $params = [];
    if ($branchId !== null) { $sql .= ' AND l.branch_id = ?'; $params[] = $branchId; }
    $sql .= ' ORDER BY l.returned_at DESC, l.id DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'fine_payments') {
    aec_method(['GET']);
    $actor = aec_library_fine_actor($db);
    $branchId = aec_library_fine_branch($actor, $_GET['branch_id'] ?? null);
    $sql = 'SELECT p.id, p.loan_id, p.branch_id, br.name AS branch_name, p.student_user_id, u.name AS student_name,
                   b.title AS book_title, p.amount, p.payment_method, p.payment_reference, p.received_at,
                   receiver.name AS received_by, j.reference AS journal_reference
            FROM library_fine_payments p
            JOIN branches br ON br.id = p.branch_id
            JOIN users u ON u.id = p.student_user_id
            JOIN library_book_loans l ON l.id = p.loan_id
            JOIN library_books b ON b.id = l.book_id
            JOIN users receiver ON receiver.id = p.received_by
            LEFT JOIN library_fine_journal_transactions j ON j.fine_payment_id = p.id
            WHERE 1=1';
    $params = [];
    if ($branchId !== null) { $sql .= ' AND p.branch_id = ?'; $params[] = $branchId; }
    $sql .= ' ORDER BY p.received_at DESC, p.id DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'fine_pay') {
    aec_method(['POST']);
    $actor = aec_library_fine_actor($db); aec_csrf_check(); $input = aec_input();
    $loanId = filter_var($input['loan_id'] ?? null, FILTER_VALIDATE_INT);
    $amountRaw = trim((string)($input['amount'] ?? ''));
    $method = (string)($input['payment_method'] ?? '');
    $reference = trim((string)($input['payment_reference'] ?? ''));
    if (!$loanId || $loanId < 1 || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/', $amountRaw)
        || (float)$amountRaw <= 0 || !in_array($method, ['cash','bank_transfer','bKash','Nagad'], true)
        || $reference === '' || mb_strlen($reference) > 140) {
        aec_fail('Enter a valid loan, positive fine payment, payment method and actual reference.', 422);
    }
    $creditCode = ['cash'=>'ASSET_CASH_ON_HAND','bank_transfer'=>'ASSET_BANK_CLEARING','bKash'=>'ASSET_BKASH_NAGAD_CLEARING','Nagad'=>'ASSET_BKASH_NAGAD_CLEARING'][$method];
    $db->beginTransaction();
    try {
        $sql = "SELECT l.id, l.branch_id, l.student_user_id, l.status, l.fine_amount, b.title AS book_title
                FROM library_book_loans l JOIN library_books b ON b.id = l.book_id
                WHERE l.id = ?" . (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND l.branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$loanId];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $loan = $stmt->fetch();
        if (!$loan) { $db->rollBack(); aec_fail('Library loan not found.', 404); }
        if ($loan['status'] !== 'returned' || (float)$loan['fine_amount'] <= 0) {
            $db->rollBack(); aec_fail('Only returned loans with an assessed late fine can receive payment.', 409);
        }
        $paidStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM library_fine_payments WHERE loan_id = ?');
        $paidStmt->execute([$loanId]); $paid = (float)$paidStmt->fetchColumn();
        $outstanding = round((float)$loan['fine_amount'] - $paid, 2);
        $amount = round((float)$amountRaw, 2);
        if ($outstanding <= 0 || $amount > $outstanding + 0.00001) {
            $db->rollBack(); aec_fail('Fine payment exceeds the outstanding balance.', 409, ['outstanding_amount'=>number_format(max(0,$outstanding),2,'.','')]);
        }
        $insert = $db->prepare('INSERT INTO library_fine_payments (loan_id, branch_id, student_user_id, amount, payment_method, payment_reference, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$loanId, (int)$loan['branch_id'], (int)$loan['student_user_id'], number_format($amount,2,'.',''), $method, $reference, (int)$actor['id']]);
        $paymentId = (int)$db->lastInsertId();
        $account = $db->prepare('SELECT id FROM accounting_accounts WHERE account_code = ? AND is_active = 1 LIMIT 1');
        $account->execute([$creditCode]); $debitAccount = $account->fetchColumn();
        $account->execute(['INCOME_LIBRARY_FINES']); $creditAccount = $account->fetchColumn();
        if (!$debitAccount || !$creditAccount) { $db->rollBack(); aec_fail('Required fine accounting accounts are missing. Apply the latest database migrations.', 503); }
        $journalRef = 'LIBFINE-' . $paymentId . '-' . strtoupper(bin2hex(random_bytes(4)));
        $db->prepare('INSERT INTO library_fine_journal_transactions (fine_payment_id, reference, description, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$paymentId, $journalRef, 'Library late fine: ' . $loan['book_title'], (int)$actor['id']]);
        $journalId = (int)$db->lastInsertId();
        $entry = $db->prepare('INSERT INTO library_fine_journal_entries (transaction_id, account_id, debit, credit, currency) VALUES (?, ?, ?, ?, ?)');
        $entry->execute([$journalId, (int)$debitAccount, number_format($amount,2,'.',''), '0.00', 'BDT']);
        $entry->execute([$journalId, (int)$creditAccount, '0.00', number_format($amount,2,'.',''), 'BDT']);
        aec_library_audit($db, $actor, 'library.fine_paid', 'library_fine_payment', $paymentId, [
            'loan_id'=>$loanId,'student_user_id'=>(int)$loan['student_user_id'],'amount'=>number_format($amount,2,'.',''),
            'payment_method'=>$method,'payment_reference'=>$reference,'journal_reference'=>$journalRef
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') aec_fail('This fine payment reference has already been recorded.', 409);
        throw $e;
    }
    aec_ok(['id'=>$paymentId,'loan_id'=>$loanId,'amount'=>number_format($amount,2,'.',''),
        'outstanding_amount'=>number_format(max(0,$outstanding-$amount),2,'.',''),'journal_reference'=>$journalRef],
        'Library fine payment received and journal posted.', 201);
}

if ($action === 'my_loans') {
    aec_method(['GET']);
    $actor = aec_current_user();
    if ($actor['role'] !== 'student') aec_fail('This endpoint is for student accounts only.', 403);
    $stmt = $db->prepare('SELECT l.id, b.title AS book_title, b.author, b.isbn, l.issued_at, l.due_date, l.returned_at, l.fine_amount, l.status,
               COALESCE(p.paid_amount,0) AS fine_paid_amount,
               GREATEST(l.fine_amount - COALESCE(p.paid_amount,0),0) AS fine_outstanding_amount
        FROM library_book_loans l JOIN library_books b ON b.id = l.book_id
        LEFT JOIN (SELECT loan_id, SUM(amount) AS paid_amount FROM library_fine_payments GROUP BY loan_id) p ON p.loan_id = l.id
        WHERE l.student_user_id = ? ORDER BY l.issued_at DESC LIMIT 200');
    $stmt->execute([(int)$actor['id']]); aec_ok($stmt->fetchAll());
}

if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_library_actor($db); aec_csrf_check(); $input = aec_input();
    $branchId = aec_library_branch($db, $actor, $input['branch_id'] ?? null);
    $title = trim((string)($input['title'] ?? ''));
    $author = trim((string)($input['author'] ?? ''));
    $isbn = trim((string)($input['isbn'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $publisher = trim((string)($input['publisher'] ?? ''));
    $shelf = trim((string)($input['shelf_location'] ?? ''));
    $copies = filter_var($input['total_copies'] ?? null, FILTER_VALIDATE_INT);
    $fine = filter_var($input['daily_fine'] ?? 0, FILTER_VALIDATE_FLOAT);
    $year = ($input['publication_year'] ?? '') === '' ? null : filter_var($input['publication_year'], FILTER_VALIDATE_INT);
    if ($title === '' || mb_strlen($title) > 180 || mb_strlen($author) > 160 || mb_strlen($isbn) > 32 || mb_strlen($category) > 120 || mb_strlen($publisher) > 160 || mb_strlen($shelf) > 80) aec_fail('Enter valid book details within the field limits.', 422);
    if ($copies === false || $copies < 1 || $copies > 5000 || $fine === false || $fine < 0 || $fine > 999999.99) aec_fail('Copies or daily fine are invalid.', 422);
    if ($year !== null && ($year === false || $year < 1000 || $year > (int)date('Y') + 1)) aec_fail('Publication year is invalid.', 422);
    try {
        $stmt = $db->prepare('INSERT INTO library_books (branch_id, isbn, title, author, category, publisher, publication_year, shelf_location, total_copies, available_copies, daily_fine, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$branchId, $isbn !== '' ? $isbn : null, $title, $author, $category, $publisher !== '' ? $publisher : null, $year, $shelf, $copies, $copies, number_format((float)$fine, 2, '.', ''), (int)$actor['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') aec_fail('This ISBN is already registered in the selected branch.', 409);
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    aec_library_audit($db, $actor, 'library.book_created', 'library_book', $id, ['branch_id' => $branchId, 'copies' => $copies]);
    aec_ok(['id' => $id], 'Book added to library.', 201);
}

if ($action === 'issue') {
    aec_method(['POST']);
    $actor = aec_library_actor($db); aec_csrf_check(); $input = aec_input();
    $bookId = filter_var($input['book_id'] ?? null, FILTER_VALIDATE_INT);
    $studentId = filter_var($input['student_user_id'] ?? null, FILTER_VALIDATE_INT);
    $dueDate = aec_library_date($input['due_date'] ?? null);
    $remarks = trim((string)($input['remarks'] ?? ''));
    if (!$bookId || $bookId < 1 || !$studentId || $studentId < 1 || mb_strlen($remarks) > 500) aec_fail('A valid book, student and optional short remark are required.', 422);
    $db->beginTransaction();
    try {
        $bookSql = 'SELECT id, branch_id, available_copies, daily_fine FROM library_books WHERE id = ? AND is_active = 1' . (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $bookStmt = $db->prepare($bookSql); $bookParams = [$bookId]; if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $bookParams[] = (int)$actor['branch_id'];
        $bookStmt->execute($bookParams); $book = $bookStmt->fetch();
        if (!$book) { $db->rollBack(); aec_fail('Book not found in your branch.', 404); }
        if ((int)$book['available_copies'] < 1) { $db->rollBack(); aec_fail('No available copies remain on the shelf.', 409); }
        $studentStmt = $db->prepare("SELECT id, branch_id FROM users WHERE id = ? AND role = 'student' AND is_active = 1 FOR UPDATE");
        $studentStmt->execute([$studentId]); $student = $studentStmt->fetch();
        if (!$student || ($student['branch_id'] !== null && (int)$student['branch_id'] !== (int)$book['branch_id'])) { $db->rollBack(); aec_fail('Student is not active or is assigned to another branch.', 422); }
        $loan = $db->prepare("INSERT INTO library_book_loans (book_id, branch_id, student_user_id, issued_by, due_date, daily_fine_snapshot, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $loan->execute([$bookId, (int)$book['branch_id'], $studentId, (int)$actor['id'], $dueDate, $book['daily_fine'], $remarks !== '' ? $remarks : null]);
        $db->prepare('UPDATE library_books SET available_copies = available_copies - 1, updated_at = NOW() WHERE id = ? AND available_copies > 0')->execute([$bookId]);
        $loanId = (int)$db->lastInsertId();
        aec_library_audit($db, $actor, 'library.book_issued', 'library_book_loan', $loanId, ['book_id' => $bookId, 'student_user_id' => $studentId, 'due_date' => $dueDate]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id' => $loanId], 'Book issued to student.', 201);
}

if ($action === 'return') {
    aec_method(['POST']);
    $actor = aec_library_actor($db); aec_csrf_check(); $input = aec_input();
    $loanId = filter_var($input['loan_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$loanId || $loanId < 1) aec_fail('A valid loan identifier is required.', 422);
    $db->beginTransaction();
    try {
        $sql = 'SELECT l.id, l.book_id, l.branch_id, l.due_date, l.daily_fine_snapshot, l.status, b.total_copies, b.available_copies
                FROM library_book_loans l JOIN library_books b ON b.id = l.book_id WHERE l.id = ?' .
                (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND l.branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$loanId]; if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $loan = $stmt->fetch();
        if (!$loan) { $db->rollBack(); aec_fail('Loan not found.', 404); }
        if ($loan['status'] !== 'issued') { $db->rollBack(); aec_fail('This loan is already closed.', 409); }
        $today = new DateTimeImmutable('today');
        $due = new DateTimeImmutable((string)$loan['due_date']);
        $lateDays = $today > $due ? (int)$due->diff($today)->days : 0;
        $fine = round($lateDays * (float)$loan['daily_fine_snapshot'], 2);
        $db->prepare("UPDATE library_book_loans SET status = 'returned', returned_at = NOW(), returned_by = ?, fine_amount = ? WHERE id = ?")
            ->execute([(int)$actor['id'], number_format($fine, 2, '.', ''), $loanId]);
        $db->prepare('UPDATE library_books SET available_copies = LEAST(total_copies, available_copies + 1), updated_at = NOW() WHERE id = ?')->execute([(int)$loan['book_id']]);
        aec_library_audit($db, $actor, 'library.book_returned', 'library_book_loan', $loanId, ['fine_amount' => $fine, 'late_days' => $lateDays]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id' => $loanId, 'fine_amount' => $fine, 'late_days' => $lateDays], 'Book returned.');
}

if ($action === 'archive') {
    aec_method(['POST']);
    $actor = aec_library_actor($db); aec_csrf_check(); $input = aec_input();
    $bookId = filter_var($input['book_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bookId || $bookId < 1) aec_fail('A valid book identifier is required.', 422);
    $sql = 'SELECT b.id, b.total_copies, b.available_copies FROM library_books b WHERE b.id = ?' .
        (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND b.branch_id = ?' : '') . ' FOR UPDATE';
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql); $params = [$bookId]; if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $book = $stmt->fetch();
        if (!$book) { $db->rollBack(); aec_fail('Book not found.', 404); }
        $activeLoans = $db->prepare("SELECT COUNT(*) FROM library_book_loans WHERE book_id = ? AND status = 'issued'");
        $activeLoans->execute([$bookId]);
        if ((int)$activeLoans->fetchColumn() > 0 || (int)$book['available_copies'] !== (int)$book['total_copies']) { $db->rollBack(); aec_fail('Return all issued copies before archiving this book.', 409); }
        $db->prepare('UPDATE library_books SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$bookId]);
        aec_library_audit($db, $actor, 'library.book_archived', 'library_book', $bookId);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id' => $bookId], 'Book archived.');
}

aec_fail('Unknown library action.', 404);
