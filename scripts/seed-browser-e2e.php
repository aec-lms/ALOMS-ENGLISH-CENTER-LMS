<?php
declare(strict_types=1);

/**
 * Seed isolated, disposable MySQL fixtures for Playwright browser E2E.
 * Never point this script at a production database.
 */
$host = getenv('AEC_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('AEC_TEST_DB_PORT') ?: '3306';
$name = getenv('AEC_TEST_DB_NAME') ?: 'aec_test';
$user = getenv('AEC_TEST_DB_USER') ?: 'root';
$pass = getenv('AEC_TEST_DB_PASS') ?: '';
if (!str_contains($name, 'test')) {
    fwrite(STDERR, "Refusing to seed a database whose name does not contain 'test'.\n");
    exit(2);
}
$db = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$suffix = bin2hex(random_bytes(6));
$adminEmail = "e2e-admin-{$suffix}@example.test";
$studentEmail = "e2e-student-{$suffix}@example.test";
$outsiderEmail = "e2e-outsider-{$suffix}@example.test";
$adminPassword = 'E2EAdmin!Password123';
$studentPassword = 'E2EStudent!Password123';
$outsiderPassword = 'E2EOutsider!Password123';

$insertUser = $db->prepare('INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)');
$insertUser->execute(['Browser E2E Admin', $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), 'admin']);
$adminId = (int)$db->lastInsertId();
$insertUser->execute(['Browser E2E Student', $studentEmail, password_hash($studentPassword, PASSWORD_DEFAULT), 'student']);
$studentId = (int)$db->lastInsertId();
$insertUser->execute(['Browser E2E Outsider', $outsiderEmail, password_hash($outsiderPassword, PASSWORD_DEFAULT), 'student']);
$outsiderId = (int)$db->lastInsertId();
$insertUser->execute(['Browser E2E Branch Manager', "e2e-branch-manager-{$suffix}@example.test", password_hash('E2EBranch!Password123', PASSWORD_DEFAULT), 'branch_manager']);
$branchManagerId = (int)$db->lastInsertId();

$branchInsert = $db->prepare('INSERT INTO branches (code, name, city, is_active) VALUES (?, ?, ?, 1)');
$branchInsert->execute(["E2E-A-{$suffix}", 'Browser E2E Primary Branch', 'Dhaka']);
$primaryBranchId = (int)$db->lastInsertId();
$branchInsert->execute(["E2E-B-{$suffix}", 'Browser E2E Other Branch', 'Rajshahi']);
$otherBranchId = (int)$db->lastInsertId();
$db->prepare('UPDATE users SET branch_id = ? WHERE id IN (?,?)')->execute([$primaryBranchId, $studentId, $branchManagerId]);
$db->prepare('UPDATE users SET branch_id = ? WHERE id = ?')->execute([$otherBranchId, $outsiderId]);

$permissions = ['enrollments.manage', 'payments.manage', 'exams.manage', 'attendance.manage', 'certificates.manage', 'notifications.broadcast', 'cms.manage', 'storefront.orders.manage'];
$permission = $db->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES ('admin', ?, 1) ON DUPLICATE KEY UPDATE allowed = 1");
foreach ($permissions as $key) $permission->execute([$key]);

$course = $db->prepare("INSERT INTO courses (title, slug, description, price, original_price, level, duration_weeks, status, created_by, branch_id) VALUES (?, ?, ?, ?, ?, 'All Levels', 4, 'published', ?, NULL)");
$course->execute(['E2E Active Course', "e2e-active-{$suffix}", 'Course fixture for exams and attendance.', '500.00', '700.00', $adminId]);
$activeCourseId = (int)$db->lastInsertId();
$course->execute(['E2E Completed Course', "e2e-completed-{$suffix}", 'Course fixture for certificate issuance.', '0.00', '0.00', $adminId]);
$completedCourseId = (int)$db->lastInsertId();
$course->execute(['E2E Enrollment Course', "e2e-enrollment-{$suffix}", 'Course fixture for enrollment review.', '900.00', '900.00', $adminId]);
$enrollmentCourseId = (int)$db->lastInsertId();
$course->execute(['E2E Payment Course', "e2e-payment-{$suffix}", 'Course fixture for manual payment verification.', '1200.00', '1200.00', $adminId]);
$paymentCourseId = (int)$db->lastInsertId();

$enrollment = $db->prepare('INSERT INTO course_enrollments (user_id, course_id, branch_id, status, enrolled_by, completed_at) VALUES (?, ?, NULL, ?, ?, ?)');
$enrollment->execute([$studentId, $activeCourseId, 'active', $adminId, null]);
$enrollment->execute([$studentId, $completedCourseId, 'completed', $adminId, date('Y-m-d H:i:s')]);
$enrollment->execute([$studentId, $enrollmentCourseId, 'pending', $adminId, null]);
$pendingEnrollmentId = (int)$db->lastInsertId();

$exam = $db->prepare("INSERT INTO exams (course_id, title, description, duration_minutes, total_marks, pass_marks, negative_mark_per_wrong, max_attempts, status, created_by) VALUES (?, ?, ?, 10, 1, 1, 0, 1, 'published', ?)");
$exam->execute([$activeCourseId, 'E2E Server Graded Exam', 'One-question integration exam.', $adminId]);
$examId = (int)$db->lastInsertId();
$question = $db->prepare('INSERT INTO exam_questions (exam_id, question, options, correct_option_index, explanation, marks, sort_order) VALUES (?, ?, ?, 1, ?, 1, 1)');
$question->execute([$examId, 'Which word means rapid?', json_encode(['Slow', 'Fast', 'Late', 'Quiet']), 'Fast is the correct answer.']);
$questionId = (int)$db->lastInsertId();

