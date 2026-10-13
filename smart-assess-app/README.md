# SMART ASSESS

The PHP application renders the public and internal HTML prototypes through
the real PHP entry points and loads data from the existing MySQL schema. The
original design references remain at the repository root:

- `../smart-assess.html` — public/client interface
- `../smart-assess-internal.html` — internal staff interface

## Run locally

From the repository root, start the PHP built-in server. The helper uses the
local defaults (`127.0.0.1:3307`, database `smart_assess`, user
`root`), prompts for the DB password without echoing it, verifies
the connection, and then starts PHP:

```bash
bash smart-assess-app/run-local.sh
```

Set `DB_HOST`, `DB_PORT`, `DB_NAME`, and `DB_USER` in the environment first
only when overriding those defaults. The password can also be supplied through
`SMART_ASSESS_DB_PASS` by a trusted local secret manager; do not put it in a
command, source file, or committed `.env` file.

Open `http://127.0.0.1:8923/` for the public design and
`http://127.0.0.1:8923/internal.php` for the internal portal. The internal
portal shows its login screen until a valid internal account is authenticated.
Database credentials are read from the environment; do not put them in source
files or commit them.

The public HTML is served by `web/index.php`; internal pages are served by
`web/internal.php`; form and workflow actions are handled by `web/api.php`.
`web/prototype.php` safely injects server-generated state and
`web/assets/php-bridge.js` connects prototype actions to the API. Uploaded
documents are stored under `smart-assess-app/private_uploads/`, outside the
web document root; that directory is git-ignored.

## Internal AI message generation

The internal notification and Department Head announcement forms can request
draft wording from Ollama. The browser sends only the selected feature, topic,
and user-entered non-sensitive context to the PHP API; PHP enforces the staff
or Department Head role and calls Ollama server-to-server. The result only
fills the editable message field. It does not send a notification, save a
draft, or publish an announcement. Do not enter client names, property
details, request references, uploaded-document contents, credentials, or other
identifying information in AI context fields.

For local development, keep Ollama bound to loopback and use its default local
endpoint. The application defaults to `http://127.0.0.1:11434`; if PHP and
Ollama run in different network namespaces, set `SMART_ASSESS_OLLAMA_URL` to
the private endpoint reachable by PHP. Do not expose port `11434` to the
public internet or call it directly from a browser.

### Production configuration

Do not assume `localhost` points to Ollama after deployment. Configure
`SMART_ASSESS_OLLAMA_URL` on the application server to an HTTPS Ollama endpoint
reachable only over the deployment's private network. Restrict inbound access
to that endpoint to the application server, require TLS, and use the
organization's approved private-network and authentication controls. Do not
publish port `11434` or place endpoint credentials in source code or browser
configuration. If the production Ollama gateway requires request
authentication, add server-side credential handling through the deployment's
secret manager before enabling generation. Without this configuration, the
application should remain usable with manual message entry.

## Database setup

The application expects the existing `smart_assess` schema and uses PDO
prepared statements. Preserve the existing tables and records; use only the
additive migrations below for an existing installation.

Existing installations must apply this additive migration once before using
internal account management and profile forms:

```bash
cd smart-assess-app
mysql -h127.0.0.1 -P3307 -u'<database user>' -p smart_assess \
  < database/migrations/20261012_add_internal_account_email.sql
```

The migration adds nullable, unique `users.email`; existing internal accounts
remain intact and may have no email until edited. The app does not apply
migrations automatically. Back up the database using the project's normal
secure backup procedure before applying schema changes.

Request processing timers and staff assignment also require the additive
migration below. It adds nullable processing timestamps, a saved duration,
the accepting staff ID/name to `requests`, and a nullable actor name to
`activity_logs`; it does not reset existing request or log rows. Apply it
using a schema administrator such as your `root` MySQL account:

