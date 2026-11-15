-- Monthly recurring expense templates and idempotent generated requests.
CREATE TABLE IF NOT EXISTS expense_recurring_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  vendor VARCHAR(160) NULL,
  supplier_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_expense_recurring_branch_active (branch_id, is_active),
  CONSTRAINT fk_expense_recurring_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_recurring_supplier FOREIGN KEY (supplier_id) REFERENCES expense_suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_recurring_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_expense_recurring_amount CHECK (amount > 0),
  CONSTRAINT chk_expense_recurring_day CHECK (day_of_month BETWEEN 1 AND 28)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_recurring_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT UNSIGNED NOT NULL,
  expense_month DATE NOT NULL,
  expense_id BIGINT UNSIGNED NOT NULL,
  generated_by BIGINT UNSIGNED NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_expense_recurring_template_month (template_id, expense_month),
  UNIQUE KEY uq_expense_recurring_expense (expense_id),
  CONSTRAINT fk_expense_recurring_run_template FOREIGN KEY (template_id) REFERENCES expense_recurring_templates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_recurring_run_expense FOREIGN KEY (expense_id) REFERENCES expense_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_recurring_run_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
