-- Retain deleted private course media for a recoverable 30-day window.
SET @aec_media_deleted_at_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'media_assets' AND column_name = 'deleted_at'
);
SET @aec_media_deleted_at_ddl = IF(
  @aec_media_deleted_at_exists = 0,
  'ALTER TABLE media_assets ADD COLUMN deleted_at DATETIME NULL AFTER uploaded_at',
  'DO 1'
);
PREPARE aec_media_deleted_at_stmt FROM @aec_media_deleted_at_ddl;
EXECUTE aec_media_deleted_at_stmt;
DEALLOCATE PREPARE aec_media_deleted_at_stmt;

SET @aec_media_deleted_by_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'media_assets' AND column_name = 'deleted_by'
);
SET @aec_media_deleted_by_ddl = IF(
  @aec_media_deleted_by_exists = 0,
  'ALTER TABLE media_assets ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at',
  'DO 1'
);
PREPARE aec_media_deleted_by_stmt FROM @aec_media_deleted_by_ddl;
EXECUTE aec_media_deleted_by_stmt;
DEALLOCATE PREPARE aec_media_deleted_by_stmt;

SET @aec_media_purge_after_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'media_assets' AND column_name = 'purge_after'
);
SET @aec_media_purge_after_ddl = IF(
  @aec_media_purge_after_exists = 0,
  'ALTER TABLE media_assets ADD COLUMN purge_after DATETIME NULL AFTER deleted_by',
  'DO 1'
);
PREPARE aec_media_purge_after_stmt FROM @aec_media_purge_after_ddl;
EXECUTE aec_media_purge_after_stmt;
DEALLOCATE PREPARE aec_media_purge_after_stmt;

SET @aec_media_retention_index_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'media_assets' AND index_name = 'idx_media_retention'
);
SET @aec_media_retention_index_ddl = IF(
  @aec_media_retention_index_exists = 0,
  'ALTER TABLE media_assets ADD INDEX idx_media_retention (deleted_at, purge_after)',
  'DO 1'
);
PREPARE aec_media_retention_index_stmt FROM @aec_media_retention_index_ddl;
EXECUTE aec_media_retention_index_stmt;
DEALLOCATE PREPARE aec_media_retention_index_stmt;

SET @aec_media_deleted_by_fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE constraint_schema = DATABASE() AND table_name = 'media_assets'
    AND constraint_name = 'fk_media_deleted_by' AND constraint_type = 'FOREIGN KEY'
);
SET @aec_media_deleted_by_fk_ddl = IF(
  @aec_media_deleted_by_fk_exists = 0,
  'ALTER TABLE media_assets ADD CONSTRAINT fk_media_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 1'
);
PREPARE aec_media_deleted_by_fk_stmt FROM @aec_media_deleted_by_fk_ddl;
EXECUTE aec_media_deleted_by_fk_stmt;
DEALLOCATE PREPARE aec_media_deleted_by_fk_stmt;
