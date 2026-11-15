<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'list');

function aec_batch_id(): int {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid batch or routine id is required.', 422);
    return (int)$id;
}
function aec_batch_actor(PDO $db): array {
    $actor = aec_require_permission('batches.manage');
    aec_assert_active_branch($db, $actor);
    return $actor;
}
function aec_batch_branch(array $actor, mixed $requested): ?int {
    if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) {
        if (empty($actor['branch_id'])) aec_fail('Your account is not assigned to a branch.', 403);
        return (int)$actor['branch_id'];
    }
    if ($requested === null || $requested === '') aec_fail('A branch is required for every physical batch and classroom schedule.', 422);
    $id = filter_var($requested, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('Invalid branch id.', 422);
    return (int)$id;
}
function aec_batch_audit(PDO $db, array $actor, string $event, string $type, int $id, array $metadata = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$actor['id'], $event, $type, $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

if ($action === 'list') {
    aec_method(['GET']);
    $actor = aec_batch_actor($db);
    $where = 'b.is_active = 1';
    $params = [];
    if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) { $where .= ' AND b.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    $stmt = $db->prepare("SELECT b.id, b.course_id, c.title AS course_title, b.branch_id, br.name AS branch_name,
        b.instructor_id, u.name AS instructor_name, b.name, b.batch_code, b.schedule, b.room_number,
        b.max_capacity, b.start_date, b.end_date, b.status,
        (SELECT COUNT(*) FROM batch_students bs WHERE bs.batch_id = b.id AND bs.status = 'active') AS enrolled_students_count
      FROM batches b JOIN courses c ON c.id = b.course_id JOIN users u ON u.id = b.instructor_id
      LEFT JOIN branches br ON br.id = b.branch_id WHERE {$where} ORDER BY b.start_date DESC, b.id DESC");
    $stmt->execute($params);
    aec_ok($stmt->fetchAll());
}

if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db);
    aec_csrf_check();
    $input = aec_input();
    $courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
    $teacherId = filter_var($input['instructor_id'] ?? null, FILTER_VALIDATE_INT);
    $branchId = aec_batch_branch($actor, $input['branch_id'] ?? null);
    $name = trim((string)($input['name'] ?? ''));
    $code = strtoupper(trim((string)($input['batch_code'] ?? '')));
    $schedule = trim((string)($input['schedule'] ?? ''));
    $room = trim((string)($input['room_number'] ?? ''));
    $capacity = filter_var($input['max_capacity'] ?? 30, FILTER_VALIDATE_INT);
    $start = (string)($input['start_date'] ?? '');
    $end = (string)($input['end_date'] ?? '');
    $status = (string)($input['status'] ?? 'upcoming');
    if (!$courseId || $courseId < 1 || !$teacherId || $teacherId < 1) aec_fail('A valid course and instructor are required.', 422);
    if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code)) aec_fail('Enter a valid batch name and 2–40 character batch code.', 422);
    if (mb_strlen($schedule) > 255 || mb_strlen($room) > 80 || $capacity === false || $capacity < 1 || $capacity > 500) aec_fail('Invalid schedule, room or capacity.', 422);
    $date = static fn(string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false && date('Y-m-d', strtotime($value)) === $value;
    if (!$date($start) || !$date($end) || $end < $start) aec_fail('Valid start and end dates are required.', 422);
    if (!in_array($status, ['upcoming','ongoing','completed'], true)) aec_fail('Invalid batch status.', 422);
    $courseStmt = $db->prepare("SELECT id, branch_id FROM courses WHERE id = ? AND deleted_at IS NULL AND status <> 'archived'");
    $courseStmt->execute([$courseId]); $course = $courseStmt->fetch();
    if (!$course) aec_fail('Course not found.', 404);
    if ($course['branch_id'] !== null && (int)$course['branch_id'] !== (int)$branchId) aec_fail('Batch branch must match the course branch.', 422);
    if ($branchId !== null) {
        $branchStmt = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
        $branchStmt->execute([$branchId]); if (!$branchStmt->fetchColumn()) aec_fail('Select an active branch.', 422);
    }
    $teacherStmt = $db->prepare("SELECT id, branch_id FROM users WHERE id = ? AND role IN ('teacher','assistant_teacher') AND is_active = 1");
    $teacherStmt->execute([$teacherId]); $teacher = $teacherStmt->fetch();
    if (!$teacher || ($teacher['branch_id'] !== null && (int)$teacher['branch_id'] !== (int)$branchId)) aec_fail('Select an active instructor assigned to this branch.', 422);
    if (in_array($actor['role'], ['branch_manager', 'support_staff'], true) && (int)$branchId !== (int)$actor['branch_id']) aec_fail('You cannot create batches outside your branch.', 403);
    try {
        $stmt = $db->prepare('INSERT INTO batches (course_id, branch_id, instructor_id, name, batch_code, schedule, room_number, max_capacity, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$courseId, $branchId, $teacherId, $name, $code, $schedule, $room, $capacity, $start, $end, $status, (int)$actor['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') aec_fail('Batch code is already used in this branch.', 409);
        throw $e;
    }
    $id = (int)$db->lastInsertId();
    aec_batch_audit($db, $actor, 'batch.created', 'batch', $id, ['course_id' => $courseId, 'instructor_id' => $teacherId, 'branch_id' => $branchId]);
    aec_ok(['id' => $id], 'Batch created.', 201);
}

if ($action === 'deactivate') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db); aec_csrf_check(); $id = aec_batch_id();
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, branch_id FROM batches WHERE id = ? AND is_active = 1' . (in_array($actor['role'], ['branch_manager', 'support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$id]; if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $batch = $stmt->fetch();
        if (!$batch) { $db->rollBack(); aec_fail('Batch not found.', 404); }
        $activeStudents = $db->prepare("SELECT COUNT(*) FROM batch_students WHERE batch_id = ? AND status = 'active'");
        $activeStudents->execute([$id]);
        if ((int)$activeStudents->fetchColumn() > 0) { $db->rollBack(); aec_fail('Move or withdraw active students before closing this batch.', 409); }
        $db->prepare('UPDATE batches SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        aec_batch_audit($db, $actor, 'batch.deactivated', 'batch', $id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id' => $id], 'Batch closed.');
}

