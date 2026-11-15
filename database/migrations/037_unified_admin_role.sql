-- Consolidate legacy Super Admin accounts and permissions into the single Admin role.
-- Materialize the source rows in a derived table to avoid MySQL's target/source
-- table ambiguity while preserving the most permissive permission value.
INSERT INTO role_permissions (role, permission_key, allowed)
SELECT 'admin', legacy_permissions.permission_key, legacy_permissions.allowed
FROM (
    SELECT permission_key, allowed
    FROM role_permissions
    WHERE role = 'super_admin'
) AS legacy_permissions
ON DUPLICATE KEY UPDATE
    allowed = GREATEST(role_permissions.allowed, VALUES(allowed));

UPDATE users SET role = 'admin' WHERE role = 'super_admin';

DELETE FROM role_permissions WHERE role = 'super_admin';
