# Alom's English Center — PHP API

Target runtime: PHP 8.3+, PDO MySQL, MySQL 8 / compatible MariaDB with InnoDB, HTTPS.

## Server configuration

Set these values in the hosting environment or in a server-side configuration file outside the public web root:

- `AEC_DB_HOST`
- `AEC_DB_NAME`
- `AEC_DB_USER`
- `AEC_DB_PASS`
- `AEC_APP_URL` — canonical HTTPS site URL used for verification and password-reset links.
- `AEC_MAIL_FROM` — valid sender mailbox for account verification and recovery emails.

Never place database credentials in a `VITE_*` variable, JavaScript bundle, Git commit, or public directory.

## AI Admin Copilot (planning-only)

- `ai-assistant.php` is restricted to active `admin` and `super_admin` sessions and requires the existing CSRF token.
- Configure `AEC_AI_API_KEY` and optionally `AEC_AI_MODEL` in the private server config outside the document root. Never expose the provider key in frontend variables or source control.
- Requests are limited to 4,000 characters and 12 per 10 minutes per session. Prompts and responses are not persisted by this endpoint.
- This initial version only returns advice and plans. It cannot execute tools, read arbitrary LMS records, mutate settings/database, edit code or deploy releases.
- See [AI-ADMIN-COPILOT.md](../docs/AI-ADMIN-COPILOT.md) for configuration and the safe expansion plan.

## Isolated plugin package updater

- `plugin-updater.php` provides a super-admin-only, CSRF-protected signed data-package installer with private storage, strict ZIP path/type/size checks, SHA-256 payload verification, staging, atomic activation, rollback snapshots and audit logging.
- Configure `AEC_PLUGIN_SIGNING_KEY` (random secret, at least 32 characters) outside the web root and enable PHP's ZIP extension before using it. Without the key, installation and rollback fail closed.
- Only signed JSON data packages are accepted. Executable PHP/JS/HTML/CSS plugins are intentionally unsupported; reviewed first-party adapters must execute integrations. See [PLUGIN-UPDATER.md](../docs/PLUGIN-UPDATER.md).
- Routes: `GET /api/plugin-updater.php?action=list`, `POST /api/plugin-updater.php?action=install` (multipart field `package`), `POST /api/plugin-updater.php?action=toggle` (JSON body with plugin id and boolean `enabled`), and `POST /api/plugin-updater.php?action=rollback` (JSON body with plugin id and snapshot). Mutations require the LMS session and `X-CSRF-Token`.

## Implemented API areas

- Authentication and student accounts: `auth.php`, `accounts.php` — session login, registration, verification, recovery, own profile and audited activation.
- Branch registry: `branches.php` — administrator branch CRUD, branch deactivation without deleting history, and branch-manager own-branch read access.
- Courses/categories: `courses.php`, `categories.php` — catalogue, course CRUD and category management. Branch managers can manage only courses and categories assigned to their own active branch; global categories remain shared and cannot be edited by branch managers.
- Reusable content: `content.php` — lessons/articles/videos, multi-course links and student progress/notes. This content library remains global; branch managers are intentionally not granted content-management permission.
- Users/enrollment: `users.php`, `enrollments.php` — admin and branch-scoped user management, enrollment records and branch ownership checks.
- Payments: `payments.php` — manual payment submission, branch-scoped review, provider-confirmed refund recording, double-entry refund reversals, student payment history and branch-filtered ledger views.
- Exams/question bank: `exams.php`, `question_bank.php` — timed attempts, autosaved answers, server-side grading and reusable questions. Branch-manager exam/question-bank permissions remain disabled until those APIs receive complete branch scoping.
- Attendance: `attendance.php` — branch-scoped roster, daily attendance upsert, reports and student-owned history.
- Certificates: `certificates.php` — issuance, student wallet, public verification, revocation and branch-scoped management.
- Live classes: `live_classes.php` — persistent schedule, instructor ownership, enrolled-student visibility, audited lifecycle actions and branch scoping.
- Notifications: `notifications.php` — per-user persistent inbox, read/unread state, user-owned deletion and admin-only role-targeted broadcasts. In-app delivery only; no email or SMS is sent by this endpoint.
- Website CMS: `cms.php` — public website settings, blog posts, SEO metadata and testimonials; homepage section visibility, theme tokens, e-book catalog and product catalog settings are validated and persisted.
- Admission inquiries: `admissions.php` — public application intake with rate limiting and admin-only review; does not create accounts or accept/verify payments.
- Physical library: `library.php` — branch-scoped book catalog, copy inventory, student issue/return register, audit events, late-fine calculation and fine collection with balanced accounting journals. Fine collection/accounting integration is not yet included.
- Transport: `transport.php` — branch-scoped route/vehicle registry, driver and stop details, passenger-capacity enforcement, one active route assignment per student and audited pass termination.
- Hostel: `hostel.php` — branch-scoped room inventory, bed-capacity enforcement, one active hostel allocation per student and audited vacating.
- Expenses: `expenses.php` — branch expense requests, administrator/accountant approval, payment recording and balanced double-entry expense journal posting; monthly branch/category budgets with committed-spend tracking, and a branch-scoped supplier directory linked to expense records.

