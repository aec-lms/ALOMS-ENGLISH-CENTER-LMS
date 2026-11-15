-- Explicit access to consolidated, branch-scoped accounting statements.
INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('super_admin', 'accounting.reports.view', 1),
('admin', 'accounting.reports.view', 1),
('accountant', 'accounting.reports.view', 1),
('branch_manager', 'accounting.reports.view', 1),
('support_staff', 'accounting.reports.view', 0),
('teacher', 'accounting.reports.view', 0),
('assistant_teacher', 'accounting.reports.view', 0),
('student', 'accounting.reports.view', 0),
('guardian', 'accounting.reports.view', 0);
