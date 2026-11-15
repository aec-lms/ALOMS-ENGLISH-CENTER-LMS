<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? 'active');

function aec_promotion_admin(PDO $db): array {
    $actor = aec_current_user();
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can manage promotion campaigns.', 403);
    return $actor;
}

if ($action === 'active') {
    aec_method(['GET']);
    $db->exec("UPDATE promotion_campaigns SET status = 'completed' WHERE status IN ('active','scheduled') AND ends_at <= NOW()");
    $db->exec("UPDATE promotion_campaigns SET status = 'active' WHERE status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()");
    $placement = (string)($_GET['placement'] ?? 'homepage_featured');
    if (!in_array($placement, ['homepage_featured','course_listing','branch_directory','notice_banner'], true)) aec_fail('Invalid promotion placement.', 422);
    $stmt = $db->prepare("SELECT id, campaign_name, entity_type, entity_id, placement, priority, destination_url
        FROM promotion_campaigns
        WHERE status = 'active' AND placement = ? AND starts_at <= NOW() AND ends_at > NOW()
          AND (
            entity_type = 'institution'
            OR (entity_type = 'course' AND EXISTS (SELECT 1 FROM courses c WHERE c.id = promotion_campaigns.entity_id AND c.status = 'published' AND c.deleted_at IS NULL))
            OR (entity_type = 'branch' AND EXISTS (SELECT 1 FROM branches b WHERE b.id = promotion_campaigns.entity_id AND b.is_active = 1))
          )
        ORDER BY priority DESC, starts_at ASC, id DESC LIMIT 30");
    $stmt->execute([$placement]);
    aec_ok($stmt->fetchAll());
}

if ($action === 'track') {
    aec_method(['POST']);
    $input = aec_input();
    $id = filter_var($input['campaign_id'] ?? null, FILTER_VALIDATE_INT);
    $event = (string)($input['event_type'] ?? '');
    if (!$id || $id < 1 || !in_array($event, ['impression','click'], true)) aec_fail('Invalid campaign event.', 422);
    $stmt = $db->prepare("SELECT id FROM promotion_campaigns WHERE id = ? AND status = 'active' AND starts_at <= NOW() AND ends_at > NOW()");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) aec_fail('Campaign is not currently active.', 404);
    $actorId = null;
    if (!empty($_SESSION['user_id'])) {
        $actor = aec_current_user();
        $actorId = (int)$actor['id'];
    }
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $visitorHash = hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d'));
    $recent = $db->prepare("SELECT id FROM promotion_campaign_events WHERE campaign_id = ? AND event_type = ? AND visitor_hash = ? AND occurred_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
    $recent->execute([$id, $event, $visitorHash]);
    if (!$recent->fetchColumn()) {
        $db->prepare('INSERT INTO promotion_campaign_events (campaign_id, event_type, visitor_hash, user_id) VALUES (?, ?, ?, ?)')
            ->execute([$id, $event, $visitorHash, $actorId]);
    }
    aec_ok(['tracked' => true]);
}

if ($action === 'list') {
    aec_method(['GET']);
    $db->exec("UPDATE promotion_campaigns SET status = 'completed' WHERE status IN ('active','scheduled') AND ends_at <= NOW()");
    aec_promotion_admin($db);
    $stmt = $db->query("SELECT c.*, 
        (SELECT COUNT(*) FROM promotion_campaign_events e WHERE e.campaign_id = c.id AND e.event_type = 'impression') AS impressions,
        (SELECT COUNT(*) FROM promotion_campaign_events e WHERE e.campaign_id = c.id AND e.event_type = 'click') AS clicks,
        (SELECT COUNT(*) FROM promotion_campaign_events e WHERE e.campaign_id = c.id AND e.event_type = 'conversion') AS conversions
        FROM promotion_campaigns c ORDER BY c.created_at DESC, c.id DESC LIMIT 500");
    aec_ok($stmt->fetchAll());
}

if ($action === 'create') {
    aec_method(['POST']);
    $actor = aec_promotion_admin($db);
    aec_csrf_check();
    $input = aec_input();
    $name = trim((string)($input['campaign_name'] ?? ''));
    $entityType = (string)($input['entity_type'] ?? '');
    $entityId = isset($input['entity_id']) && $input['entity_id'] !== '' ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
    $placement = (string)($input['placement'] ?? 'homepage_featured');
    $start = trim((string)($input['starts_at'] ?? ''));
    $end = trim((string)($input['ends_at'] ?? ''));
    $priority = filter_var($input['priority'] ?? 1, FILTER_VALIDATE_INT);
    $url = trim((string)($input['destination_url'] ?? ''));
    if ($name === '' || mb_strlen($name) > 180) aec_fail('Campaign name is required (maximum 180 characters).', 422);
    if (!in_array($entityType, ['course','institution','branch'], true)) aec_fail('Invalid promotion target.', 422);
    if ($entityType !== 'institution' && (!$entityId || $entityId < 1)) aec_fail('A valid course or branch ID is required.', 422);
    if (!in_array($placement, ['homepage_featured','course_listing','branch_directory','notice_banner'], true)) aec_fail('Invalid placement.', 422);
    $startDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start);
    $endDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end);
    if (!$startDate || !$endDate || $endDate <= $startDate) aec_fail('Campaign end time must be later than start time. Use YYYY-MM-DD HH:MM:SS.', 422);
    if ($priority === false || $priority < 1 || $priority > 10) aec_fail('Priority must be between 1 and 10.', 422);
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['https','http'], true))) aec_fail('Destination URL must be a valid HTTP or HTTPS URL.', 422);
    if ($entityType === 'course') {
        $check = $db->prepare('SELECT id FROM courses WHERE id = ? AND deleted_at IS NULL');
        $check->execute([$entityId]);
        if (!$check->fetchColumn()) aec_fail('Course not found.', 404);
    } elseif ($entityType === 'branch') {
        $check = $db->prepare('SELECT id FROM branches WHERE id = ?');
        $check->execute([$entityId]);
        if (!$check->fetchColumn()) aec_fail('Branch not found.', 404);
    }
    $status = $startDate > new DateTimeImmutable('now') ? 'scheduled' : 'active';
    $insert = $db->prepare('INSERT INTO promotion_campaigns (campaign_name, entity_type, entity_id, placement, status, starts_at, ends_at, priority, destination_url, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$name, $entityType, $entityId, $placement, $status, $startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s'), $priority, $url ?: null, (int)$actor['id']]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'promotion.created', 'promotion_campaign', ?, ?, ?)")
        ->execute([(int)$actor['id'], $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['entity_type' => $entityType, 'placement' => $placement])]);
    aec_ok(['id' => $id, 'status' => $status], 'Promotion campaign created.', 201);
}

