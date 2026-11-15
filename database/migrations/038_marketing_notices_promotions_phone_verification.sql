-- Marketing, notices, campaign promotion and phone verification foundation.
-- All tables are additive; provider secrets remain in private server configuration.
CREATE TABLE IF NOT EXISTS notice_board_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    audience_role VARCHAR(40) NOT NULL DEFAULT 'all',
    branch_id BIGINT UNSIGNED NULL,
    status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notice_visibility (status, starts_at, ends_at),
    INDEX idx_notice_audience (audience_role, branch_id),
    CONSTRAINT fk_notice_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_notice_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notice_board_reads (
    notice_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, user_id),
    CONSTRAINT fk_notice_read_item FOREIGN KEY (notice_id) REFERENCES notice_board_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_notice_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_name VARCHAR(180) NOT NULL,
    entity_type ENUM('course','institution','branch') NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    placement ENUM('homepage_featured','course_listing','branch_directory','notice_banner') NOT NULL DEFAULT 'homepage_featured',
    status ENUM('draft','scheduled','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    priority TINYINT UNSIGNED NOT NULL DEFAULT 1,
    destination_url VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_promotion_live (status, starts_at, ends_at, priority),
    INDEX idx_promotion_entity (entity_type, entity_id),
    CONSTRAINT fk_promotion_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_promotion_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_campaign_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('impression','click','conversion') NOT NULL,
    visitor_hash CHAR(64) NULL,
    user_id BIGINT UNSIGNED NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign_event_type_time (campaign_id, event_type, occurred_at),
    CONSTRAINT fk_promotion_event_campaign FOREIGN KEY (campaign_id) REFERENCES promotion_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phone_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    phone_e164 VARCHAR(20) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requested_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_phone_verification_hash (token_hash),
    INDEX idx_phone_verification_user (user_id, consumed_at, expires_at),
    INDEX idx_phone_verification_phone (phone_e164, created_at),
    CONSTRAINT fk_phone_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
