-- Extends the existing schema with a fine-grained RBAC data layer, DB-backed
-- reference tables for document types / transfer types / request statuses
-- (currently PHP constants), and three genuinely-missing tracking tables:
-- notifications (with read/unread state), user_sessions (login/logout
-- history), and document_validation (per-requirement validation detail).
--
-- Entirely additive: no existing table, column, or row is changed or
-- dropped. Every foreign key references rows that already exist (role_id
-- 1-4 in `roles`, existing requests/request_documents/users/clients), so
-- this applies cleanly to the live, populated database. Run once.

-- ---------------------------------------------------------------------
-- Fine-grained RBAC. `roles` already exists (1=CLIENT,2=STAFF,3=ADMIN,
-- 4=DEPARTMENT_HEAD) and is already the FK target for users.role_id /
-- clients.role_id — this layers a real permission catalog on top of it
-- instead of each page's require_role() call being the only source of
-- truth. require_role() remains the primary, already-correct enforcement;
-- user_can() (see includes/auth.php) is available for new code to adopt
-- incrementally rather than rewriting every existing page's checks.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`       VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       TINYINT NOT NULL,
  permission_id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO permissions (`key`, description) VALUES
  ('manage_staff_accounts', 'Create, edit, activate/deactivate, archive, and restore internal accounts'),
  ('view_roles', 'View the roles reference page'),
  ('manage_settings', 'Edit office settings and processing-time thresholds'),
  ('view_audit_logs', 'View the system audit log'),
  ('view_admin_dashboard', 'View the Admin dashboard'),
  ('manage_announcements', 'Create, edit, schedule, publish, and cancel announcements'),
  ('view_reports', 'View generated reports'),
  ('view_head_dashboard', 'View the Department Head dashboard'),
  ('process_requests', 'View, review, and update the status of document/land-transfer requests'),
  ('run_ai_checker', 'View AI Rule-Based Requirement Checker results'),
  ('send_notifications', 'Send notifications to clients about their requests'),
  ('view_staff_dashboard', 'View the Staff dashboard');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE `key` IN
    ('manage_staff_accounts','view_roles','manage_settings','view_audit_logs','view_admin_dashboard');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE `key` IN
    ('manage_announcements','view_reports','view_head_dashboard');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE `key` IN
    ('process_requests','run_ai_checker','send_notifications','view_staff_dashboard');

-- ---------------------------------------------------------------------
-- Reference/lookup tables — mirror the PHP constants DOC_TYPES /
-- TRANSFER_TYPES / STATUS_FLOW (includes.functions.php) exactly, which
-- themselves mirror ai_checker/checker/rules.py, so nothing currently
-- displayed or validated changes. requests.document_type / transfer_type
-- / status deliberately stay as they are (VARCHAR / ENUM) rather than
-- being converted to FK columns — these tables are the authoritative
-- list for a future admin-managed "Document Types" page, not a schema
-- change to the already-live, already-indexed `requests` table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_types (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO document_types (name) VALUES
  ('Certified True Copy of Tax Declaration (CTC-TD)'),
  ('Certification of No/With Existing Improvement'),
  ('Certification of Property/No Property Holdings'),
  ('Certification of No Liens and Encumbrances'),
  ('Certification of Assessment');

CREATE TABLE IF NOT EXISTS transfer_types (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO transfer_types (name) VALUES ('Sale'), ('Donation'), ('Estate');

CREATE TABLE IF NOT EXISTS request_statuses (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(30) NOT NULL UNIQUE,
  sort_order  TINYINT UNSIGNED NOT NULL,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  badge_class VARCHAR(20) NOT NULL DEFAULT 'slate'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO request_statuses (name, sort_order, is_terminal, badge_class) VALUES
  ('Received', 1, 0, 'slate'),
  ('Processing', 2, 0, 'amber'),
  ('Approved', 3, 1, 'green'),
  ('Rejected', 4, 1, 'red'),
  ('Out for Release', 5, 1, 'blue'),
  ('Timed Out', 6, 0, 'orange');

-- ---------------------------------------------------------------------
-- Real notifications. request_status_log.sms_body is a simulated SMS
-- transcript with no read/unread concept; this is the actual
-- notification feed, with channel/delivery_status so SMS/email
-- integration can be added later without another migration.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  client_id       INT NULL,
  request_id      INT NULL,
  type            VARCHAR(40) NOT NULL,
  message         TEXT NOT NULL,
  channel         ENUM('system','sms','email') NOT NULL DEFAULT 'system',
  delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
  read_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  INDEX idx_client_unread (client_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Login/logout history, distinct from audit_log's coarse "Logged in" /
-- "Logged out" action string — one row per session with a real end time.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_type   ENUM('client','staff','admin','head') NOT NULL,
  user_id     INT NOT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  login_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logout_at   DATETIME NULL,
  INDEX idx_user (user_type, user_id),
  INDEX idx_active (logout_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Lightweight staff workflow activity — separate in PURPOSE from
-- audit_log (accountability for administrative changes: who
-- archived/approved/rejected what). This is higher-volume, lower-stakes
-- "what did staff look at" tracking that audit_log was never meant to
-- carry, e.g. opening a request's detail page.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  action      VARCHAR(60) NOT NULL,
  request_id  INT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Per-requirement validation detail. request_documents.file_status is a
-- single ok/flagged/missing flag per uploaded file with no history; this
-- is the AI checker's actual verdict per requirement, with room for
-- staff to later record a manual re-validation (validated_by set = a
-- human overrode the automatic pass; NULL = the automatic AI verdict).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_validation (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  request_document_id INT NOT NULL,
  validation_status    ENUM('pending','valid','invalid','needs_review') NOT NULL DEFAULT 'pending',
  remarks              VARCHAR(255) NULL,
  validated_by         INT NULL,
  validated_at         DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_document_id) REFERENCES request_documents(id) ON DELETE CASCADE,
  FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_request_document (request_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
