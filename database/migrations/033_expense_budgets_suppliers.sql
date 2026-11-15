-- Monthly branch budgets and reusable supplier/payee directory.
CREATE TABLE IF NOT EXISTS expense_suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  supplier_name VARCHAR(160) NOT NULL,
  contact_person VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(500) NULL,
  tax_id VARCHAR(80) NULL,
  notes VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_expense_supplier_branch_name (branch_id, supplier_name),
  INDEX idx_expense_supplier_branch_active (branch_id, is_active),
  CONSTRAINT fk_expense_supplier_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_supplier_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_monthly_budgets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL,
  budget_month DATE NOT NULL,
  budget_amount DECIMAL(12,2) NOT NULL,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_expense_budget_branch_category_month (branch_id, category, budget_month),
  INDEX idx_expense_budget_month_branch (budget_month, branch_id),
  CONSTRAINT fk_expense_budget_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_budget_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expense_budget_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_expense_budget_amount CHECK (budget_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_record_suppliers (
  expense_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expense_record_supplier (supplier_id),
  CONSTRAINT fk_expense_record_supplier_expense FOREIGN KEY (expense_id) REFERENCES expense_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_record_supplier_supplier FOREIGN KEY (supplier_id) REFERENCES expense_suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