if ($action === 'student_assign') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db); aec_csrf_check(); $input = aec_input();
    $batchId = filter_var($input['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $studentId = filter_var($input['student_user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$batchId || $batchId < 1 || !$studentId || $studentId < 1) aec_fail('A valid batch and student are required.', 422);
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, course_id, branch_id, max_capacity FROM batches WHERE id = ? AND is_active = 1' . (in_array($actor['role'], ['branch_manager', 'support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $stmt = $db->prepare($sql); $params = [$batchId]; if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) $params[] = (int)$actor['branch_id'];
        $stmt->execute($params); $batch = $stmt->fetch();
        if (!$batch) { $db->rollBack(); aec_fail('Batch not found.', 404); }
        $student = $db->prepare("SELECT id, branch_id FROM users WHERE id = ? AND role = 'student' AND is_active = 1");
        $student->execute([$studentId]); $studentRow = $student->fetch();
        if (!$studentRow || ($studentRow['branch_id'] !== null && (int)$studentRow['branch_id'] !== (int)$batch['branch_id'])) { $db->rollBack(); aec_fail('Student is not active or is assigned to another branch.', 422); }
        $enrollment = $db->prepare("SELECT id FROM course_enrollments WHERE user_id = ? AND course_id = ? AND status IN ('active','completed') LIMIT 1");
        $enrollment->execute([$studentId, (int)$batch['course_id']]);
        if (!$enrollment->fetchColumn()) { $db->rollBack(); aec_fail('Student must have an active or completed enrollment in this course.', 409); }
        $count = $db->prepare("SELECT COUNT(*) FROM batch_students WHERE batch_id = ? AND status = 'active'");
        $count->execute([$batchId]);
        $existing = $db->prepare('SELECT status FROM batch_students WHERE batch_id = ? AND student_user_id = ? FOR UPDATE');
        $existing->execute([$batchId, $studentId]); $existingStatus = $existing->fetchColumn();
        if ($existingStatus !== 'active' && (int)$count->fetchColumn() >= (int)$batch['max_capacity']) { $db->rollBack(); aec_fail('Batch capacity has been reached.', 409); }
        $insert = $db->prepare("INSERT INTO batch_students (batch_id, student_user_id, status) VALUES (?, ?, 'active')
          ON DUPLICATE KEY UPDATE status = 'active', enrolled_at = NOW(), updated_at = NOW()");
        $insert->execute([$batchId, $studentId]);
        aec_batch_audit($db, $actor, 'batch.student_assigned', 'batch', (int)$batchId, ['student_user_id' => $studentId]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['batch_id' => (int)$batchId, 'student_user_id' => (int)$studentId], 'Student assigned to batch.');
}