## Useful routes

- `GET /api/branches.php?action=list` — administrator branch list; branch managers receive only their assigned branch.
- `POST /api/branches.php?action=create` — `branches.manage` + CSRF.
- `POST /api/branches.php?action=update&id=123` — `branches.manage` + CSRF.
- `POST /api/branches.php?action=deactivate&id=123` — `branches.manage` + CSRF; preserves historical records.
- `GET /api/library.php?action=branches|students|list|loans` — `library.manage`; branch managers and assigned support staff receive only their assigned branch and active students in that branch.
- `POST /api/library.php?action=create` — `library.manage` + CSRF; requires an active branch and initializes copy inventory.
- `POST /api/library.php?action=issue` — `library.manage` + CSRF; locks the book row, validates student/branch and decrements available copies atomically.
- `POST /api/library.php?action=return` — `library.manage` + CSRF; closes an active loan once, restores inventory and calculates late fines from the saved daily rate.
- `POST /api/library.php?action=archive` — `library.manage` + CSRF; only archives a book after all copies have been returned.
- `GET /api/library.php?action=my_loans` — authenticated students only; returns only the current student's loan history.
- `GET /api/facility-fees.php?action=branches|list` — authorized finance/facility staff can view branch-scoped hostel and transport invoices; students use `action=my` to see only their own invoices.
- `POST /api/facility-fees.php?action=generate` — `facility.fees.manage` + CSRF; generates idempotent monthly invoices from hostel allocations or transport assignments active at any time during the selected month.
- `POST /api/facility-fees.php?action=pay` — `facility.fees.manage` + CSRF; records partial/full payment and posts a balanced debit to cash/bank/clearing and credit to the matching hostel/transport income account.
- `GET /api/library.php?action=fine_branches|fine_list|fine_payments` — users with `library.fines.manage` permission; branch managers are restricted to their own branch.
- `POST /api/library.php?action=fine_pay` — `library.fines.manage` + CSRF; accepts partial/full fine payment and posts debit to cash/bank/clearing and credit to library fine income in one transaction.
- `GET /api/transport.php?action=branches|students|list|assignments` and `GET /api/hostel.php?action=branches|students|rooms|allocations` — permission-protected and branch-scoped for branch managers/assigned support staff.
- `POST /api/transport.php?action=create|assign|end|archive` — `transport.manage` + CSRF; route capacity and active student assignments are enforced server-side.
- `POST /api/hostel.php?action=create_room|allocate|vacate|archive_room` — `hostel.manage` + CSRF; bed capacity and active student allocations are enforced server-side.
- `GET /api/expenses.php?action=branches|list|summary` — branch managers see only their own branch; administrators/accountants can filter across active branches.
- `POST /api/expenses.php?action=create` — `expenses.manage` + CSRF; creates a pending branch expense request.
- `POST /api/expenses.php?action=approve|reject&id=123` — administrators/accountants only; rejection requires a reason.
- `POST /api/expenses.php?action=pay&id=123` — administrators/accountants only; requires actual payment method/reference and posts a balanced debit-to-expense / credit-to-cash-or-clearing journal.
- `GET /api/expenses.php?action=budgets&month=YYYY-MM` — returns branch-scoped monthly category budgets, approved/paid committed spend and remaining/over-budget amounts.
- `POST /api/expenses.php?action=budget_save` — administrators/accountants only; creates or updates one branch/category/month budget.
- `GET /api/expenses.php?action=suppliers&active=1` — branch-scoped supplier directory.
- `POST /api/expenses.php?action=supplier_create` and `POST /api/expenses.php?action=supplier_archive&id=123` — maintain suppliers without deleting historic expense references; expense creation may include `supplier_id` from the same branch.
- `GET /api/expenses.php?action=recurring_list` — branch-scoped recurring expense templates.
- `POST /api/expenses.php?action=recurring_create` and `POST /api/expenses.php?action=recurring_archive&id=123` — administrators/accountants manage monthly templates with optional branch supplier links.
- `POST /api/expenses.php?action=recurring_generate` — administrators/accountants create pending expense requests for a selected month; a unique template/month run record prevents duplicate generation on retries. The generated requests enter the standard approval/payment workflow; no cron job is configured automatically.
- `GET /api/reports.php?type=expenses&period=current_month` — branch-scoped expense analytics by date, category and status; CSV export is available in the Reports & Analytics screen.
- `GET /api/reports.php?type=facility_fees&period=current_month` — branch-scoped hostel/transport fee receipts by date and service; CSV export is available in Reports & Analytics.
- `GET /api/reports.php?type=trial_balance&period=current_month` — cumulative posted journal balances as of the selected period end, with debit/credit balance columns.
- `GET /api/reports.php?type=income_statement&period=current_month` — posted income and expense journal totals for the selected period. Both require `accounting.reports.view` and enforce branch scope. Teacher payout completion journals are included in both statements.
- `GET /api/attendance.php?action=roster&course_id=123&date=YYYY-MM-DD` — `attendance.manage`; branch managers are restricted to their own course branch.
- `POST /api/attendance.php?action=save` — `attendance.manage` + CSRF and branch/enrollment checks.
- `GET /api/attendance.php?action=my` — authenticated user's own history.
- `POST /api/admissions.php?action=submit` — public inquiry; rate-limited; pending status only.
- `GET /api/admissions.php?action=list` — `admissions.manage`.
- `POST /api/admissions.php?action=update&id=123` — `admissions.manage` + CSRF.
- `POST /api/accounts.php?action=register` — public; creates an inactive student pending email verification.
- `POST /api/accounts.php?action=verify_email` and `POST /api/accounts.php?action=resend_verification` — public, single-use token and rate-limited generic resend response.
- `POST /api/accounts.php?action=request_password_reset` and `POST /api/accounts.php?action=reset_password` — public, generic response, hashed expiring single-use token.
- `GET/POST /api/accounts.php?action=profile` — authenticated; profile writes require CSRF.
- `POST /api/accounts.php?action=admin_activate&id=123` — `users.manage` + CSRF; verified student only.
- `GET /api/live_classes.php?action=list` — students see enrolled-course sessions; teachers see their own sessions; branch managers see their branch; administrators see all.
- `POST /api/live_classes.php?action=create` — `live_classes.manage` + CSRF; validates course, instructor, Bangladesh-local date/time and provider HTTPS URL.
- `POST /api/live_classes.php?action=update&id=123` — `live_classes.manage` + CSRF; validates status transition and optional HTTPS recording URL.
- `POST /api/live_classes.php?action=cancel&id=123` — `live_classes.manage` + CSRF; cancellation is audited, not hard-deleted.
- `GET /api/notifications.php?action=list` — authenticated user's own inbox with unread count.
- `POST /api/notifications.php?action=mark_read&id=123` — authenticated user marks only their own notification as read.
- `POST /api/notifications.php?action=mark_all_read` — marks the authenticated user's unread notifications as read.
- `POST /api/notifications.php?action=delete&id=123` — soft-deletes only the authenticated user's notification.
- `POST /api/notifications.php?action=broadcast` — `notifications.broadcast` + CSRF; sends an in-app announcement to active users in the selected role audience; capped at 10,000 recipients and 20 broadcasts per admin per hour.
- `POST /api/payments.php?action=refund&id=123` — `payments.manage` + CSRF; records a refund only after staff have processed it with the external provider, requires the provider refund reference and reason, and posts a balanced reversing journal.
- `GET /api/payments.php?action=reconcile` — `payments.manage`; reports unbalanced or incomplete journals without modifying ledger data.
- `POST /api/storefront.php?action=ebook_submit` — authenticated + CSRF; submits a bKash/Nagad reference using the price stored in public CMS settings. The e-book is not unlocked until a staff member verifies the transaction.
- `GET /api/storefront.php?action=my_ebooks` — authenticated; returns verified e-book entitlements and available private-file IDs.
- `GET /api/media.php?action=list&content_id=123`, `POST /api/media.php?action=upload`, and `GET /api/media.php?action=download&id=123` — `content.manage` for library management; upload private course media (JPG, PNG, WebP, GIF, PDF, MP3, M4A, OGG, MP4 and WebM; maximum 20 MB). Downloads require an authenticated content manager or an enrolled student with access to the linked course. Files are stored under `storage/private/media`, outside the public web root; the API checks MIME type and streams files with private/no-store headers.
- `POST /api/media.php?action=delete` — `content.manage`; moves an asset to a recoverable 30-day trash and denies further downloads.
- `GET /api/media.php?action=trash`, `POST /api/media.php?action=restore`, and `POST /api/media.php?action=purge` — Admin-only retention controls. Restore verifies stored size and SHA-256 while the 30-day window remains open; permanent purge is rejected until `purge_after` has expired. Upload, trash, restore and purge actions are audited. Migrations 022 and 041 create the media metadata and retention columns.
- `GET /api/storefront.php?action=ebook_files` and `POST /api/storefront.php?action=ebook_upload` — `cms.manage`; upload/list private PDF assets (PDF MIME/signature checked, maximum 20 MB).
- `GET /api/storefront.php?action=ebook_download&id=ebook-id` — authenticated; streams private PDFs only to administrators, free e-book users, or students with a verified purchase.
- `GET /api/storefront.php?action=ebook_orders` and `POST /api/storefront.php?action=ebook_verify|ebook_reject&id=123` — `storefront.orders.manage`; staff review of manual e-book payments.
- `POST /api/storefront.php?action=product_submit` — authenticated + CSRF; creates a cash-on-delivery order using the server-side CMS product price and stock flag.
- `GET /api/storefront.php?action=product_orders` and `POST /api/storefront.php?action=product_update&id=123` — `storefront.orders.manage`; staff fulfilment queue and status updates.

