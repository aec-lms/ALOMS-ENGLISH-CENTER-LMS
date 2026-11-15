<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$actor = aec_current_user();
if (($actor['role'] ?? '') !== 'admin') {
    aec_fail('Only an administrator can access the SaaS control plane.', 403);
}
$action = (string)($_GET['action'] ?? 'list');

function aec_saas_audit(PDO $db, array $actor, string $event, int $tenantId, array $metadata = []): void {
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        (int)$actor['id'], $event, 'saas_tenant', $tenantId,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function aec_saas_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    if ($domain === '') return '';
    if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain)) {
        aec_fail('Enter a valid ASCII domain name (use punycode for international domains).', 422);
    }
    return $domain;
}

if ($action === 'list') {
    aec_method(['GET']);
    $stmt = $db->query("SELECT t.id, t.tenant_key, t.name, t.status, t.contact_email, t.contact_phone, t.city,
        t.created_at, p.plan_key, p.name AS plan_name, p.monthly_price, p.currency,
        (SELECT GROUP_CONCAT(d.domain ORDER BY d.is_primary DESC, d.domain SEPARATOR ',') FROM saas_tenant_domains d WHERE d.tenant_id = t.id) AS domains,
        (SELECT s.status FROM saas_subscriptions s WHERE s.tenant_id = t.id ORDER BY s.created_at DESC, s.id DESC LIMIT 1) AS subscription_status,
        (SELECT s.ends_at FROM saas_subscriptions s WHERE s.tenant_id = t.id ORDER BY s.created_at DESC, s.id DESC LIMIT 1) AS subscription_ends_at
        FROM saas_tenants t JOIN saas_plans p ON p.id = t.plan_id
        ORDER BY t.created_at DESC, t.id DESC");
    aec_ok($stmt->fetchAll());
}

if ($action === 'plans') {
    aec_method(['GET']);
    $stmt = $db->query('SELECT plan_key, name, monthly_price, currency FROM saas_plans WHERE is_active = 1 ORDER BY monthly_price, id');
    aec_ok($stmt->fetchAll());
}

if ($action === 'create') {
    aec_method(['POST']);
    aec_csrf_check();
    $input = aec_input();
    $key = strtolower(trim((string)($input['tenant_key'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['contact_email'] ?? '')));
    $phone = trim((string)($input['contact_phone'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $planKey = strtolower(trim((string)($input['plan_key'] ?? 'professional')));
    $domain = aec_saas_domain((string)($input['domain'] ?? ''));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/', $key)) aec_fail('Tenant key must be 3–63 lowercase letters, numbers or internal hyphens.', 422);
    if ($name === '' || mb_strlen($name) > 160) aec_fail('Tenant name is required and must not exceed 160 characters.', 422);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) aec_fail('A valid contact email is required.', 422);
    if (strlen($phone) > 32 || mb_strlen($city) > 120) aec_fail('Contact details exceed the allowed length.', 422);

    $planStmt = $db->prepare('SELECT id, monthly_price, currency FROM saas_plans WHERE plan_key = ? AND is_active = 1 LIMIT 1');
    $planStmt->execute([$planKey]);
    $plan = $planStmt->fetch();
    if (!$plan) aec_fail('Selected SaaS plan is unavailable.', 422);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO saas_tenants (tenant_key, name, status, contact_email, contact_phone, city, plan_id, created_by)
            VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)");
        $stmt->execute([$key, $name, $email ?: null, $phone ?: null, $city ?: null, (int)$plan['id'], (int)$actor['id']]);
        $tenantId = (int)$db->lastInsertId();

        if ($domain !== '') {
            $domainStmt = $db->prepare('INSERT INTO saas_tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 1)');
            $domainStmt->execute([$tenantId, $domain]);
        }

        $subscription = $db->prepare("INSERT INTO saas_subscriptions (tenant_id, plan_id, status, billing_cycle, amount, currency, created_by)
            VALUES (?, ?, 'trialing', 'monthly', ?, ?, ?)");
        $subscription->execute([$tenantId, (int)$plan['id'], $plan['monthly_price'], $plan['currency'], (int)$actor['id']]);
        aec_saas_audit($db, $actor, 'saas.tenant.created', $tenantId, ['tenant_key' => $key, 'plan_key' => $planKey]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getCode() === '23000') aec_fail('Tenant key or domain is already registered.', 409);
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_ok(['id' => $tenantId, 'tenant_key' => $key, 'status' => 'pending', 'provisioned' => false], 'Tenant registered. Data-plane provisioning is not enabled.', 201);
}

if ($action === 'domain-challenge') {
    aec_method(['POST']);
    aec_csrf_check();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid tenant id is required.', 422);
    $stmt = $db->prepare('SELECT id, domain, verified_at FROM saas_tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
    $stmt->execute([$id]);
    $domainRow = $stmt->fetch();
    if (!$domainRow) aec_fail('Register a tenant domain before requesting verification.', 404);
    if ($domainRow['verified_at'] !== null) aec_fail('This domain is already verified.', 409);
    $db->beginTransaction();
    try {
        $locked = $db->prepare('SELECT id, domain, verified_at, challenge_window_started_at, challenge_request_count FROM saas_tenant_domains WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $locked->execute([(int)$domainRow['id'], (int)$id]);
        $domainRow = $locked->fetch();
        if (!$domainRow || $domainRow['verified_at'] !== null) aec_fail('This tenant domain is no longer eligible for a challenge.', 409);
        $now = new DateTimeImmutable('now');
        $windowStart = !empty($domainRow['challenge_window_started_at'])
            ? new DateTimeImmutable((string)$domainRow['challenge_window_started_at'])
            : null;
        $count = $windowStart && $windowStart > $now->modify('-1 hour')
            ? (int)$domainRow['challenge_request_count'] : 0;
        if ($count >= 3) aec_fail('Domain verification challenge limit reached. Try again after one hour.', 429);
        $token = bin2hex(random_bytes(32));
        $expiresAt = $now->modify('+24 hours')->format('Y-m-d H:i:s');
        $windowValue = $count === 0 ? $now->format('Y-m-d H:i:s') : $windowStart->format('Y-m-d H:i:s');
        $update = $db->prepare('UPDATE saas_tenant_domains SET verification_token = ?, verification_expires_at = ?, challenge_window_started_at = ?, challenge_request_count = ? WHERE id = ?');
        $update->execute([hash('sha256', $token), $expiresAt, $windowValue, $count + 1, (int)$domainRow['id']]);
        aec_saas_audit($db, $actor, 'saas.domain.challenge_created', (int)$id, ['domain' => $domainRow['domain'], 'request_count' => $count + 1]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    aec_ok([
        'domain' => $domainRow['domain'],
        'record_name' => '_aec-verification.' . $domainRow['domain'],
        'record_type' => 'TXT',
        'record_value' => 'aec-verification=' . $token,
        'expires_at' => $expiresAt,
    ], 'Publish the TXT record, then request domain verification.');
}

if ($action === 'verify-domain') {
    aec_method(['POST']);
    aec_csrf_check();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid tenant id is required.', 422);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, domain, verification_token, verification_expires_at, verified_at, verification_window_started_at, verification_attempt_count FROM saas_tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $domainRow = $stmt->fetch();
        if (!$domainRow) aec_fail('Tenant domain not found.', 404);
        if ($domainRow['verified_at'] !== null) {
            $db->commit();
            aec_ok(['domain' => $domainRow['domain'], 'verified' => true], 'Domain is already verified.');
        }
        if (!$domainRow['verification_token'] || !$domainRow['verification_expires_at'] || strtotime((string)$domainRow['verification_expires_at']) <= time()) {
            aec_fail('Create a fresh, unexpired domain verification challenge first.', 409);
        }
        $now = new DateTimeImmutable('now');
        $windowStart = !empty($domainRow['verification_window_started_at'])
            ? new DateTimeImmutable((string)$domainRow['verification_window_started_at'])
            : null;
        $count = $windowStart && $windowStart > $now->modify('-1 hour')
            ? (int)$domainRow['verification_attempt_count'] : 0;
        if ($count >= 10) aec_fail('Domain verification attempt limit reached. Try again after one hour.', 429);
        $windowValue = $count === 0 ? $now->format('Y-m-d H:i:s') : $windowStart->format('Y-m-d H:i:s');
        $db->prepare('UPDATE saas_tenant_domains SET verification_window_started_at = ?, verification_attempt_count = ? WHERE id = ?')
            ->execute([$windowValue, $count + 1, (int)$domainRow['id']]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }

    $recordName = '_aec-verification.' . $domainRow['domain'];
    $records = @dns_get_record($recordName, DNS_TXT);
    $verified = false;
    if (is_array($records)) {
        foreach ($records as $record) {
            $txt = trim((string)($record['txt'] ?? implode('', $record['entries'] ?? [])));
            $prefix = 'aec-verification=';
            if (!str_starts_with($txt, $prefix)) continue;
            $providedToken = substr($txt, strlen($prefix));
            if (preg_match('/^[a-f0-9]{64}$/D', $providedToken) === 1
                && hash_equals((string)$domainRow['verification_token'], hash('sha256', $providedToken))) {
                $verified = true;
                break;
            }
        }
    }
    if (!$verified) aec_fail('DNS TXT challenge was not found. Confirm propagation and try again.', 409);
    $verifiedUpdate = $db->prepare('UPDATE saas_tenant_domains SET verified_at = NOW(), verification_token = NULL, verification_expires_at = NULL WHERE id = ? AND verified_at IS NULL AND verification_token = ? AND verification_expires_at > NOW()');
    $verifiedUpdate->execute([(int)$domainRow['id'], (string)$domainRow['verification_token']]);
    if ($verifiedUpdate->rowCount() !== 1) aec_fail('The verification challenge changed or expired. Create a fresh challenge.', 409);
    aec_saas_audit($db, $actor, 'saas.domain.verified', (int)$id, ['domain' => $domainRow['domain']]);
    aec_ok(['domain' => $domainRow['domain'], 'verified' => true], 'Domain ownership verified.');
}

if ($action === 'status') {
    aec_method(['POST']);
    aec_csrf_check();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid tenant id is required.', 422);
    $input = aec_input();
    $status = (string)($input['status'] ?? '');
    if (!in_array($status, ['active', 'suspended'], true)) aec_fail('Tenant status must be active or suspended.', 422);
    if ($status === 'active') {
        aec_fail('Tenant activation is globally disabled until tenant-specific data-plane provisioning and cross-tenant isolation verification are implemented.', 409);
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, status FROM saas_tenants WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $tenant = $stmt->fetch();
        if (!$tenant) {
            $db->rollBack();
            aec_fail('Tenant not found.', 404);
        }
        if ($tenant['status'] === 'pending') {
            $db->rollBack();
            aec_fail('A pending tenant cannot change status until data-plane provisioning and domain verification are implemented.', 409);
        }
        $db->prepare('UPDATE saas_tenants SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $id]);
        aec_saas_audit($db, $actor, 'saas.tenant.' . $status, (int)$id, ['previous_status' => $tenant['status']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    aec_ok(['id' => (int)$id, 'status' => $status], 'Tenant status updated.');
}

aec_fail('Unknown SaaS tenant action.', 404);
