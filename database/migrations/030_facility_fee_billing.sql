-- Monthly hostel and transport invoices with auditable collection journals.
CREATE TABLE IF NOT EXISTS facility_fee_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  service_type ENUM('hostel','transport') NOT NULL,
  hostel_allocation_id BIGINT UNSIGNED NULL,
  transport_assignment_id BIGINT UNSIGNED NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  billing_month DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  generated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_facility_hostel_month (hostel_allocation_id, billing_month),
  UNIQUE KEY uq_facility_transport_month (transport_assignment_id, billing_month),
  INDEX idx_facility_fee_branch_month_status (branch_id, billing_month, status),
  INDEX idx_facility_fee_student_month (student_user_id, billing_month),
  CONSTRAINT fk_facility_fee_hostel_allocation FOREIGN KEY (hostel_allocation_id) REFERENCES hostel_allocations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_transport_assignment FOREIGN KEY (transport_assignment_id) REFERENCES transport_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_generator FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_facility_fee_amount CHECK (amount > 0),
  CONSTRAINT chk_facility_fee_source CHECK (
    (service_type = 'hostel' AND hostel_allocation_id IS NOT NULL AND transport_assignment_id IS NULL) OR
    (service_type = 'transport' AND transport_assignment_id IS NOT NULL AND hostel_allocation_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facility_fee_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  payment_method ENUM('cash','bank_transfer','bKash','Nagad') NOT NULL,
  payment_reference VARCHAR(140) NOT NULL UNIQUE,
  received_by BIGINT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_facility_fee_payment_invoice (invoice_id, received_at),
  INDEX idx_facility_fee_payment_branch_date (branch_id, received_at),
  CONSTRAINT fk_facility_fee_payment_invoice FOREIGN KEY (invoice_id) REFERENCES facility_fee_invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_payment_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_payment_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_payment_receiver FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_facility_fee_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facility_fee_journal_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
  reference VARCHAR(140) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_facility_fee_journal_payment FOREIGN KEY (payment_id) REFERENCES facility_fee_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facility_fee_journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_facility_fee_entry_transaction (transaction_id),
  INDEX idx_facility_fee_entry_account (account_id, created_at),
  CONSTRAINT fk_facility_fee_entry_transaction FOREIGN KEY (transaction_id) REFERENCES facility_fee_journal_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facility_fee_entry_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_facility_fee_journal_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type) VALUES
('INCOME_HOSTEL_FEES', 'Hostel Accommodation Income', 'income'),
('INCOME_TRANSPORT_FEES', 'Student Transport Income', 'income');

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('super_admin', 'facility.fees.manage', 1),
('admin', 'facility.fees.manage', 1),
('accountant', 'facility.fees.manage', 1),
('branch_manager', 'facility.fees.manage', 1),
('support_staff', 'facility.fees.manage', 0),
('teacher', 'facility.fees.manage', 0),
('assistant_teacher', 'facility.fees.manage', 0),
('student', 'facility.fees.manage', 0),
('guardian', 'facility.fees.manage', 0);
