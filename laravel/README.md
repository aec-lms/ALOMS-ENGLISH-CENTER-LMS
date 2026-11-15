# AEC LMS — Laravel 12 Modular Plugin Platform

Complete Laravel 12 (`PHP 8.3+`) modular Learning Management System located under `/laravel/`.

## Architecture Overview

### Protected Core (`/laravel/app/Core/`)
The Core is permanently protected against plugin disabling or uninstallation:
- **Authentication & RBAC**: `User`, `Role`, `Permission`, `RolePermissionMiddleware`, Sanctum API tokens (`personal_access_tokens`).
- **Fresh Empty-Database Installer**: `FreshInstallerService`, `/install` wizard, and `php artisan aec:install-fresh`. Refuses installation if the database already contains any tables, never drops/truncates databases, and never imports legacy records (`legacy_records_imported = false`).
- **Plugin Lifecycle Engine**: `PluginManager`, `PluginManifest`, `BasePluginServiceProvider`, `EnsurePluginEnabled` middleware, encrypted settings via Laravel `Crypt`, and data-preserving uninstall (`onUninstall`).
- **Security & Governance**: `AuditLogger`, encrypted ZIP+AES-256-CBC+HMAC-SHA256 `BackupService`, and SHA-256 + HMAC-SHA256 signed `UniversalUpdateService`.

### Modular Plugins (`/laravel/plugins/modules/`)
1. `branch-management` — Campus branches & branch isolation foundation
2. `student-management` — Branch-scoped admissions & student profiles
3. `teacher-management` — Instructor profiles & specializations
4. `guardian-management` — Guardian profiles & student-guardian links
5. `course-management` — Academic sessions, courses, lessons, batches, enrollments
6. `exam-management` — Exams, question bank, server-side graded attempts
7. `attendance-management` — Daily batch attendance registers
8. `fees-management` — Fee categories, student invoices, discounts
9. `payment-management` — Immutable ledger, offline payments, refunds
10. `gateway-sslcommerz` — Dedicated SSLCOMMERZ Hosted Checkout + IPN hash verification
11. `gateway-card` — Dedicated Visa/Mastercard Tokenized Checkout + webhook HMAC (rejects raw PAN/CVV)
12. `notice-board` — Role-targeted & pinned notices
13. `notification-center` — In-app notifications & encrypted SMS/Push settings
14. `cms-website` — Page builder, slider builder, carousel builder, SEO, inquiries
15. `reports-analytics` — Operational metrics & CSV audit exports
16. `mobile-app-api` — Sanctum-authenticated learner API endpoints (`/api/mobile/*`)
17. `role-ai-assistant` — Optional server-side Role-Based AI Assistant with encrypted keys & role allowlists

## Local Setup & Verification (PHP 8.3+ & Composer Required)

```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan aec:install-fresh --name="Admin" --email="admin@example.com" --password="StrongPassword!2026"
php artisan aec:sync-plugins
php artisan test
```
