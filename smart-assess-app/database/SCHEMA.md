# SMART ASSESS — Database Schema & Business Rules

Generated from the live `smart_assess` database (`SHOW CREATE TABLE` on every
table), 2026-10-11, immediately before the PHP architecture rebuild. This is
the authoritative reference the rebuild is implemented against — nothing in
this document is invented; every table/column/constraint listed here was
read directly off the running database.

## Tables and relationships

### Identity / accounts
- **`roles`** (`id` tinyint PK, `name`) — 4 fixed rows: `1=CLIENT`, `2=STAFF`,
  `3=ADMIN`, `4=DEPARTMENT_HEAD`. Referenced by both `clients.role_id` and
  `users.role_id`.
- **`clients`** — public/resident accounts. `email` UNIQUE. `role_id` is
  CHECK-constrained to `= 1` (clients can never hold an internal role).
  `status` enum `Active`/`Inactive`.
- **`users`** — internal accounts (Staff/Admin/Department Head only).
  `username` UNIQUE. `role_id` CHECK-constrained to `IN (2,3,4)` (a `users`
  row can never be role 1/CLIENT) — this is the hard DB-level guarantee that
  a client account can never function as an internal account, independent
  of any application code. `position_title` is **nullable** (no default,
  never auto-filled). Soft-archive/soft-delete columns: `archived_at`/
  `archived_by`, `restored_at`/`restored_by`, `deleted_at`/`deleted_by` — all
  self-referencing FKs onto `users.id` with `ON DELETE SET NULL`, so deleting
  the admin who archived someone never cascades into deleting the archived
  record itself.
- **`permissions`** (`key` UNIQUE) / **`role_permissions`** (composite PK
  `role_id`+`permission_id`, both FKs `ON DELETE CASCADE`) — data-backed
  permission grants per role, read by `admin/roles.php` and `user_can()`.
- **`user_sessions`** — one row per internal or client login
  (`user_type`+`user_id`, `login_at`, `logout_at` nullable while active).

### Requests
- **`requests`** — one row per Document Request or Land Transfer.
  `client_id` nullable + `ON DELETE SET NULL` (guest/anonymous submissions
  are allowed and keep their request even if no client account exists).
  `reference_no` UNIQUE (format `DR-YYYY-NNNNN` / `LT-YYYY-NNNNN`).
  `flow` enum `docreq`/`landtransfer`.
  **`status` enum: `Received`, `Processing`, `Approved`, `Rejected`,
  `Out for Release`, `Timed Out`** — this exact 6-value list is a hard
  business rule carried over unchanged; it is not the same list as either
  HTML prototype's own status vocabulary, and the rebuild must not change it,
  since every status-dependent query/badge/filter in the whole system is
  written against these exact 6 strings.
  `requirement_complete` — set by the AI Rule-Based Requirement Checker.
- **`request_documents`** — one row per uploaded (or missing) requirement
  file for a request. `UNIQUE(request_id, doc_key)`. `file_status` enum
  `ok`/`flagged`/`missing`. `ON DELETE CASCADE` from `requests`.
- **`document_validation`** — richer per-document verdict, 1:1 with
  `request_documents` (`UNIQUE(request_document_id)`), `ON DELETE CASCADE`.
  `validated_by` nullable FK to `users`, `ON DELETE SET NULL` (NULL = the
  automatic AI pass, not a human override).
- **`request_status_log`** — **append-only** history of every status this
  request has ever had. `actor` column (`client`/`staff`/`system`) records
  who caused each transition. `sms_body` holds the (simulated) notification
  text sent at that moment. **Business rule: rows here are never updated or
  deleted, only inserted** — this is what makes "preserve status history,
  don't overwrite previous events" and "no duplicate entries on repeated
  page loads" both true simultaneously: the overdue-detection sweep
  (`mark_overdue_requests()`) only ever inserts a `Timed Out` row when the
  *current* status isn't already terminal and the time since the *latest*
  logged entry (not the original `created_at`) exceeds the configured
  threshold — so re-running it on every page load is idempotent by
  construction, not by a separate "already ran" flag.
- **`request_statuses`** / **`document_types`** / **`transfer_types`** —
  lookup/reference tables (sort order, badge class, active flags) that exist
  alongside the hardcoded PHP constants (`STATUS_FLOW`, `DOC_TYPES`,
  `TRANSFER_TYPES` in `includes/functions.php`). The PHP constants are what
  the application actually reads from today; these tables exist in the
  schema but aren't the live source of truth for those values yet.

### Communication
- **`announcements`** — `audience` enum `client`/`staff`/`both`. `status`
  enum `Draft`/`Scheduled`/`Published`/`Cancelled`. `scheduled_at` +
  `publish_due_announcements()` is the same lazy-sweep idempotent pattern as
  overdue detection: a `Scheduled` row flips to `Published` the first time
  any page checks and finds `scheduled_at <= NOW()`, via one conditional
  `UPDATE`, never a background job.
- **`notifications`** — per-client inbox (`client_id` `ON DELETE CASCADE`,
  `request_id` `ON DELETE CASCADE`). `read_at` nullable = unread.
- **`help_messages`** — client support/help form submissions.

### Audit trails (two, deliberately separate)
- **`audit_log`** — admin-facing: logins, account/role changes, archive/
  restore/delete, settings changes. `actor_type` enum includes `system`.
- **`activity_logs`** — staff workflow activity (e.g. "viewed request"),
  tied 1:many to a `user_id` with `ON DELETE CASCADE` — this is exactly why
  "permanently delete" a staff account is a soft marker (`deleted_at`) and
  never a real `DELETE FROM users`: a hard delete would cascade-destroy that
  person's entire activity history.
- **`settings`** — key/value store (office name/contact, and the two
  configurable processing-deadline thresholds in days, read via
  `get_setting()`/`set_setting()`).

## Key business rules (confirmed from the live schema + the code that reads it)
1. A client account can never become an internal account, or vice versa —
   enforced at the database level via `chk_clients_role` / `chk_users_role`,
   not just by application code.
2. Status history is append-only; nothing in `request_status_log` is ever
   updated or deleted once written.
3. Overdue/`Timed Out` detection and announcement auto-publishing are both
   lazy, idempotent sweeps run on page load (no cron) — safe to call on
   every request because they only ever act on rows whose condition is
   currently true, using the *latest* log entry's timestamp as the clock
   baseline, not the original submission time.
4. "Permanently delete" on an internal account is a soft marker
   (`deleted_at`), never a real row deletion, to avoid cascading into
   `activity_logs`.
5. `position_title` has no default and is never auto-assigned — an account
   can legitimately have no position.

## Migrations applied (in `database/migrations/`, chronological)
1. `20261007_add_internal_account_contact_position.sql`
2. `20261007_announcement_audience_schedule.sql`
3. `20261007_backfill_document_validation.sql`
4. `20261007_rbac_lookup_and_tracking_tables.sql`
5. `20261007_request_timeout_tracking.sql`
6. `20261008_staff_account_archiving.sql`

All already applied to the live database and reflected in `schema.sql`
(the fresh-install baseline). The rebuild reuses this schema as-is — no
tables are dropped, recreated, or altered as part of the PHP rebuild.
