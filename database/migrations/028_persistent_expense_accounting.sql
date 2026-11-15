-- Persistent expense requests and balanced double-entry expense journals.
CREATE TABLE IF NOT EXISTS expense_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  vendor VARCHAR(160) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  expense_date DATE NOT NULL,
  status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(500) NULL,
  payment_method ENUM('cash','bank_transfer','bKash','Nagad') NULL,
  payment_reference VARCHAR(140) NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_expense_payment_reference (payment_reference),
  INDEX idx_expense_branch_status_date (branch_id, status, expense_date),
  INDEX idx_expense_status_date (status, expense_date),
  CONSTRAINT fk_expense_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_payer FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_expense_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_journal_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  expense_id BIGINT UNSIGNED NOT NULL UNIQUE,
  reference VARCHAR(140) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expense_journal_expense FOREIGN KEY (expense_id) REFERENCES expense_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expense_journal_transaction (transaction_id),
  INDEX idx_expense_journal_account (account_id, created_at),
  CONSTRAINT fk_expense_entry_transaction FOREIGN KEY (transaction_id) REFERENCES expense_journal_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_entry_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_expense_journal_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type) VALUES
('ASSET_CASH_ON_HAND', 'Cash on Hand', 'asset'),
('EXPENSE_GENERAL', 'General Operating Expense', 'expense'),
('EXPENSE_RENT', 'Rent Expense', 'expense'),
('EXPENSE_UTILITIES', 'Utilities Expense', 'expense'),
('EXPENSE_SALARIES', 'Salary Expense', 'expense'),
('EXPENSE_MARKETING', 'Marketing Expense', 'expense'),
('EXPENSE_TRANSPORT', 'Transport Expense', 'expense'),
('EXPENSE_HOSTEL', 'Hostel Expense', 'expense'),
('EXPENSE_LIBRARY', 'Library Expense', 'expense'),
('EXPENSE_SUPPLIES', 'Office Supplies Expense', 'expense');

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('super_admin', 'expenses.manage', 1), ('admin', 'expenses.manage', 1),
('accountant', 'expenses.manage', 1), ('branch_manager', 'expenses.manage', 1),
('support_staff', 'expenses.manage', 0), ('teacher', 'expenses.manage', 0),
('assistant_teacher', 'expenses.manage', 0), ('student', 'expenses.manage', 0),
('guardian', 'expenses.manage', 0);
