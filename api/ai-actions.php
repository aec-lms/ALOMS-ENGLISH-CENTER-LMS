<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$actor = aec_current_user();
if (($actor['role'] ?? '') !== 'admin') aec_fail('AI action approvals are available to Admin only.', 403);
$action = (string)($_GET['action'] ?? '');

function aec_ai_keys(array $payload, array $allowed, array $required = []): void {
    foreach (array_keys($payload) as $key) if (!in_array($key, $allowed, true)) aec_fail('Unsupported field in AI action payload: ' . (string)$key, 422);
    foreach ($required as $key) if (!array_key_exists($key, $payload)) aec_fail('Missing required AI action field: ' . $key, 422);
}

function aec_ai_date(?string $value, string $field, bool $required = false): ?string {
    if ($value === null || trim($value) === '') {
        if ($required) aec_fail($field . ' is required.', 422);
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) aec_fail($field . ' must use YYYY-MM-DD HH:MM:SS.', 422);
    return $date->format('Y-m-d H:i:s');
}

function aec_ai_validate(PDO $db, string $type, array $payload): array {
    if ($type === 'update_marketing_settings') {
        $allowed = ['googleAnalyticsId','googleTagManagerId','metaPixelId','googleSearchConsoleToken','siteTitleSeo','siteMetaDescription','canonicalUrl','robotsTxtContent'];
        aec_ai_keys($payload, $allowed);
        if (!$payload) aec_fail('At least one marketing setting is required.', 422);
        $clean = [];
        foreach ($payload as $key => $value) {
            if (!is_string($value)) aec_fail('Marketing setting values must be strings.', 422);
            $value = trim($value);
            if (mb_strlen($value) > 10000) aec_fail($key . ' is too long.', 422);
            $clean[$key] = $value;
        }
        if (isset($clean['googleAnalyticsId']) && $clean['googleAnalyticsId'] !== '' && !preg_match('/^G-[A-Z0-9]{4,20}$/i', $clean['googleAnalyticsId'])) aec_fail('Invalid Google Analytics ID.', 422);
        if (isset($clean['googleTagManagerId']) && $clean['googleTagManagerId'] !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/i', $clean['googleTagManagerId'])) aec_fail('Invalid Google Tag Manager ID.', 422);
        if (isset($clean['metaPixelId']) && $clean['metaPixelId'] !== '' && !preg_match('/^[0-9]{5,30}$/', $clean['metaPixelId'])) aec_fail('Invalid Meta Pixel ID.', 422);
        if (isset($clean['googleSearchConsoleToken'])) {
            if (str_starts_with($clean['googleSearchConsoleToken'], 'google-site-verification=')) $clean['googleSearchConsoleToken'] = substr($clean['googleSearchConsoleToken'], strlen('google-site-verification='));
            if ($clean['googleSearchConsoleToken'] !== '' && !preg_match('/^[A-Za-z0-9_=-]{10,250}$/', $clean['googleSearchConsoleToken'])) aec_fail('Invalid Search Console verification token.', 422);
        }
        if (isset($clean['siteTitleSeo']) && mb_strlen($clean['siteTitleSeo']) > 70) aec_fail('SEO title must not exceed 70 characters.', 422);
        if (isset($clean['siteMetaDescription']) && mb_strlen($clean['siteMetaDescription']) > 320) aec_fail('SEO description must not exceed 320 characters.', 422);
        if (isset($clean['canonicalUrl']) && $clean['canonicalUrl'] !== '') {
            $parts = parse_url($clean['canonicalUrl']);
            if (!filter_var($clean['canonicalUrl'], FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) aec_fail('Canonical URL must be HTTPS.', 422);
        }
        return $clean;
    }

    if ($type === 'create_notice' || $type === 'update_notice') {
        $allowed = ['title','body','audience_role','branch_id','starts_at','ends_at'];
        if ($type === 'update_notice') $allowed[] = 'id';
        aec_ai_keys($payload, $allowed, $type === 'update_notice' ? ['id','title','body','audience_role','branch_id','starts_at','ends_at'] : ['title','body','audience_role']);
        $clean = [
            'title' => trim((string)$payload['title']),
            'body' => trim((string)$payload['body']),
            'audience_role' => (string)$payload['audience_role'],
            'branch_id' => isset($payload['branch_id']) && $payload['branch_id'] !== '' ? filter_var($payload['branch_id'], FILTER_VALIDATE_INT) : null,
            'starts_at' => aec_ai_date(isset($payload['starts_at']) ? (string)$payload['starts_at'] : null, 'starts_at'),
            'ends_at' => aec_ai_date(isset($payload['ends_at']) ? (string)$payload['ends_at'] : null, 'ends_at'),
        ];
        if ($type === 'update_notice') $clean['id'] = filter_var($payload['id'], FILTER_VALIDATE_INT);
        if ($clean['title'] === '' || mb_strlen($clean['title']) > 180 || $clean['body'] === '' || mb_strlen($clean['body']) > 20000) aec_fail('Notice title/body is invalid.', 422);
        if (!in_array($clean['audience_role'], ['all','admin','branch_manager','teacher','assistant_teacher','accountant','student','guardian','support_staff'], true)) aec_fail('Invalid notice audience.', 422);
        if ($clean['branch_id'] !== null) {
            if (!$clean['branch_id'] || $clean['branch_id'] < 1) aec_fail('Invalid branch ID.', 422);
            $check = $db->prepare('SELECT id FROM branches WHERE id = ?');
            $check->execute([$clean['branch_id']]);
            if (!$check->fetchColumn()) aec_fail('Branch not found.', 404);
        }
        if ($clean['starts_at'] && $clean['ends_at'] && $clean['ends_at'] <= $clean['starts_at']) aec_fail('Notice end must be later than start.', 422);
        if ($type === 'update_notice' && (!$clean['id'] || $clean['id'] < 1)) aec_fail('Invalid notice ID.', 422);
        return $clean;
    }

    if ($type === 'set_notice_status') {
        aec_ai_keys($payload, ['id','status'], ['id','status']);
        $id = filter_var($payload['id'], FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !in_array($payload['status'], ['draft','published','archived'], true)) aec_fail('Invalid notice status action.', 422);
        return ['id' => $id, 'status' => (string)$payload['status']];
    }

    if ($type === 'create_promotion') {
        $allowed = ['campaign_name','entity_type','entity_id','placement','starts_at','ends_at','priority','destination_url'];
        aec_ai_keys($payload, $allowed, ['campaign_name','entity_type','placement','starts_at','ends_at']);
        $name = trim((string)$payload['campaign_name']);
        $entityType = (string)$payload['entity_type'];
        $entityId = isset($payload['entity_id']) && $payload['entity_id'] !== '' ? filter_var($payload['entity_id'], FILTER_VALIDATE_INT) : null;
        $placement = (string)$payload['placement'];
        $start = aec_ai_date((string)$payload['starts_at'], 'starts_at', true);
        $end = aec_ai_date((string)$payload['ends_at'], 'ends_at', true);
        $priority = filter_var($payload['priority'] ?? 5, FILTER_VALIDATE_INT);
        $url = trim((string)($payload['destination_url'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) aec_fail('Campaign name is invalid.', 422);
        if (!in_array($entityType, ['course','institution','branch'], true)) aec_fail('Invalid campaign target.', 422);
        if ($entityType !== 'institution' && (!$entityId || $entityId < 1)) aec_fail('A valid target ID is required.', 422);
        if (!in_array($placement, ['homepage_featured','course_listing','branch_directory','notice_banner'], true)) aec_fail('Invalid campaign placement.', 422);
        if ($end <= $start) aec_fail('Campaign end must be later than start.', 422);
        if ($priority === false || $priority < 1 || $priority > 10) aec_fail('Campaign priority must be 1–10.', 422);
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https')) aec_fail('Campaign destination must be an HTTPS URL.', 422);
        if ($entityType === 'course') {
            $check = $db->prepare('SELECT id FROM courses WHERE id = ? AND deleted_at IS NULL');
            $check->execute([$entityId]);
            if (!$check->fetchColumn()) aec_fail('Course not found.', 404);
        } elseif ($entityType === 'branch') {
            $check = $db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
            $check->execute([$entityId]);
            if (!$check->fetchColumn()) aec_fail('Active branch not found.', 404);
        }
        return ['campaign_name'=>$name,'entity_type'=>$entityType,'entity_id'=>$entityId,'placement'=>$placement,'starts_at'=>$start,'ends_at'=>$end,'priority'=>$priority,'destination_url'=>$url ?: null];
    }

    if ($type === 'set_promotion_status') {
        aec_ai_keys($payload, ['id','status'], ['id','status']);
        $id = filter_var($payload['id'], FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !in_array($payload['status'], ['active','paused','cancelled'], true)) aec_fail('Invalid promotion status action.', 422);
        return ['id'=>$id,'status'=>(string)$payload['status']];
    }

    aec_fail('This AI action type is not supported.', 422);
}

function aec_ai_canonical_snapshot(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('aec_ai_canonical_snapshot', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = aec_ai_canonical_snapshot($item);
    return $value;
}

function aec_ai_same_snapshot(mixed $left, mixed $right): bool {
    return json_encode(aec_ai_canonical_snapshot($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === json_encode(aec_ai_canonical_snapshot($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function aec_ai_marketing_snapshot(PDO $db): array {
    $stmt = $db->prepare("SELECT setting_value, updated_by, updated_at FROM site_settings WHERE setting_key = 'marketing' LIMIT 1 FOR UPDATE");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $settings = is_array($row) ? json_decode((string)$row['setting_value'], true) : [];
    return [
        'exists' => is_array($row),
        'settings' => is_array($settings) ? $settings : [],
        'updated_by' => is_array($row) && $row['updated_by'] !== null ? (int)$row['updated_by'] : null,
        'updated_at' => is_array($row) ? (string)$row['updated_at'] : null,
    ];
}

function aec_ai_snapshot(PDO $db, string $type, array $payload): array {
    if ($type === 'update_marketing_settings') return aec_ai_marketing_snapshot($db);
    if ($type === 'create_notice') return ['exists' => false];
    if ($type === 'update_notice' || $type === 'set_notice_status') {
        $stmt = $db->prepare('SELECT id,title,body,audience_role,branch_id,status,starts_at,ends_at,created_by FROM notice_board_items WHERE id = ? FOR UPDATE');
        $stmt->execute([(int)$payload['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) aec_fail('Notice not found.', 404);
        return $row;
    }
    if ($type === 'create_promotion') return ['exists' => false];
    if ($type === 'set_promotion_status') {
        $row = aec_ai_read_promotion($db, (int)$payload['id']);
        if (!$row) aec_fail('Campaign not found.', 404);
        return $row;
    }
    return [];
}

function aec_ai_preview_after(string $type, array $payload, array $before): array {
    if ($type === 'update_marketing_settings') return ['exists' => true, 'settings' => array_merge($before['settings'] ?? [], $payload)];
    if ($type === 'create_notice') return ['notice' => $payload, 'status' => !empty($payload['starts_at']) && $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'published'];
    if ($type === 'update_notice') {
        $status = (string)$before['status'];
        if (in_array($status, ['published','scheduled'], true)) $status = !empty($payload['starts_at']) && $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'published';
        return array_merge($before, $payload, ['status' => $status]);
    }
    if ($type === 'set_notice_status') return array_merge($before, ['status' => $payload['status']]);
    if ($type === 'create_promotion') return ['campaign' => $payload, 'status' => $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'active'];
    if ($type === 'set_promotion_status') return array_merge($before, ['status' => $payload['status']]);
    return [];
}

function aec_ai_read_notice(PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT id,title,body,audience_role,branch_id,status,starts_at,ends_at,created_by FROM notice_board_items WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function aec_ai_read_promotion(PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT id,campaign_name,entity_type,entity_id,placement,status,starts_at,ends_at,priority,destination_url,created_by FROM promotion_campaigns WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function aec_ai_apply(PDO $db, string $type, array $payload, array $before, int $actorId): array {
    if ($type === 'update_marketing_settings') {
        $settings = array_merge($before['settings'] ?? [], $payload);
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES ('marketing',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actorId]);
        return aec_ai_marketing_snapshot($db);
    }
    if ($type === 'create_notice') {
        $status = !empty($payload['starts_at']) && $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'published';
        $stmt = $db->prepare('INSERT INTO notice_board_items (title,body,audience_role,branch_id,status,starts_at,ends_at,created_by) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$payload['title'],$payload['body'],$payload['audience_role'],$payload['branch_id'],$status,$payload['starts_at'],$payload['ends_at'],$actorId]);
        return aec_ai_read_notice($db, (int)$db->lastInsertId());
    }
    if ($type === 'update_notice') {
        $status = (string)$before['status'];
        if (in_array($status, ['published','scheduled'], true)) $status = !empty($payload['starts_at']) && $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'published';
        $stmt = $db->prepare('UPDATE notice_board_items SET title=?,body=?,audience_role=?,branch_id=?,status=?,starts_at=?,ends_at=? WHERE id=?');
        $stmt->execute([$payload['title'],$payload['body'],$payload['audience_role'],$payload['branch_id'],$status,$payload['starts_at'],$payload['ends_at'],(int)$payload['id']]);
        return aec_ai_read_notice($db, (int)$payload['id']);
    }
    if ($type === 'set_notice_status') {
        $db->prepare('UPDATE notice_board_items SET status=? WHERE id=?')->execute([$payload['status'],(int)$payload['id']]);
        return aec_ai_read_notice($db, (int)$payload['id']);
    }
    if ($type === 'create_promotion') {
        $status = $payload['starts_at'] > date('Y-m-d H:i:s') ? 'scheduled' : 'active';
        $stmt = $db->prepare('INSERT INTO promotion_campaigns (campaign_name,entity_type,entity_id,placement,status,starts_at,ends_at,priority,destination_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$payload['campaign_name'],$payload['entity_type'],$payload['entity_id'],$payload['placement'],$status,$payload['starts_at'],$payload['ends_at'],$payload['priority'],$payload['destination_url'],$actorId]);
        return aec_ai_read_promotion($db, (int)$db->lastInsertId());
    }
    if ($type === 'set_promotion_status') {
        if ($payload['status'] === 'active') {
            $stmt = $db->prepare('SELECT id FROM promotion_campaigns WHERE id=? AND starts_at<=NOW() AND ends_at>NOW()');
            $stmt->execute([(int)$payload['id']]);
            if (!$stmt->fetchColumn()) aec_fail('Only an in-date campaign can be activated.', 409);
        }
        $db->prepare('UPDATE promotion_campaigns SET status=? WHERE id=?')->execute([$payload['status'],(int)$payload['id']]);
        return aec_ai_read_promotion($db, (int)$payload['id']);
    }
    aec_fail('Unsupported AI action.', 422);
}

function aec_ai_rollback(PDO $db, string $type, array $payload, array $before, array $after): void {
    if ($type === 'update_marketing_settings') {
        if (empty($before['exists'])) {
            $db->prepare("DELETE FROM site_settings WHERE setting_key = 'marketing'")->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('marketing',?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)");
            $stmt->execute([
                json_encode($before['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $before['updated_by'] ?? null,
                $before['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
        return;
    }
    if ($type === 'create_notice') {
        $db->prepare("UPDATE notice_board_items SET status='archived' WHERE id=?")->execute([(int)($after['id'] ?? 0)]);
        return;
    }
    if ($type === 'update_notice' || $type === 'set_notice_status') {
        $db->prepare('UPDATE notice_board_items SET title=?,body=?,audience_role=?,branch_id=?,status=?,starts_at=?,ends_at=? WHERE id=?')
            ->execute([$before['title'],$before['body'],$before['audience_role'],$before['branch_id'],$before['status'],$before['starts_at'],$before['ends_at'],(int)$before['id']]);
        return;
    }
    if ($type === 'create_promotion') {
        $db->prepare("UPDATE promotion_campaigns SET status='cancelled' WHERE id=?")->execute([(int)($after['id'] ?? 0)]);
        return;
    }
    if ($type === 'set_promotion_status') {
        $db->prepare('UPDATE promotion_campaigns SET status=? WHERE id=?')->execute([$before['status'],(int)$before['id']]);
        return;
    }
    aec_fail('Rollback is not supported for this action.', 422);
}

function aec_ai_audit(PDO $db, int $actorId, string $event, int $proposalId, array $metadata = []): void {
    $db->prepare('INSERT INTO audit_logs (user_id,action,entity_type,entity_id,ip_address,metadata) VALUES (?,?,?,?,?,?)')
        ->execute([$actorId,$event,'ai_action_proposal',$proposalId,substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45) ?: null,json_encode($metadata,JSON_UNESCAPED_UNICODE)]);
}

if ($action === 'preview') {
    aec_method(['POST']);
    aec_csrf_check();
    $input = aec_input();
    $type = (string)($input['action_type'] ?? '');
    $payload = $input['payload'] ?? null;
    if (!is_array($payload)) aec_fail('A typed action payload is required.', 422);
    $payload = aec_ai_validate($db, $type, $payload);
    $before = aec_ai_snapshot($db, $type, $payload);
    $after = aec_ai_preview_after($type, $payload, $before);
    $preview = ['action_type'=>$type,'before'=>$before,'after'=>$after,'summary'=>'Review this exact change before approving it.'];
    $stmt = $db->prepare('INSERT INTO ai_action_proposals (action_type,payload,preview,created_by,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))');
    $stmt->execute([$type,json_encode($payload,JSON_UNESCAPED_UNICODE),json_encode($preview,JSON_UNESCAPED_UNICODE),(int)$actor['id']]);
    $id = (int)$db->lastInsertId();
    aec_ai_audit($db,(int)$actor['id'],'ai.action.previewed',$id,['action_type'=>$type]);
    aec_ok(['proposal_id'=>$id,'expires_in_seconds'=>600,'preview'=>$preview],'Preview created. Explicit Admin approval is required.');
}

if ($action === 'approve') {
    aec_method(['POST']);
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['proposal_id'] ?? null,FILTER_VALIDATE_INT);
    if (!$id || $id < 1 || ($input['confirmation'] ?? '') !== 'APPLY') aec_fail('Enter the exact confirmation phrase APPLY.',422);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT *, (expires_at <= NOW()) AS is_expired FROM ai_action_proposals WHERE id=? AND created_by=? FOR UPDATE');
        $stmt->execute([$id,(int)$actor['id']]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$proposal || $proposal['status'] !== 'pending' || (int)($proposal['is_expired'] ?? 1) === 1) {
            if ($proposal && $proposal['status'] === 'pending') $db->prepare("UPDATE ai_action_proposals SET status='expired' WHERE id=?")->execute([$id]);
            $db->commit();
            aec_fail('Proposal is missing, expired, already used or belongs to another Admin.',409);
        }
        $payload = json_decode((string)$proposal['payload'],true);
        $payload = aec_ai_validate($db,(string)$proposal['action_type'],is_array($payload)?$payload:[]);
        $before = aec_ai_snapshot($db,(string)$proposal['action_type'],$payload);
        $preview = json_decode((string)$proposal['preview'],true);
        if (!aec_ai_same_snapshot($before, $preview['before'] ?? null)) {
            $db->rollBack();
            aec_fail('The underlying data changed after preview. Create a fresh preview.',409);
        }
        $db->prepare('INSERT INTO ai_action_backups (proposal_id,before_snapshot) VALUES (?,?)')->execute([$id,json_encode($before,JSON_UNESCAPED_UNICODE)]);
        $applied = aec_ai_apply($db,(string)$proposal['action_type'],$payload,$before,(int)$actor['id']);
        $after = in_array((string)$proposal['action_type'], ['create_notice','create_promotion'], true)
            ? $applied
            : aec_ai_snapshot($db,(string)$proposal['action_type'],$payload);
        $db->prepare('UPDATE ai_action_backups SET after_snapshot=? WHERE proposal_id=?')->execute([json_encode($after,JSON_UNESCAPED_UNICODE),$id]);
        $db->prepare("UPDATE ai_action_proposals SET status='applied',approved_by=?,approved_at=NOW(),applied_at=NOW() WHERE id=?")->execute([(int)$actor['id'],$id]);
        aec_ai_audit($db,(int)$actor['id'],'ai.action.applied',$id,['action_type'=>$proposal['action_type']]);
        $db->commit();
        aec_ok(['proposal_id'=>$id,'status'=>'applied','result'=>$after],'Approved action applied successfully.');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

if ($action === 'rollback') {
    aec_method(['POST']);
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['proposal_id'] ?? null,FILTER_VALIDATE_INT);
    if (!$id || $id < 1 || ($input['confirmation'] ?? '') !== 'ROLLBACK') aec_fail('Enter the exact confirmation phrase ROLLBACK.',422);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT p.*,b.before_snapshot,b.after_snapshot FROM ai_action_proposals p JOIN ai_action_backups b ON b.proposal_id=p.id WHERE p.id=? AND p.created_by=? FOR UPDATE");
        $stmt->execute([$id,(int)$actor['id']]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$proposal || $proposal['status'] !== 'applied' || $proposal['rolled_back_at'] !== null) {
            $db->rollBack();
            aec_fail('Only an applied, not-yet-rolled-back proposal can be rolled back.',409);
        }
        $payload = json_decode((string)$proposal['payload'],true) ?: [];
        $before = json_decode((string)$proposal['before_snapshot'],true) ?: [];
        $after = json_decode((string)$proposal['after_snapshot'],true) ?: [];
        $current = match ((string)$proposal['action_type']) {
            'create_notice' => aec_ai_read_notice($db, (int)($after['id'] ?? 0)),
            'create_promotion' => aec_ai_read_promotion($db, (int)($after['id'] ?? 0)),
            default => aec_ai_snapshot($db,(string)$proposal['action_type'],$payload),
        };
        if (!aec_ai_same_snapshot($current, $after)) {
            $db->rollBack();
            aec_fail('The target changed after this AI action. Rollback was refused to avoid overwriting newer work.',409);
        }
        aec_ai_rollback($db,(string)$proposal['action_type'],$payload,$before,$after);
        $db->prepare("UPDATE ai_action_proposals SET status='rolled_back',rolled_back_at=NOW() WHERE id=?")->execute([$id]);
        $db->prepare('UPDATE ai_action_backups SET rolled_back_by=?,rolled_back_at=NOW() WHERE proposal_id=?')->execute([(int)$actor['id'],$id]);
        aec_ai_audit($db,(int)$actor['id'],'ai.action.rolled_back',$id,['action_type'=>$proposal['action_type']]);
        $db->commit();
        aec_ok(['proposal_id'=>$id,'status'=>'rolled_back'],'Action rolled back.');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

aec_fail('Unknown AI action workflow.',404);
