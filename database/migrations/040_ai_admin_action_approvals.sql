-- Safe, typed AI Admin Copilot proposals and reversible before-image backups.
CREATE TABLE IF NOT EXISTS ai_action_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    preview JSON NOT NULL,
    status ENUM('pending','applied','rejected','expired','rolled_back') NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    approved_at DATETIME NULL,
    applied_at DATETIME NULL,
    rolled_back_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_proposal_owner_status (created_by, status, expires_at),
    CONSTRAINT fk_ai_proposal_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_ai_proposal_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_action_backups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    proposal_id BIGINT UNSIGNED NOT NULL,
    before_snapshot JSON NOT NULL,
    after_snapshot JSON NULL,
    rolled_back_by BIGINT UNSIGNED NULL,
    rolled_back_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_action_backup_proposal (proposal_id),
    CONSTRAINT fk_ai_backup_proposal FOREIGN KEY (proposal_id) REFERENCES ai_action_proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_backup_rollback_user FOREIGN KEY (rolled_back_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