Authenticated write requests require the `X-CSRF-Token` header returned by login/session endpoints. Password resets/changes, account deactivation/reactivation, role changes and student email changes revoke old sessions through a per-user session version. Session cookies are HttpOnly, SameSite and secure when served over HTTPS. Permission checks are enforced server-side; hiding a dashboard control is not authorization.

## Schema migrations

For a new or existing deployment, configure private server-side values `AEC_DB_HOST`, `AEC_DB_NAME`, `AEC_DB_USER`, `AEC_DB_PASS` (and optional `AEC_DB_PORT`) and run the migration runner from SSH/Terminal in the project root:

```bash
php scripts/migrate.php --status
php scripts/migrate.php --dry-run
php scripts/migrate.php
```

The runner is CLI-only, obtains a MySQL advisory lock, applies migrations in filename order, records SHA-256 checksums in `aec_schema_migrations`, skips already-applied files, and refuses to run if an applied migration file has been edited or removed. Add a new numbered migration instead of changing an applied file. MySQL DDL may auto-commit, so a failed migration must be inspected and safely rerun; the runner does not claim transactional rollback.

Migrations are applied in filename order. Current application schema includes `001_core_schema.sql` through `032_teacher_commission_accounting.sql`. Migration 017 creates the branch registry, imports existing branch identifiers, adds branch ownership/FKs to users, courses, categories, enrollments, payments, attendance, certificates and live classes, and seeds branch-scoped manager permissions. Migration 018 adds provider-confirmed refunds, reversal journal references, payment refund status and ledger integrity checks. Migration 019 adds the persistent per-user notification inbox and administrator broadcast permission. Migration 020 adds persistent e-book payment requests and product orders with admin review permissions. Migration 021 adds private PDF file metadata for authenticated upload and entitlement-checked download. Migration 022 adds private course media, 023 persistent marksheets, 024 teacher commission rules and payout requests, 025 academic batches/routines, and 026 branch-scoped physical library inventory and loan history. These migrations are designed to be safely rerunnable.

