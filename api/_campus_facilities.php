<?php
declare(strict_types=1);

function aec_campus_actor(PDO $db, string $permission): array {
    $actor = aec_require_permission($permission);
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

function aec_campus_branch(PDO $db, array $actor, mixed $requested): int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid branch is required for campus facilities.', 422);
    $stmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) aec_fail('Select an active branch.', 422);
    return (int)$id;
}

function aec_campus_scope_branch(array $actor, mixed $requested): int {
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) return (int)$actor['branch_id'];
    if ($requested === null || $requested === '') return 0;
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('Invalid branch filter.', 422);
    return (int)$id;
}

function aec_campus_audit(PDO $db, array $actor, string $event, string $entity, int $id, array $meta = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, $entity, $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function aec_campus_student(PDO $db, int $studentId, int $branchId): array {
    $stmt = $db->prepare("SELECT id, branch_id FROM users WHERE id = ? AND role = 'student' AND is_active = 1 LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student || ($student['branch_id'] !== null && (int)$student['branch_id'] !== $branchId)) {
        aec_fail('Student is not active or is assigned to another branch.', 422);
    }
    return $student;
}

function aec_campus_date(mixed $value): string {
    if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) aec_fail('Date must use YYYY-MM-DD format.', 422);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) aec_fail('Date is invalid.', 422);
    return $date->format('Y-m-d');
}

function aec_campus_students(PDO $db, array $actor, mixed $requestedBranch): never {
    $branchId = aec_campus_scope_branch($actor, $requestedBranch);
    $sql = "SELECT id, name, email, branch_id FROM users WHERE role = 'student' AND is_active = 1";
    $params = [];
    if ($branchId > 0) { $sql .= ' AND branch_id = ?'; $params[] = $branchId; }
    $sql .= ' ORDER BY name ASC LIMIT 500';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    aec_ok($stmt->fetchAll());
}
