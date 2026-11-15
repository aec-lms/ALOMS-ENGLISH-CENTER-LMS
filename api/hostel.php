<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_campus_facilities.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'rooms');

if ($action === 'branches') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'hostel.manage');
    if (in_array($actor['role'], ['branch_manager','support_staff'], true)) {
        $stmt = $db->prepare('SELECT id, name FROM branches WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$actor['branch_id']]);
    } else $stmt = $db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    aec_ok($stmt->fetchAll());
}
if ($action === 'students') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'hostel.manage');
    aec_campus_students($db, $actor, $_GET['branch_id'] ?? null);
}
if ($action === 'rooms') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'hostel.manage');
    $branchId = aec_campus_scope_branch($actor, $_GET['branch_id'] ?? null);
    $sql = "SELECT r.id, r.branch_id, r.building_name, r.room_number, r.room_type, r.monthly_rent,
                   r.capacity, r.amenities, r.warden_name, r.warden_phone, r.is_active,
                   (SELECT COUNT(*) FROM hostel_allocations a WHERE a.room_id = r.id AND a.status = 'active') AS occupied
            FROM hostel_rooms r WHERE r.is_active = 1";
    $params = [];
    if ($branchId > 0) { $sql .= ' AND r.branch_id = ?'; $params[] = $branchId; }
    $sql .= ' ORDER BY r.building_name, r.room_number LIMIT 500';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        foreach (['id','branch_id','capacity','occupied'] as $field) $row[$field] = (int)$row[$field];
        $row['amenities'] = $row['amenities'] ? (json_decode((string)$row['amenities'], true) ?: []) : [];
    }
    unset($row);
    aec_ok($rows);
}
if ($action === 'allocations') {
    aec_method(['GET']);
    $actor = aec_campus_actor($db, 'hostel.manage');
    $branchId = aec_campus_scope_branch($actor, $_GET['branch_id'] ?? null);
    $sql = 'SELECT a.id, a.room_id, a.branch_id, a.student_user_id, u.name AS student_name, u.email,
                   r.building_name, r.room_number, r.room_type, a.status, a.start_date, a.end_date,
                   a.monthly_rent_snapshot, a.remarks
            FROM hostel_allocations a JOIN users u ON u.id = a.student_user_id
            JOIN hostel_rooms r ON r.id = a.room_id WHERE 1=1';
    $params = [];
    if ($branchId > 0) { $sql .= ' AND a.branch_id = ?'; $params[] = $branchId; }
    if (isset($_GET['status']) && in_array($_GET['status'], ['active','vacated'], true)) { $sql .= ' AND a.status = ?'; $params[] = $_GET['status']; }
    $sql .= ' ORDER BY a.created_at DESC LIMIT 1000';
    $stmt = $db->prepare($sql); $stmt->execute($params); aec_ok($stmt->fetchAll());
}
if ($action === 'create_room') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'hostel.manage'); aec_csrf_check(); $input = aec_input();
    $branchId = aec_campus_branch($db, $actor, $input['branch_id'] ?? null);
    $building = trim((string)($input['building_name'] ?? ''));
    $room = strtoupper(trim((string)($input['room_number'] ?? '')));
    $type = (string)($input['room_type'] ?? 'double');
    $rent = filter_var($input['monthly_rent'] ?? 0, FILTER_VALIDATE_FLOAT);
    $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT);
    $amenities = $input['amenities'] ?? [];
    $warden = trim((string)($input['warden_name'] ?? ''));
    $phone = trim((string)($input['warden_phone'] ?? ''));
    if ($building === '' || mb_strlen($building) > 160 || $room === '' || mb_strlen($room) > 40
        || !in_array($type, ['single','double','dormitory'], true) || $rent === false || $rent < 0 || $rent > 99999999
        || $capacity === false || $capacity < 1 || $capacity > 100 || !is_array($amenities) || count($amenities) > 30
        || mb_strlen($warden) > 160 || mb_strlen($phone) > 32) aec_fail('Enter valid building, room, type, rent, capacity and amenity details.', 422);
    $normalized = [];
    foreach ($amenities as $item) {
        if (!is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 80) aec_fail('Each amenity must contain 1–80 characters.', 422);
        $normalized[] = trim($item);
    }
    try {
        $stmt = $db->prepare('INSERT INTO hostel_rooms (branch_id, building_name, room_number, room_type, monthly_rent, capacity, amenities, warden_name, warden_phone, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$branchId, $building, $room, $type, number_format((float)$rent,2,'.',''), $capacity,
            json_encode($normalized, JSON_UNESCAPED_UNICODE), $warden !== '' ? $warden : null, $phone !== '' ? $phone : null, (int)$actor['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') aec_fail('This room number already exists in the selected building and branch.', 409);
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    aec_campus_audit($db, $actor, 'hostel.room_created', 'hostel_room', $id, ['branch_id'=>$branchId,'capacity'=>$capacity]);
    aec_ok(['id'=>$id], 'Hostel room created.', 201);
}
if ($action === 'allocate') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'hostel.manage'); aec_csrf_check(); $input = aec_input();
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    $studentId = filter_var($input['student_user_id'] ?? null, FILTER_VALIDATE_INT);
    $startDate = aec_campus_date($input['start_date'] ?? date('Y-m-d'));
    $remarks = trim((string)($input['remarks'] ?? ''));
    if (!$roomId || $roomId < 1 || !$studentId || $studentId < 1 || mb_strlen($remarks) > 500) aec_fail('A valid room, student, start date and short remark are required.', 422);
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, branch_id, capacity, monthly_rent FROM hostel_rooms WHERE id = ? AND is_active = 1' .
            (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$roomId];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $room = $stmt->fetch();
        if (!$room) { $db->rollBack(); aec_fail('Hostel room not found.', 404); }
        aec_campus_student($db, $studentId, (int)$room['branch_id']);
        $count = $db->prepare("SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ? AND status = 'active'");
        $count->execute([$roomId]);
        if ((int)$count->fetchColumn() >= (int)$room['capacity']) { $db->rollBack(); aec_fail('This hostel room is full.', 409); }
        $insert = $db->prepare('INSERT INTO hostel_allocations (room_id, branch_id, student_user_id, allocated_by, start_date, monthly_rent_snapshot, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$roomId, (int)$room['branch_id'], $studentId, (int)$actor['id'], $startDate, $room['monthly_rent'], $remarks !== '' ? $remarks : null]);
        $id = (int)$db->lastInsertId();
        aec_campus_audit($db, $actor, 'hostel.student_allocated', 'hostel_allocation', $id, ['room_id'=>$roomId,'student_user_id'=>$studentId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') aec_fail('Student already has an active hostel allocation.', 409);
        throw $e;
    }
    aec_ok(['id'=>$id], 'Student allocated to hostel room.', 201);
}
if ($action === 'vacate') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'hostel.manage'); aec_csrf_check(); $input = aec_input();
    $id = filter_var($input['allocation_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid hostel allocation is required.', 422);
    $sql = 'SELECT id, status FROM hostel_allocations WHERE id = ?' .
        (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql); $params = [$id];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); aec_fail('Hostel allocation not found.', 404); }
        if ($row['status'] !== 'active') { $db->rollBack(); aec_fail('This hostel allocation is already closed.', 409); }
        $db->prepare("UPDATE hostel_allocations SET status = 'vacated', end_date = CURDATE(), updated_at = NOW() WHERE id = ?")->execute([$id]);
        aec_campus_audit($db, $actor, 'hostel.student_vacated', 'hostel_allocation', $id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id'=>$id], 'Hostel allocation closed.');
}
if ($action === 'archive_room') {
    aec_method(['POST']);
    $actor = aec_campus_actor($db, 'hostel.manage'); aec_csrf_check(); $input = aec_input();
    $id = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid room is required.', 422);
    $sql = 'SELECT id FROM hostel_rooms WHERE id = ?' . (in_array($actor['role'], ['branch_manager','support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql); $params = [$id];
        if (in_array($actor['role'], ['branch_manager','support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); if (!$stmt->fetch()) { $db->rollBack(); aec_fail('Hostel room not found.', 404); }
        $count = $db->prepare("SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ? AND status = 'active'");
        $count->execute([$id]); if ((int)$count->fetchColumn() > 0) { $db->rollBack(); aec_fail('Vacate all students before archiving this room.', 409); }
        $db->prepare('UPDATE hostel_rooms SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        aec_campus_audit($db, $actor, 'hostel.room_archived', 'hostel_room', $id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id'=>$id], 'Hostel room archived.');
}
aec_fail('Unknown hostel action.', 404);