## Authorization and remaining limitations

Branch managers are scoped server-side to their assigned branch for users, courses, categories, enrollments, payments, attendance, certificates and live classes. They cannot manage the global branch registry or shared content library. Branch-manager permissions for exams, question bank, CMS, global reporting and other modules remain disabled until those endpoints have equivalent branch scoping. A missing branch assignment is denied, not treated as global access.

This is not yet a production-ready full LMS. Remaining release gates include optional MFA; production email delivery smoke tests on cPanel; complete branch scoping for exams, question bank, reusable content, notifications, reports, commissions and remaining facilities; fine collection/accounting integration for the library and monthly hostel/transport fee collection; complete branch scoping for any future facilities workflows; production media upload and private file delivery; live payment gateway callbacks/signature verification, automated provider refunds, cash desk and invoices; stronger immutable-ledger enforcement and external bank/provider reconciliation; full installer and upgrade/rollback tooling; backup/restore; browser E2E/security testing; and a clean cPanel deployment rehearsal. The refund API records a refund only after staff have processed it externally; it does not call bKash, Nagad or a bank API. E-book payment references are also manually verified by staff; there is no automatic bKash/Nagad gateway callback. Premium PDF files are stored under `storage/private/ebooks` with direct Apache access denied; downloads are streamed only after entitlement checks. The administrator still needs to upload the real PDF assets. Notification broadcasts are in-app only; cPanel SMTP/email delivery, SMS delivery, scheduled digests and provider delivery receipts remain separate release gates. A CLI migration runner and Apache access-control/SPA rules exist; these do not replace a full installer. Do not treat the project as ready to deploy until those gates pass.