```bash
set -e
cd smart-assess-app
umask 077
mkdir -p "$HOME/Backups"
BACKUP="$HOME/Backups/smart_assess-before-processing-$(date +%Y%m%d-%H%M%S).sql"
mysqldump --single-transaction --routines --triggers --protocol=TCP \
  -h127.0.0.1 -P3307 -u root -p smart_assess > "$BACKUP"
test -s "$BACKUP"
ls -lh "$BACKUP"
TABLES=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('requests','activity_logs')" smart_assess)
test "$TABLES" = "2"
ENGINE=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'" smart_assess)
test "$ENGINE" = "InnoDB"
BEFORE=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse 'SELECT COUNT(*) FROM requests' smart_assess)
BEFORE_LOGS=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse 'SELECT COUNT(*) FROM activity_logs' smart_assess)
mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p smart_assess \
  < database/migrations/20261012_add_request_processing_duration.sql
AFTER=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse 'SELECT COUNT(*) FROM requests' smart_assess)
AFTER_LOGS=$(mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p \
  -Nse 'SELECT COUNT(*) FROM activity_logs' smart_assess)
test "$BEFORE" = "$AFTER"
test "$BEFORE_LOGS" = "$AFTER_LOGS"
mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p smart_assess \
  -e "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME IN ('processing_started_at','processing_completed_at','processing_duration_seconds','processing_staff_id','processing_staff_name') ORDER BY ORDINAL_POSITION"
mysql --protocol=TCP -h127.0.0.1 -P3307 -u root -p smart_assess \
  -e "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'actor_name'"
```

Run this block in a terminal from the repository root. Each `-p` prompts for
the password; do not append the password to a command. If the preflight stops
because either table is missing or `requests` is not InnoDB, stop and inspect
the actual schema instead of running every migration or the fresh-install
schema against this database.
Confirm the backup is non-empty and stored outside the repository before
proceeding. Do not proceed if the storage-engine check fails; claiming uses
row-level `SELECT ... FOR UPDATE` locks and the migration deliberately does not
convert the existing table. The migration is additive and rerunnable. Confirm
that all five request columns and `activity_logs.actor_name` appear, and that
the before/after request and activity-log counts match. Request claiming also
requires the existing `requests` table to use
InnoDB; the API checks this before relying on `SELECT ... FOR UPDATE`, and it
does not silently convert the table.

After applying it, all staff accounts see the same request lists. A staff
member claims a request from its detail screen with **Accept/Process Request**;
the database row is locked during assignment so only one staff account can
claim it. The processor name and ID, start/completion timestamps, and duration
are shown in request details and accountability reports. The Admin dashboard
also shows processing summaries. Existing Department Head navigation and
permissions are unchanged.

The requested core schema names are also exposed as read-only compatibility
views (`document_requests`, `land_transfers`, `uploaded_documents`,
`request_status_history`, `audit_logs`, and `all_user_accounts`). They project
the existing records without replacing or duplicating the writable tables.
Apply the compatibility-view migration after the `users.email` migration,
since the unified account view depends on that column. Use the existing source
tables for writes.

## Current scope and verification status

- PHP pages preserve the prototypes' markup, styles, and client-side layout;
  PHP injects database-backed announcement, request, account, and workflow
  state.
- Request submission, reference-number tracking, internal login, account
  management, status updates, notifications, and announcements have PHP API
  handlers. Write actions validate CSRF and applicable session/role
  permissions.
- The visual prototype includes controls that do not yet have persistent
  backend workflows, including time-clock entries and the AI checker. Client
  registration/profile screens, document download/review, and client inbox
  pages are also not supplied by the two design references.
- Database-backed and role-based workflows require a reachable MySQL service,
  valid local credentials, and the email migration above. Do not treat PHP
  syntax validation alone as proof of those workflows.

For code checks, run `php -l` on PHP files and `node --check` on
`web/assets/php-bridge.js`. Browser and real-database workflow testing should
be performed with non-production/test records before deployment.
