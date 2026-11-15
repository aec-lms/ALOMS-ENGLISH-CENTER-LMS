-- Rate-limit SaaS custom-domain verification challenges and DNS lookups.
SET @aec_has_challenge_window = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'saas_tenant_domains'
    AND column_name = 'challenge_window_started_at'
);
SET @aec_challenge_window_sql = IF(
  @aec_has_challenge_window = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN challenge_window_started_at DATETIME NULL AFTER verification_expires_at',
  'DO 0'
);
PREPARE aec_saas_rate_limit_stmt FROM @aec_challenge_window_sql;
EXECUTE aec_saas_rate_limit_stmt;
DEALLOCATE PREPARE aec_saas_rate_limit_stmt;

SET @aec_has_challenge_count = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'saas_tenant_domains'
    AND column_name = 'challenge_request_count'
);
SET @aec_challenge_count_sql = IF(
  @aec_has_challenge_count = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN challenge_request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER challenge_window_started_at',
  'DO 0'
);
PREPARE aec_saas_rate_limit_stmt FROM @aec_challenge_count_sql;
EXECUTE aec_saas_rate_limit_stmt;
DEALLOCATE PREPARE aec_saas_rate_limit_stmt;

SET @aec_has_verify_window = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'saas_tenant_domains'
    AND column_name = 'verification_window_started_at'
);
SET @aec_verify_window_sql = IF(
  @aec_has_verify_window = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN verification_window_started_at DATETIME NULL AFTER challenge_request_count',
  'DO 0'
);
PREPARE aec_saas_rate_limit_stmt FROM @aec_verify_window_sql;
EXECUTE aec_saas_rate_limit_stmt;
DEALLOCATE PREPARE aec_saas_rate_limit_stmt;

SET @aec_has_verify_count = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'saas_tenant_domains'
    AND column_name = 'verification_attempt_count'
);
SET @aec_verify_count_sql = IF(
  @aec_has_verify_count = 0,
  'ALTER TABLE saas_tenant_domains ADD COLUMN verification_attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verification_window_started_at',
  'DO 0'
);
PREPARE aec_saas_rate_limit_stmt FROM @aec_verify_count_sql;
EXECUTE aec_saas_rate_limit_stmt;
DEALLOCATE PREPARE aec_saas_rate_limit_stmt;
