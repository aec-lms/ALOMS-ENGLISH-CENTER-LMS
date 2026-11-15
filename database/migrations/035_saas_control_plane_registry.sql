-- SaaS control-plane registry. This does NOT yet enable tenant data-plane isolation.
-- Tenant provisioning remains disabled until every operational table and API is tenant-scoped.
CREATE TABLE IF NOT EXISTS saas_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_key VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saas_plan_key (plan_key),
  CONSTRAINT chk_saas_plan_price CHECK (monthly_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO saas_plans (plan_key, name, monthly_price, currency) VALUES
('free', 'Free', 0.00, 'BDT'),
('basic', 'Basic', 2999.00, 'BDT'),
('standard', 'Standard', 5999.00, 'BDT'),
('professional', 'Professional', 9999.00, 'BDT'),
('enterprise', 'Enterprise', 19999.00, 'BDT');

CREATE TABLE IF NOT EXISTS saas_tenants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_key VARCHAR(63) NOT NULL,
  name VARCHAR(160) NOT NULL,
  status ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(32) NULL,
  city VARCHAR(120) NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_saas_tenant_key (tenant_key),
  INDEX idx_saas_tenant_status_created (status, created_at),
  CONSTRAINT fk_saas_tenant_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_saas_tenant_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_tenant_domains (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(253) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saas_tenant_domain (domain),
  INDEX idx_saas_tenant_domains_tenant (tenant_id, is_primary),
  CONSTRAINT fk_saas_domain_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trialing','active','past_due','canceled') NOT NULL DEFAULT 'trialing',
  billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_saas_subscription_tenant_status (tenant_id, status, ends_at),
  CONSTRAINT fk_saas_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_saas_subscription_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id) ON DELETE RESTRICT,
  CONSTRAINT fk_saas_subscription_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_saas_subscription_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('super_admin', 'saas.tenants.manage', 1),
('admin', 'saas.tenants.manage', 0),
('branch_manager', 'saas.tenants.manage', 0),
('accountant', 'saas.tenants.manage', 0),
('support_staff', 'saas.tenants.manage', 0),
('teacher', 'saas.tenants.manage', 0),
('assistant_teacher', 'saas.tenants.manage', 0),
('student', 'saas.tenants.manage', 0),
('guardian', 'saas.tenants.manage', 0);
