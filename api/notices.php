<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'visible');

function aec_notice_admin(): array {
    $actor = aec_current_user();
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can manage the notice board.', 403);
    return $actor;
}

function aec_notice_audit(PDO $db, array $actor, string $event, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, 'notice_board_item', $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

if ($action === 'visible') {
    aec_method(['GET']);
    $actor = aec_current_user();
    $db->exec("UPDATE notice_board_items SET status = 'archived' WHERE status IN ('published','scheduled') AND ends_at IS NOT NULL AND ends_at <= NOW()");
    $db->exec("UPDATE notice_board_items SET status = 'published' WHERE status = 'scheduled' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW())");
    $stmt = $db->prepare("SELECT n.id, n.title, n.body, n.audience_role, n.branch_id, n.starts_at, n.ends_at, n.created_at,
        CASE WHEN r.user_id IS NULL THEN 0 ELSE 1 END AS is_read
        FROM notice_board_items n
        LEFT JOIN notice_board_reads r ON r.notice_id = n.id AND r.user_id = ?
        WHERE n.status IN ('published','scheduled')
          AND (n.starts_at IS NULL OR n.starts_at <= NOW())
          AND (n.ends_at IS NULL OR n.ends_at > NOW())
          AND (n.audience_role = 'all' OR n.audience_role = ?)
          AND (n.branch_id IS NULL OR n.branch_id = ? OR ? = 'admin')
        ORDER BY n.created_at DESC, n.id DESC LIMIT 100");
    $stmt->execute([(int)$actor['id'], (string)$actor['role'], $actor['branch_id'] ?? null, (string)$actor['role']]);
    aec_ok($stmt->fetchAll());
}

if ($action === 'list') {
    aec_method(['GET']);
    $db->exec("UPDATE notice_board_items SET status = 'archived' WHERE status IN ('published','scheduled') AND ends_at IS NOT NULL AND ends_at <= NOW()");
    $db->exec("UPDATE notice_board_items SET status = 'published' WHERE status = 'scheduled' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW())");

    aec_notice_admin();
    $stmt = $db->query("SELECT n.*,
        (SELECT COUNT(*) FROM notice_board_reads r WHERE r.notice_id = n.id) AS read_count
        FROM notice_board_items n ORDER BY n.created_at DESC, n.id DESC LIMIT 500");
    aec_ok($stmt->fetchAll());
}

if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_notice_admin();
    aec_csrf_check();
    $input = aec_input();
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $audience = (string)($input['audience_role'] ?? 'all');
    $branchId = isset($input['branch_id']) && $input['branch_id'] !== '' ? filter_var($input['branch_id'], FILTER_VALIDATE_INT) : null;
    $start = trim((string)($input['starts_at'] ?? ''));
    $end = trim((string)($input['ends_at'] ?? ''));
    if ($title === '' || mb_strlen($title) > 180) aec_fail('Notice title is required (maximum 180 characters).', 422);
    if ($body === '' || mb_strlen($body) > 20000) aec_fail('Notice body is required (maximum 20,000 characters).', 422);
    if ($audience !== 'all' && !in_array($audience, ['admin','branch_manager','teacher','assistant_teacher','accountant','student','guardian','support_staff'], true)) aec_fail('Invalid notice audience.', 422);
    if ($branchId !== null && (!$branchId || $branchId < 1)) aec_fail('Invalid branch.', 422);
    if ($branchId !== null) {
        $check = $db->prepare('SELECT id FROM branches WHERE id = ?');
        $check->execute([$branchId]);
        if (!$check->fetchColumn()) aec_fail('Branch not found.', 404);
    }
    $startDate = $start === '' ? null : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start);
    $endDate = $end === '' ? null : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end);
    if (($start !== '' && !$startDate) || ($end !== '' && !$endDate) || ($startDate && $endDate && $endDate <= $startDate)) {
        aec_fail('Invalid notice schedule. Use YYYY-MM-DD HH:MM:SS and ensure end is later than start.', 422);
    }
    $status = $startDate && $startDate > new DateTimeImmutable('now') ? 'scheduled' : 'published';
    $insert = $db->prepare('INSERT INTO notice_board_items (title, body, audience_role, branch_id, status, starts_at, ends_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$title, $body, $audience, $branchId, $status, $startDate?->format('Y-m-d H:i:s'), $endDate?->format('Y-m-d H:i:s'), (int)$actor['id']]);
    $id = (int)$db->lastInsertId();
    aec_notice_audit($db, $actor, 'notice.created', $id, ['audience_role' => $audience, 'branch_id' => $branchId]);
    aec_ok(['id' => $id, 'status' => $status], 'Notice published or scheduled.', 201);
}

if ($action === 'update') {
    aec_method(['POST']);
    $actor = aec_notice_admin();
    aec_csrf_check();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $input = aec_input();
    if (!$id || $id < 1) aec_fail('A valid notice ID is required.', 422);
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $audience = (string)($input['audience_role'] ?? 'all');
    $branchId = isset($input['branch_id']) && $input['branch_id'] !== '' ? filter_var($input['branch_id'], FILTER_VALIDATE_INT) : null;
    $start = trim((string)($input['starts_at'] ?? ''));
    $end = trim((string)($input['ends_at'] ?? ''));
    if ($title === '' || mb_strlen($title) > 180) aec_fail('Notice title is required (maximum 180 characters).', 422);
    if ($body === '' || mb_strlen($body) > 20000) aec_fail('Notice body is required (maximum 20,000 characters).', 422);
    if ($audience !== 'all' && !in_array($audience, ['admin','branch_manager','teacher','assistant_teacher','accountant','student','guardian','support_staff'], true)) aec_fail('Invalid notice audience.', 422);
    if ($branchId !== null && (!$branchId || $branchId < 1)) aec_fail('Invalid branch.', 422);
    if ($branchId !== null) {
        $check = $db->prepare('SELECT id FROM branches WHERE id = ?');
        $check->execute([$branchId]);
        if (!$check->fetchColumn()) aec_fail('Branch not found.', 404);
    }
    $startDate = $start === '' ? null : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start);
    $endDate = $end === '' ? null : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end);
    if (($start !== '' && !$startDate) || ($end !== '' && !$endDate) || ($startDate && $endDate && $endDate <= $startDate)) {
        aec_fail('Invalid notice schedule. Use YYYY-MM-DD HH:MM:SS and ensure end is later than start.', 422);
    }
    $current = $db->prepare('SELECT status FROM notice_board_items WHERE id = ?');
    $current->execute([$id]);
    $currentStatus = $current->fetchColumn();
    if (!$currentStatus) aec_fail('Notice not found.', 404);
    $status = in_array($currentStatus, ['published','scheduled'], true)
        ? ($startDate && $startDate > new DateTimeImmutable('now') ? 'scheduled' : 'published')
        : $currentStatus;
    $stmt = $db->prepare('UPDATE notice_board_items SET title = ?, body = ?, audience_role = ?, branch_id = ?, status = ?, starts_at = ?, ends_at = ? WHERE id = ?');
    $stmt->execute([$title, $body, $audience, $branchId, $status, $startDate?->format('Y-m-d H:i:s'), $endDate?->format('Y-m-d H:i:s'), $id]);
    aec_notice_audit($db, $actor, 'notice.updated', $id, ['audience_role' => $audience, 'branch_id' => $branchId]);
    aec_ok(['id' => $id, 'status' => $status], 'Notice updated.');
}