if ($action === 'student_withdraw') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db); aec_csrf_check(); $input = aec_input();
    $batchId = filter_var($input['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $studentId = filter_var($input['student_user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$batchId || !$studentId) aec_fail('A valid batch and student are required.', 422);
    $sql = "UPDATE batch_students bs JOIN batches b ON b.id = bs.batch_id SET bs.status = 'withdrawn', bs.updated_at = NOW()
      WHERE bs.batch_id = ? AND bs.student_user_id = ? AND bs.status = 'active' AND b.is_active = 1" . (in_array($actor['role'], ['branch_manager', 'support_staff'], true) ? ' AND b.branch_id = ?' : '');
    $params = [$batchId, $studentId]; if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) $params[] = (int)$actor['branch_id'];
    $stmt = $db->prepare($sql); $stmt->execute($params);
    if ($stmt->rowCount() !== 1) aec_fail('Active batch assignment not found.', 404);
    aec_batch_audit($db, $actor, 'batch.student_withdrawn', 'batch', (int)$batchId, ['student_user_id' => (int)$studentId]);
    aec_ok(['batch_id' => (int)$batchId, 'student_user_id' => (int)$studentId], 'Student withdrawn from batch.');
}

if ($action === 'routines') {
    aec_method(['GET']);
    $actor = aec_batch_actor($db);
    $where = 'b.is_active = 1'; $params = [];
    if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) { $where .= ' AND s.branch_id = ?'; $params[] = (int)$actor['branch_id']; }
    $stmt = $db->prepare("SELECT s.id, s.batch_id, b.name AS batch_name, c.title AS course_title,
        s.branch_id, br.name AS branch_name, s.instructor_id, u.name AS instructor_name,
        s.day_of_week, TIME_FORMAT(s.start_time, '%H:%i') AS start_time,
        TIME_FORMAT(s.end_time, '%H:%i') AS end_time, s.subject, s.room_number
      FROM class_routine_slots s JOIN batches b ON b.id = s.batch_id
      JOIN courses c ON c.id = b.course_id JOIN users u ON u.id = s.instructor_id
      LEFT JOIN branches br ON br.id = s.branch_id WHERE {$where}
      ORDER BY FIELD(s.day_of_week,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), s.start_time");
    $stmt->execute($params); aec_ok($stmt->fetchAll());
}

