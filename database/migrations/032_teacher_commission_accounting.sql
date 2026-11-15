-- Post teacher payout completions to the accounting ledger.
CREATE TABLE IF NOT EXISTS commission_payout_journal_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payout_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
  reference VARCHAR(140) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_commission_payout_journal_request FOREIGN KEY (payout_request_id) REFERENCES commission_payout_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_payout_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_payout_journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_commission_payout_entry_transaction (transaction_id),
  INDEX idx_commission_payout_entry_account (account_id, created_at),
  CONSTRAINT fk_commission_payout_entry_transaction FOREIGN KEY (transaction_id) REFERENCES commission_payout_journal_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_commission_payout_entry_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_commission_payout_journal_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type)
VALUES ('EXPENSE_TEACHER_COMMISSION', 'Teacher Commission Expense', 'expense');