## Certificate registry

Migration: `database/migrations/014_certificates.sql`; branch ownership is added by migration 017.

- `GET /api/certificates.php?action=verify&cert_no=...` — public credential lookup; returns only public fields and marks revoked credentials invalid.
- `GET /api/certificates.php?action=my` — authenticated student's own certificate wallet.
- `GET /api/certificates.php?action=list&q=...` — `certificates.manage`; branch managers receive only their branch.
- `GET /api/certificates.php?action=students&q=...` — permission-protected active student search, branch-filtered for branch managers.
- `POST /api/certificates.php?action=issue` — `certificates.manage` + CSRF; requires active enrolment and course completion.
- `POST /api/certificates.php?action=revoke&id=...` — `certificates.manage` + CSRF; requires a reason and writes an audit event.

Certificate numbers use 128 bits of cryptographic randomness. One certificate record is allowed per student/course pair; revocation is permanent and remains visible in public verification.


## Unified administrator role

The LMS uses one administrator role: `admin`. The former `super_admin` role is consolidated by migration `037_unified_admin_role.sql`; existing accounts are retained and promoted to `admin`, and permission grants are merged using the most permissive value. The Admin role retains full administrator permissions. Do not create or assign a separate `super_admin` role.

## Promotion campaigns
- `GET /api/promotions.php?action=active&placement=homepage_featured` — public, returns currently active and in-date promotion placements.
- `POST /api/promotions.php?action=track` — public impression/click event with a daily visitor hash and 30-minute duplicate suppression; body: `{"campaign_id": 1, "event_type": "impression"}`.
- `GET /api/promotions.php?action=list` — Admin-only campaign list with impression, click and conversion counts.
- `POST /api/promotions.php?action=create` — Admin-only campaign creation; requires session and CSRF token.
- `POST /api/promotions.php?action=status&id=1` — Admin-only pause/resume/cancel; requires session and CSRF token.
- Supported campaign targets are courses, the institution, and branches. Scheduling is evaluated against server time; expired campaigns are never returned by the public active endpoint.
- Conversion counts are reserved for trusted server-side enrollment/payment integration; the public tracking endpoint cannot submit conversions.


## Persistent notice board
- `GET /api/notices.php?action=visible` — authenticated notices filtered by audience role, branch and schedule; includes the caller's read receipt.
- `GET /api/notices.php?action=list` — Admin-only notice list with read counts.
- `POST /api/notices.php?action=create` — Admin-only create/publish/schedule; JSON fields: `title`, `body`, `audience_role`, optional `branch_id`, `starts_at`, `ends_at`; requires CSRF.
- `POST /api/notices.php?action=status&id=1` — Admin-only draft/publish/archive; requires CSRF.
- `POST /api/notices.php?action=mark_read` — authenticated read receipt; body: `{"notice_id":1}`; requires CSRF.