if ($action === 'routine_create') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db); aec_csrf_check(); $input = aec_input();
    $batchId = filter_var($input['batch_id'] ?? null, FILTER_VALIDATE_INT);
    $instructorId = filter_var($input['instructor_id'] ?? null, FILTER_VALIDATE_INT);
    $day = (string)($input['day_of_week'] ?? '');
    $start = (string)($input['start_time'] ?? '');
    $end = (string)($input['end_time'] ?? '');
    $subject = trim((string)($input['subject'] ?? ''));
    $room = trim((string)($input['room_number'] ?? ''));
    if (!$batchId || $batchId < 1 || !in_array($day, ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], true)) aec_fail('Select a valid batch and weekday.', 422);
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start) aec_fail('Use 24-hour time and ensure the end time is later than the start time.', 422);
    if ($subject === '' || mb_strlen($subject) > 180 || $room === '' || mb_strlen($room) > 80) aec_fail('A subject and valid room are required.', 422);
    $db->beginTransaction();
    try {
        $batchSql = 'SELECT id, branch_id, instructor_id, room_number FROM batches WHERE id = ? AND is_active = 1' . (in_array($actor['role'], ['branch_manager', 'support_staff'], true) ? ' AND branch_id = ?' : '') . ' FOR UPDATE';
        $batchStmt = $db->prepare($batchSql); $batchParams = [$batchId]; if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) $batchParams[] = (int)$actor['branch_id'];
        $batchStmt->execute($batchParams); $batch = $batchStmt->fetch();
        if (!$batch) { $db->rollBack(); aec_fail('Batch not found.', 404); }
        $instructorId = $instructorId ?: (int)$batch['instructor_id'];
        $instructorLock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $instructorLock->execute([$instructorId]);
        if ($batch['branch_id'] !== null) {
            $branchLock = $db->prepare('SELECT id FROM branches WHERE id = ? FOR UPDATE');
            $branchLock->execute([(int)$batch['branch_id']]);
        }
        $teacherStmt = $db->prepare("SELECT id, branch_id FROM users WHERE id = ? AND role IN ('teacher','assistant_teacher') AND is_active = 1");
        $teacherStmt->execute([$instructorId]); $routineTeacher = $teacherStmt->fetch();
        if (!$routineTeacher || ($routineTeacher['branch_id'] !== null && (int)$routineTeacher['branch_id'] !== (int)$batch['branch_id'])) { $db->rollBack(); aec_fail('Select an active instructor assigned to this branch.', 422); }
        $conflict = $db->prepare("SELECT s.id, s.subject FROM class_routine_slots s JOIN batches b ON b.id = s.batch_id
          WHERE b.is_active = 1 AND s.day_of_week = ? AND s.start_time < ? AND s.end_time > ?
            AND (s.instructor_id = ? OR (s.branch_id <=> ? AND LOWER(s.room_number) = LOWER(?))) LIMIT 1 FOR UPDATE");
        $conflict->execute([$day, $end, $start, $instructorId, $batch['branch_id'], $room]);
        if ($conflict->fetch()) { $db->rollBack(); aec_fail('This instructor or room is already booked during the selected time.', 409); }
        $stmt = $db->prepare('INSERT INTO class_routine_slots (batch_id, branch_id, instructor_id, day_of_week, start_time, end_time, subject, room_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$batchId, $batch['branch_id'], $instructorId, $day, $start, $end, $subject, $room, (int)$actor['id']]);
        $id = (int)$db->lastInsertId();
        aec_batch_audit($db, $actor, 'routine.created', 'class_routine_slot', $id, ['batch_id' => $batchId, 'day' => $day, 'start' => $start, 'end' => $end]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    aec_ok(['id' => $id], 'Routine slot created.', 201);
}

if ($action === 'routine_delete') {
    aec_method(['POST']);
    $actor = aec_batch_actor($db); aec_csrf_check(); $id = aec_batch_id();
    $sql = 'SELECT s.id FROM class_routine_slots s JOIN batches b ON b.id = s.batch_id WHERE s.id = ?' . (in_array($actor['role'], ['branch_manager', 'support_staff'], true) ? ' AND s.branch_id = ?' : '');
    $stmt = $db->prepare($sql); $params = [$id]; if (in_array($actor['role'], ['branch_manager', 'support_staff'], true)) $params[] = (int)$actor['branch_id'];
    $stmt->execute($params); if (!$stmt->fetchColumn()) aec_fail('Routine slot not found.', 404);
    $db->prepare('DELETE FROM class_routine_slots WHERE id = ?')->execute([$id]);
    aec_batch_audit($db, $actor, 'routine.deleted', 'class_routine_slot', $id);
    aec_ok(['id' => $id], 'Routine slot deleted.');
}

aec_fail('Unknown batch/routine action.', 404);
