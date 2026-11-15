-- DNS ownership verification for SaaS tenant custom domains.
-- Verification is a prerequisite for future provisioning; it does not activate tenants.
-- Use conditional dynamic ALTERs because MySQL 8.4 does not support
-- ADD COLUMN IF NOT EXISTS, and the migration test suite replays migrations.
SET @aec_has_domain_verification_token = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'saas_tenant_domains'
    AND column_name = 'verification_token'
);
SET @aec_domain_verification_token_sql = IF(
  @aec_has_domain_verification_token = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN verification_token CHAR(64) NULL AFTER verified_at',
  'DO 0'
);
PREPARE aec_domain_verification_stmt FROM @aec_domain_verification_token_sql;
EXECUTE aec_domain_verification_stmt;
DEALLOCATE PREPARE aec_domain_verification_stmt;

SET @aec_has_domain_verification_expiry = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'saas_tenant_domains'
    AND column_name = 'verification_expires_at'
);
SET @aec_domain_verification_expiry_sql = IF(
  @aec_has_domain_verification_expiry = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN verification_expires_at DATETIME NULL AFTER verification_token',
  'DO 0'
);
PREPARE aec_domain_verification_stmt FROM @aec_domain_verification_expiry_sql;
EXECUTE aec_domain_verification_stmt;
DEALLOCATE PREPARE aec_domain_verification_stmt;
