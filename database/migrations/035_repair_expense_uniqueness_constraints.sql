-- Repair expense uniqueness indexes for databases created before the index definitions were applied.
-- MySQL 8.4 does not support CREATE INDEX IF NOT EXISTS. Check the data dictionary
-- and execute ALTER TABLE only when the named unique index is absent.

SET @aec_budget_unique_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'expense_monthly_budgets'
    AND index_name = 'uq_expense_budget_branch_category_month'
);
SET @aec_budget_unique_sql = IF(
  @aec_budget_unique_exists > 0,
  'SET @aec_noop = 1',
  'ALTER TABLE expense_monthly_budgets ADD UNIQUE KEY uq_expense_budget_branch_category_month (branch_id, category, budget_month)'
);
PREPARE aec_budget_unique_stmt FROM @aec_budget_unique_sql;
EXECUTE aec_budget_unique_stmt;
DEALLOCATE PREPARE aec_budget_unique_stmt;

SET @aec_supplier_unique_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'expense_suppliers'
    AND index_name = 'uq_expense_supplier_branch_name'
);
SET @aec_supplier_unique_sql = IF(
  @aec_supplier_unique_exists > 0,
  'SET @aec_noop = 1',
  'ALTER TABLE expense_suppliers ADD UNIQUE KEY uq_expense_supplier_branch_name (branch_id, supplier_name)'
);
PREPARE aec_supplier_unique_stmt FROM @aec_supplier_unique_sql;
EXECUTE aec_supplier_unique_stmt;
DEALLOCATE PREPARE aec_supplier_unique_stmt;

SET @aec_recurring_unique_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'expense_recurring_runs'
    AND index_name = 'uq_expense_recurring_template_month'
);
SET @aec_recurring_unique_sql = IF(
  @aec_recurring_unique_exists > 0,
  'SET @aec_noop = 1',
  'ALTER TABLE expense_recurring_runs ADD UNIQUE KEY uq_expense_recurring_template_month (template_id, expense_month)'
);
PREPARE aec_recurring_unique_stmt FROM @aec_recurring_unique_sql;
EXECUTE aec_recurring_unique_stmt;
DEALLOCATE PREPARE aec_recurring_unique_stmt;
