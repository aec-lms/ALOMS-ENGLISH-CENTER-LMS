<?php
declare(strict_types=1);

/**
 * Attribute a completed course enrollment/payment to a previously clicked Boost
 * campaign. Click events are tied to an authenticated user when available and
 * otherwise to a short-lived IP/user-agent hash. Tracking failures never block
 * the underlying enrollment or payment workflow.
 */
function aec_promotion_record_course_conversion(PDO $db, int $userId, int $courseId, ?int $branchId): void
{
    if ($userId < 1 || $courseId < 1) return;
    try {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        $visitorHash = hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d'));
        $campaigns = $db->prepare("SELECT DISTINCT c.id
            FROM promotion_campaigns c
            INNER JOIN promotion_campaign_events click
                ON click.campaign_id = c.id
                AND click.event_type = 'click'
                AND click.occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND (click.user_id = ? OR click.visitor_hash = ?)
            WHERE (
                (c.entity_type = 'course' AND c.entity_id = ?)
                OR (c.entity_type = 'branch' AND c.entity_id = ?)
                OR c.entity_type = 'institution'
            )");
        $campaigns->execute([$userId, $visitorHash, $courseId, $branchId]);
        foreach ($campaigns->fetchAll(PDO::FETCH_COLUMN) as $campaignId) {
            $existing = $db->prepare("SELECT id FROM promotion_campaign_events
                WHERE campaign_id = ? AND event_type = 'conversion' AND user_id = ?
                  AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1");
            $existing->execute([(int)$campaignId, $userId]);
            if ($existing->fetchColumn()) continue;
            $db->prepare("INSERT INTO promotion_campaign_events (campaign_id, event_type, visitor_hash, user_id)
                VALUES (?, 'conversion', ?, ?)")->execute([(int)$campaignId, $visitorHash, $userId]);
        }
    } catch (Throwable $error) {
        error_log('AEC Boost conversion tracking failed: ' . get_class($error));
    }
}
