<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_campus_facilities.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'list');

if ($action === 'branches') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'transport.manage');
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    aec_ok($stmt->fetchAll());
}
if ($action === 'students') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'transport.manage');
    aec_campus_students($db, $actor, $_GET['branch_id'] ?? null);
}
if ($action === 'list') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'transport.manage');
    $branchId = aec_campus_scope_branch($actor, $_GET['branch_id'] ?? null);
    $sql = "SELECT r.id, r.branch_id, r.route_name, r.route_name_bn, r.vehicle_number, r.driver_name,
                   r.driver_phone, r.monthly_fee, r.capacity, r.stops, r.is_active,
                   (SELECT COUNT(*) FROM transport_assignments a WHERE a.route_id = r.id AND a.status = 'active') AS active_passengers
            FROM transport_routes r WHERE r.is_active = 1";
    $params = [];
    if ($branchId > 0) { $sql .= ' AND r.branch_id = ?'; $params[] = $branchId; }
    $sql .= ' ORDER BY r.route_name LIMIT 500';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        foreach (['id','branch_id','capacity','active_passengers'] as $field) $row[$field] = (int)$row[$field];
        $row['stops'] = json_decode((string)$row['stops'], true) ?: [];
    }
    unset($row);
    aec_ok($rows);
}
if ($action === 'assignments') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'transport.manage');
    $branchId = aec_campus_scope_branch($actor, $_GET['branch_id'] ?? null);
    $sql = 'SELECT a.id, a.route_id, a.branch_id, a.student_user_id, u.name AS student_name, u.email,
                   r.route_name, r.vehicle_number, a.status, a.start_date, a.end_date, a.monthly_fee_snapshot
            FROM transport_assignments a JOIN users u ON u.id = a.student_user_id
            JOIN transport_routes r ON r.id = a.route_id WHERE 1=1';
    $params = [];
    if ($branchId > 0) { $sql .= ' AND a.branch_id = ?'; $params[] = $branchId; }
    if (isset($_GET['status']) && in_array($_GET['status'], ['active','ended'], true)) { $sql .= ' AND a.status = ?'; $params[] = $_GET['status']; }
    $sql .= ' ORDER BY a.created_at DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'transport.manage'); aec_csrf_check(); $input = aec_input();
    $branchId = aec_campus_branch($db, $actor, $input['branch_id'] ?? null);
    $name = trim((string)($input['route_name'] ?? ''));
    $nameBn = trim((string)($input['route_name_bn'] ?? ''));
    $vehicle = strtoupper(trim((string)($input['vehicle_number'] ?? '')));
    $driver = trim((string)($input['driver_name'] ?? ''));
    $phone = trim((string)($input['driver_phone'] ?? ''));
    $fee = filter_var($input['monthly_fee'] ?? 0, FILTER_VALIDATE_FLOAT);
    $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT);
    $stops = $input['stops'] ?? null;
    if ($name === '' || mb_strlen($name) > 160 || mb_strlen($nameBn) > 160 || !preg_match('/^[A-Z0-9 -]{2,40}$/', $vehicle)
        || $driver === '' || mb_strlen($driver) > 160 || $phone === '' || mb_strlen($phone) > 32
        || $fee === false || $fee < 0 || $fee > 99999999 || $capacity === false || $capacity < 1 || $capacity > 200
        || !is_array($stops) || count($stops) < 1 || count($stops) > 30) aec_fail('Enter valid route, vehicle, driver, fee, capacity and stop details.', 422);
    $normalizedStops = [];
    foreach ($stops as $stop) {
        if (!is_string($stop) || trim($stop) === '' || mb_strlen(trim($stop)) > 120) aec_fail('Each route stop must contain 1–120 characters.', 422);
        $normalizedStops[] = trim($stop);
    }
    try {
        $stmt = $db->prepare('INSERT INTO transport_routes (branch_id, route_name, route_name_bn, vehicle_number, driver_name, driver_phone, monthly_fee, capacity, stops, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$branchId, $name, $nameBn !== '' ? $nameBn : null, $vehicle, $driver, $phone, number_format((float)$fee,2,'.',''), $capacity,
            json_encode($normalizedStops, JSON_UNESCAPED_UNICODE), (int)$actor['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') aec_fail('This vehicle number is already registered in the selected branch.', 409);
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    aec_campus_audit($db, $actor, 'transport.route_created', 'transport_route', $id, ['branch_id'=>$branchId,'capacity'=>$capacity]);
    aec_ok(['id'=>$id], 'Transport route created.', 201);
}
if ($action === 'assign') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'transport.manage'); aec_csrf_check(); $input = aec_input();
    $routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT);
    $studentId = filter_var($input['student_user_id'] ?? null, FILTER_VALIDATE_INT);
    $startDate = aec_campus_date($input['start_date'] ?? date('Y-m-d'));
    if (!$routeId || $routeId < 1 || !$studentId || $studentId < 1) aec_fail('A valid route, student and start date are required.', 422);
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, branch_id, capacity, monthly_fee FROM transport_routes WHERE id = ? AND is_active = 1' .
            (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$routeId];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $route = $stmt->fetch();
        if (!$route) { $db->rollBack(); aec_fail('Transport route not found.', 404); }
        aec_campus_student($db, $studentId, (int)$route['branch_id']);
        $count = $db->prepare("SELECT COUNT(*) FROM transport_assignments WHERE route_id = ? AND status = 'active'");
        $count->execute([$routeId]);
        if ((int)$count->fetchColumn() >= (int)$route['capacity']) { $db->rollBack(); aec_fail('This route has reached its passenger capacity.', 409); }
        $insert = $db->prepare("INSERT INTO transport_assignments (route_id, branch_id, student_user_id, assigned_by, start_date, monthly_fee_snapshot) VALUES (?, ?, ?, ?, ?, ?)");
        $insert->execute([$routeId, (int)$route['branch_id'], $studentId, (int)$actor['id'], $startDate, $route['monthly_fee']]);
        $id = (int)$db->lastInsertId();
        aec_campus_audit($db, $actor, 'transport.student_assigned', 'transport_assignment', $id, ['route_id'=>$routeId,'student_user_id'=>$studentId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') aec_fail('Student already has an active transport assignment.', 409);
        throw $e;
    }
    aec_ok(['id'=>$id], 'Student assigned to transport route.', 201);
}
if ($action === 'end') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'transport.manage'); aec_csrf_check(); $input = aec_input();
    $id = filter_var($input['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid transport assignment is required.', 422);
    $sql = 'SELECT id, branch_id, status FROM transport_assignments WHERE id = ?' .
        (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql); $params = [$id];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); aec_fail('Transport assignment not found.', 404); }
        if ($row['status'] !== 'active') { $db->rollBack(); aec_fail('Transport assignment is already ended.', 409); }
        $db->prepare("UPDATE transport_assignments SET status = 'ended', end_date = CURDATE(), updated_at = NOW() WHERE id = ?")->execute([$id]);
        aec_campus_audit($db, $actor, 'transport.assignment_ended', 'transport_assignment', $id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id'=>$id], 'Transport assignment ended.');
}
if ($action === 'archive') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'transport.manage'); aec_csrf_check(); $input = aec_input();
    $id = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid route is required.', 422);
    $sql = 'SELECT id FROM transport_routes WHERE id = ?' . (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql); $params = [$id];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); if (!$stmt->fetch()) { $db->rollBack(); aec_fail('Transport route not found.', 404); }
        $count = $db->prepare("SELECT COUNT(*) FROM transport_assignments WHERE route_id = ? AND status = 'active'");
        $count->execute([$id]); if ((int)$count->fetchColumn() > 0) { $db->rollBack(); aec_fail('End active passenger assignments before archiving this route.', 409); }
        $db->prepare('UPDATE transport_routes SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        aec_campus_audit($db, $actor, 'transport.route_archived', 'transport_route', $id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id'=>$id], 'Transport route archived.');
}
aec_fail('Unknown transport action.', 404);
