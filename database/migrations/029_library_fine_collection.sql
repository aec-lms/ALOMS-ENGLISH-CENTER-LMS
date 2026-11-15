-- Auditable library fine collection with balanced double-entry accounting.
CREATE TABLE IF NOT EXISTS library_fine_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  payment_method ENUM('cash','bank_transfer','bKash','Nagad') NOT NULL,
  payment_reference VARCHAR(140) NOT NULL,
  received_by BIGINT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_library_fine_payment_reference (payment_reference),
  INDEX idx_library_fine_branch_date (branch_id, received_at),
  INDEX idx_library_fine_loan (loan_id, received_at),
  INDEX idx_library_fine_student (student_user_id, received_at),
  CONSTRAINT fk_library_fine_loan FOREIGN KEY (loan_id) REFERENCES library_book_loans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_library_fine_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_library_fine_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_library_fine_receiver FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_library_fine_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_fine_journal_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fine_payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
  reference VARCHAR(140) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_library_fine_journal_payment FOREIGN KEY (fine_payment_id) REFERENCES library_fine_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_library_fine_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_fine_journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_library_fine_entry_transaction (transaction_id),
  INDEX idx_library_fine_entry_account (account_id, created_at),
  CONSTRAINT fk_library_fine_entry_transaction FOREIGN KEY (transaction_id) REFERENCES library_fine_journal_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_library_fine_entry_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_library_fine_journal_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type)
VALUES ('INCOME_LIBRARY_FINES', 'Library Late Fine Income', 'income');

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('super_admin', 'library.fines.manage', 1),
('admin', 'library.fines.manage', 1),
('accountant', 'library.fines.manage', 1),
('branch_manager', 'library.fines.manage', 1),
('support_staff', 'library.fines.manage', 0),
('teacher', 'library.fines.manage', 0),
('assistant_teacher', 'library.fines.manage', 0),
('student', 'library.fines.manage', 0),
('guardian', 'library.fines.manage', 0);