## Marketing, SEO and verification
- `GET /api/marketing-settings.php?action=public` — safe public tracking and SEO configuration; never returns provider secrets.
- `GET /api/marketing-settings.php?action=admin_get` and `POST /api/marketing-settings.php?action=save` — Admin-only persistent settings; requires CSRF.
- `GET /api/seo.php?action=meta` — public SEO metadata and Search Console verification token.
- `GET /sitemap.xml` and `GET /robots.txt` — dynamic sitemap and robots rules from the public site settings.
- `POST /api/accounts.php?action=request_verification` — authenticated user requests an email verification link; the same single-use hashed token lifecycle supports student, guardian, teacher and branch-manager accounts.
- `POST /api/phone-verification.php?action=request` — authenticated user requests an SMS OTP; body: `{"phone":"+8801XXXXXXXXX"}`. OTP expires in five minutes, is stored hashed, and is limited to three requests per 30 minutes.
- `POST /api/phone-verification.php?action=test` — Admin-only SMS gateway smoke test; body: `{"phone":"+8801XXXXXXXXX"}`; sends a real test SMS and records a redacted audit event.
- `POST /api/phone-verification.php?action=verify` — authenticated OTP confirmation; body: `{"code":"123456"}`; maximum five attempts per issued code.
- Boost conversion events are recorded server-side after course enrollment creation or payment verification, attributed to a prior campaign click within 30 days and deduplicated per user/campaign. Public clients cannot submit conversion events.
- SMS adapter uses a configured HTTPS JSON endpoint and bearer token. Configure `AEC_SMS_API_URL`, `AEC_SMS_API_KEY`, and optional `AEC_SMS_SENDER_ID` privately on the server. The provider endpoint must accept JSON `to`, `message`, and `sender_id` fields.
- Account verification/recovery emails are encrypted at rest in `email_outbox` using a dedicated AES-256-GCM key. The CLI worker `php scripts/process-email-queue.php --limit=50` retries transient transport failures with bounded exponential backoff; schedule it from cPanel Cron every five minutes. Configure `AEC_MAIL_FROM`, `AEC_APP_URL` and the installer-generated `AEC_MAIL_ENCRYPTION_KEY` only in private server configuration. Admin can inspect masked recipients and queue status at `GET /api/accounts.php?action=email_delivery`. A `sent` status means the host mail transport accepted the message, not that the recipient's inbox delivery or bounce was confirmed.
- Meta Conversions API access token, when enabled by a later server-side event adapter, belongs in private `AEC_META_CAPI_TOKEN`, never in browser storage.


## Email delivery queue\n- `GET /api/accounts.php?action=email_delivery` — Admin-only masked recipient/status view of the encrypted outbox.\n- `php scripts/process-email-queue.php --limit=50` — CLI-only worker; serializes workers with a MySQL advisory lock, recovers expired processing leases, decrypts messages in memory and retries failed mail transport handoffs.\n- The message body is AES-256-GCM encrypted at rest. Never rotate `AEC_MAIL_ENCRYPTION_KEY` while queued messages exist; doing so makes them undecryptable.\n- Transport status records local MTA acceptance, not final inbox delivery; provider-specific bounce webhooks remain a future integration.\n\n## Safe AI Admin Copilot actions
- `POST /api/ai-assistant.php` returns a structured reply and, when appropriate, one typed proposal. It never applies the proposal.
- `POST /api/ai-actions.php?action=preview` validates an allowlisted action and stores a ten-minute preview. Supported actions: `update_marketing_settings`, `create_notice`, `update_notice`, `set_notice_status`, `create_promotion`, `set_promotion_status`.
- `POST /api/ai-actions.php?action=approve` requires the owning Admin session, CSRF, proposal still pending/unexpired, and exact confirmation `APPLY`. It stores a before-image backup, applies within a DB transaction and audits the result.
- `POST /api/ai-actions.php?action=rollback` requires the owning Admin, CSRF and exact confirmation `ROLLBACK`. It refuses rollback if the target has changed since the approved action.
- AI actions cannot execute arbitrary SQL, shell, PHP, filesystem changes, user-role/password changes, payments or financial operations. Code changes must continue through a separate branch, PR and CI.
