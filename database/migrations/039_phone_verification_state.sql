-- Add explicit phone verification state without changing existing account records.
SET @aec_phone_verified_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone_verified_at'
);
SET @aec_phone_verified_ddl = IF(
  @aec_phone_verified_exists = 0,
  'ALTER TABLE users ADD COLUMN phone_verified_at DATETIME NULL AFTER phone',
  'DO 1'
);
PREPARE aec_phone_verified_stmt FROM @aec_phone_verified_ddl;
EXECUTE aec_phone_verified_stmt;
DEALLOCATE PREPARE aec_phone_verified_stmt;
