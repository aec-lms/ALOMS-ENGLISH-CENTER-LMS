-- Preserve sub-second payment verification timestamps so commission rule changes cannot shift earnings across rate periods.
ALTER TABLE course_payments MODIFY reviewed_at DATETIME(6) NULL;

-- Teacher commission rules and auditable payout requests.
-- Commission is calculated from verified tuition payments net of completed refunds.
CREATE TABLE IF NOT EXISTS course_commission_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id BIGINT UNSIGNED NOT NULL,
  teacher_user_id BIGINT UNSIGNED NOT NULL,
  commission_rate DECIMAL(5,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  closed_at DATETIME(6) NULL,
  active_course_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, course_id, NULL)) STORED,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_course_active_commission_rule (active_course_id),
  INDEX idx_commission_teacher_active (teacher_user_id, is_active),
  INDEX idx_commission_rule_period (course_id, created_at, closed_at),
  CONSTRAINT fk_commission_rule_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_rule_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_commission_rate CHECK (commission_rate >= 0 AND commission_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_payout_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payout_method ENUM('bKash','Nagad','Bank Transfer') NOT NULL,
  account_number VARCHAR(80) NOT NULL,
  bank_name VARCHAR(120) NULL,
  bank_branch VARCHAR(120) NULL,
  status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  transaction_reference VARCHAR(140) NULL,
  remarks VARCHAR(500) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_commission_payout_teacher_status (teacher_user_id, status, created_at),
  INDEX idx_commission_payout_status_date (status, created_at),
  UNIQUE KEY uq_commission_payout_reference (transaction_reference),
  CONSTRAINT fk_commission_payout_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_payout_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_commission_payout_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('admin', 'commissions.manage', 1),
('super_admin', 'commissions.manage', 1),
('accountant', 'commissions.manage', 1),
('branch_manager', 'commissions.manage', 0),
('teacher', 'commissions.manage', 0),
('assistant_teacher', 'commissions.manage', 0),
('student', 'commissions.manage', 0);