if ($action === 'status') {
    aec_method(['POST']);
    $actor = aec_notice_admin();
    aec_csrf_check();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $input = aec_input();
    $status = (string)($input['status'] ?? '');
    if (!$id || $id < 1 || !in_array($status, ['draft','published','archived'], true)) aec_fail('Invalid notice status.', 422);
    $stmt = $db->prepare('UPDATE notice_board_items SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    if (!$stmt->rowCount()) {
        $check = $db->prepare('SELECT id FROM notice_board_items WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) aec_fail('Notice not found.', 404);
    }
    aec_notice_audit($db, $actor, 'notice.status_changed', $id, ['status' => $status]);
    aec_ok(['id' => $id, 'status' => $status]);
}

if ($action === 'mark_read') {
    aec_method(['POST']);
    $actor = aec_current_user();
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['notice_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid notice id is required.', 422);
    $stmt = $db->prepare("INSERT IGNORE INTO notice_board_reads (notice_id, user_id)
        SELECT id, ? FROM notice_board_items
        WHERE id = ? AND status IN ('published','scheduled')
          AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at > NOW())
          AND (audience_role = 'all' OR audience_role = ?)
          AND (branch_id IS NULL OR branch_id = ? OR ? = 'admin')");
    $stmt->execute([(int)$actor['id'], $id, (string)$actor['role'], $actor['branch_id'] ?? null, (string)$actor['role']]);
    $verify = $db->prepare('SELECT notice_id FROM notice_board_reads WHERE notice_id = ? AND user_id = ?');
    $verify->execute([$id, (int)$actor['id']]);
    if (!$verify->fetchColumn()) aec_fail('Notice not found or not available to your account.', 404);
    aec_ok(['notice_id' => $id, 'read' => true]);
}

aec_fail('Unknown notice board action.', 404);