if ($action === 'status') {
    aec_method(['POST']);
    $actor = aec_promotion_admin($db);
    aec_csrf_check();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $input = aec_input();
    $status = (string)($input['status'] ?? '');
    if (!$id || $id < 1 || !in_array($status, ['active','paused','cancelled'], true)) aec_fail('Invalid campaign status update.', 422);
    if ($status === 'active') {
        $live = $db->prepare('SELECT id FROM promotion_campaigns WHERE id = ? AND starts_at <= NOW() AND ends_at > NOW()');
        $live->execute([$id]);
        if (!$live->fetchColumn()) aec_fail('Only an in-date campaign can be activated.', 409);
    }
    $stmt = $db->prepare('UPDATE promotion_campaigns SET status = ? WHERE id = ? AND status IN (\'scheduled\',\'active\',\'paused\')');
    $stmt->execute([$status, $id]);
    if (!$stmt->rowCount()) {
        $exists = $db->prepare('SELECT id FROM promotion_campaigns WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) aec_fail('Campaign not found.', 404);
    }
    $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, 'promotion.status_changed', 'promotion_campaign', ?, ?, ?)")
        ->execute([(int)$actor['id'], $id, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, json_encode(['status' => $status])]);
    aec_ok(['id' => $id, 'status' => $status], 'Campaign status updated.');
}

aec_fail('Unknown promotion action.', 404);