$post = $db->prepare("INSERT INTO cms_posts (title, slug, excerpt, content, category, author, read_time_minutes, status, published_at, created_by) VALUES (?, ?, ?, ?, 'E2E', 'Browser Test', 2, 'published', NOW(), ?)");
$post->execute(["E2E Public CMS {$suffix}", "e2e-public-cms-{$suffix}", 'Published CMS fixture for browser integration.', 'This public article is seeded only in disposable test MySQL.', $adminId]);
$cmsPostId = (int)$db->lastInsertId();

$roomInsert = $db->prepare('INSERT INTO hostel_rooms (branch_id, building_name, room_number, room_type, monthly_rent, capacity, amenities, created_by) VALUES (?, ?, ?, \'single\', 1200.00, 2, ?, ?)');
$roomInsert->execute([$primaryBranchId, 'E2E Hostel', 'A-01', json_encode(['WiFi']), $adminId]);
$primaryRoomId = (int)$db->lastInsertId();
$roomInsert->execute([$otherBranchId, 'E2E Hostel', 'B-01', json_encode(['WiFi']), $adminId]);
$otherRoomId = (int)$db->lastInsertId();
$allocationInsert = $db->prepare('INSERT INTO hostel_allocations (room_id, branch_id, student_user_id, allocated_by, start_date, monthly_rent_snapshot) VALUES (?, ?, ?, ?, ?, 1200.00)');
$allocationInsert->execute([$primaryRoomId, $primaryBranchId, $studentId, $adminId, date('Y-m-01')]);
$primaryHostelAllocationId = (int)$db->lastInsertId();
$allocationInsert->execute([$otherRoomId, $otherBranchId, $outsiderId, $adminId, date('Y-m-01')]);
$otherHostelAllocationId = (int)$db->lastInsertId();

$routeInsert = $db->prepare('INSERT INTO transport_routes (branch_id, route_name, vehicle_number, driver_name, driver_phone, monthly_fee, capacity, stops, created_by) VALUES (?, ?, ?, ?, ?, 500.00, 10, ?, ?)');
$routeInsert->execute([$primaryBranchId, 'E2E Primary Route', 'E2E-A-' . strtoupper($suffix), 'E2E Driver A', '01700000001', json_encode(['Campus','Station']), $adminId]);
$primaryRouteId = (int)$db->lastInsertId();
$routeInsert->execute([$otherBranchId, 'E2E Other Route', 'E2E-B-' . strtoupper($suffix), 'E2E Driver B', '01700000002', json_encode(['Campus','Market']), $adminId]);
$otherRouteId = (int)$db->lastInsertId();
$assignmentInsert = $db->prepare('INSERT INTO transport_assignments (route_id, branch_id, student_user_id, assigned_by, start_date, monthly_fee_snapshot) VALUES (?, ?, ?, ?, ?, 500.00)');
$assignmentInsert->execute([$primaryRouteId, $primaryBranchId, $studentId, $adminId, date('Y-m-01')]);
$primaryTransportAssignmentId = (int)$db->lastInsertId();
$assignmentInsert->execute([$otherRouteId, $otherBranchId, $outsiderId, $adminId, date('Y-m-01')]);
$otherTransportAssignmentId = (int)$db->lastInsertId();

$ebookId = "e2e-ebook-{$suffix}";
$website = [
    'bkashNumber' => '01912345678',
    'nagadNumber' => '01812345679',
    'ebooks' => [[
        'id' => $ebookId,
        'title' => 'E2E Protected English Grammar PDF',
        'price' => 150,
        'isFree' => false,
        'isActive' => true,
        'description' => 'Disposable browser test e-book.',
    ]],
];
$settings = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('website', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
$settings->execute([json_encode($website, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId]);

echo json_encode([
    'admin' => ['email' => $adminEmail, 'password' => $adminPassword, 'id' => $adminId],
    'student' => ['email' => $studentEmail, 'password' => $studentPassword, 'id' => $studentId],
    'outsider' => ['email' => $outsiderEmail, 'password' => $outsiderPassword, 'id' => $outsiderId],
    'branch_manager' => ['email' => "e2e-branch-manager-{$suffix}@example.test", 'password' => 'E2EBranch!Password123', 'id' => $branchManagerId],
    'branches' => ['primary' => $primaryBranchId, 'other' => $otherBranchId],
    'facility' => ['hostel_allocation' => $primaryHostelAllocationId, 'other_hostel_allocation' => $otherHostelAllocationId, 'transport_assignment' => $primaryTransportAssignmentId, 'other_transport_assignment' => $otherTransportAssignmentId],
    'courses' => ['active' => $activeCourseId, 'completed' => $completedCourseId, 'enrollment' => $enrollmentCourseId, 'payment' => $paymentCourseId],
    'enrollment' => ['pending' => $pendingEnrollmentId],
    'exam' => ['id' => $examId, 'question_id' => $questionId],
    'cms_post_id' => $cmsPostId,
    'ebook_id' => $ebookId,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
