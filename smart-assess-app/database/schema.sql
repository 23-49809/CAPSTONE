-- SMART ASSESS: Document Request and Land Transfer Management System
-- Database schema (MySQL / MariaDB) — v2: real role-based account model.
--
-- Usage:
--   mysql -u root -p < database/schema.sql
--
-- WARNING: this DROPS and recreates the `smart_assess` database. This is a
-- development/defense-prep schema — back up first if you've entered real
-- data you care about.

DROP DATABASE IF EXISTS smart_assess;
CREATE DATABASE smart_assess CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'smart_assess_app'@'localhost' IDENTIFIED BY 'SmartAssess_2026!';
GRANT ALL PRIVILEGES ON smart_assess.* TO 'smart_assess_app'@'localhost';
FLUSH PRIVILEGES;

USE smart_assess;

-- ---------------------------------------------------------------------
-- Roles registry (per Chapter 3 "Database Role Structure"):
--   1 = CLIENT, 2 = STAFF, 3 = ADMIN, 4 = DEPARTMENT_HEAD
-- CLIENT accounts live in `clients`; STAFF/ADMIN/DEPARTMENT_HEAD accounts
-- live in `users`. Splitting them into two tables (rather than one shared
-- accounts table with a role flag) is a deliberate security boundary: the
-- public client portal's login can only ever authenticate against
-- `clients`, and the internal portal's login can only ever authenticate
-- against `users` — a bug in a role-check `if` statement cannot leak
-- access between the two, because the two portals literally query
-- different tables. `role_id` still ties every account to exactly one
-- row in `roles`, satisfying the "every account has exactly one role"
-- requirement.
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id   TINYINT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (id, name) VALUES
  (1, 'CLIENT'), (2, 'STAFF'), (3, 'ADMIN'), (4, 'DEPARTMENT_HEAD');

-- ---------------------------------------------------------------------
-- Internal accounts: Assessor's Staff / Admin / Department Head only.
-- Authenticated exclusively by the internal portal (/internal/login.php).
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  role_id       TINYINT NOT NULL,
  name          VARCHAR(150) NOT NULL,
  username      VARCHAR(80)  NOT NULL UNIQUE,
  contact_number VARCHAR(20) NULL,
  position_title VARCHAR(80) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  -- Active -> Archived -> Restored or Permanently Deleted. "Permanently
  -- Deleted" is deliberately still a soft marker (deleted_at), not a real
  -- row removal — see the archiving migration for why: a hard delete here
  -- would cascade-destroy activity_logs history via its FK.
  archived_at   DATETIME NULL,
  archived_by   INT NULL,
  restored_at   DATETIME NULL,
  restored_by   INT NULL,
  deleted_at    DATETIME NULL,
  deleted_by    INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_users_role CHECK (role_id IN (2,3,4)),
  INDEX idx_archived_at (archived_at),
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Client accounts (Assessor's Clients). Optional: a client may still
-- submit a Document Request or Land Transfer, and track it by reference
-- number, with no account at all — this table only backs the added
-- "My Requests" / profile / notifications experience for clients who
-- choose to register.
-- ---------------------------------------------------------------------
CREATE TABLE clients (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  role_id        TINYINT NOT NULL DEFAULT 1,
  first_name     VARCHAR(80)  NOT NULL,
  last_name      VARCHAR(80)  NOT NULL,
  email          VARCHAR(150) NOT NULL UNIQUE,
  contact_number VARCHAR(20)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  status         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT chk_clients_role CHECK (role_id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Requests: Document Request (1.1) and Land Transfer (1.2). `client_id`
-- is nullable on purpose — set when a logged-in client submits, left
-- NULL for an anonymous submission (still fully trackable by reference
-- number either way).
-- ---------------------------------------------------------------------
CREATE TABLE requests (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  client_id             INT NULL,
  reference_no          VARCHAR(20) NOT NULL UNIQUE,
  flow                  ENUM('docreq','landtransfer') NOT NULL,
  first_name            VARCHAR(80)  NOT NULL,
  middle_name           VARCHAR(80)  NULL,
  last_name             VARCHAR(80)  NOT NULL,
  contact_number        VARCHAR(20)  NOT NULL,
  email                 VARCHAR(150) NOT NULL,
  address_line          VARCHAR(255) NOT NULL,
  province              VARCHAR(80)  NOT NULL DEFAULT 'Batangas',
  city                  VARCHAR(80)  NOT NULL DEFAULT 'Mabini',
  zip_code              VARCHAR(10)  NOT NULL,
  document_type         VARCHAR(120) NULL,
  transfer_type         VARCHAR(40)  NULL,
  purpose               VARCHAR(80)  NOT NULL,
  arp_number            VARCHAR(60)  NOT NULL,
  property_address      VARCHAR(255) NOT NULL,
  barangay              VARCHAR(100) NOT NULL,
  is_owner              TINYINT(1)   NOT NULL DEFAULT 1,
  status                ENUM('Received','Processing','Approved','Rejected','Out for Release','Timed Out')
                           NOT NULL DEFAULT 'Received',
  requirement_complete  TINYINT(1)   NOT NULL DEFAULT 0,
  advisory              TEXT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  INDEX idx_status (status),
  INDEX idx_flow (flow),
  INDEX idx_barangay (barangay),
  INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_documents (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  request_id    INT NOT NULL,
  doc_key       VARCHAR(40)  NOT NULL,
  label         VARCHAR(150) NOT NULL,
  original_name VARCHAR(255) NULL,
  stored_path   VARCHAR(255) NULL,
  mime_type     VARCHAR(100) NULL,
  file_size     INT NULL,
  file_status   ENUM('ok','flagged','missing') NOT NULL DEFAULT 'missing',
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_request_doc (request_id, doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Fine-grained RBAC, layered on top of `roles` above. require_role() in
-- includes/auth.php remains the primary, already-tested enforcement on
-- every page; user_can() is available for new code to adopt incrementally.
-- ---------------------------------------------------------------------
CREATE TABLE permissions (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`       VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
  role_id       TINYINT NOT NULL,
  permission_id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (`key`, description) VALUES
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

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE `key` IN
    ('manage_staff_accounts','view_roles','manage_settings','view_audit_logs','view_admin_dashboard');
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE `key` IN
    ('manage_announcements','view_reports','view_head_dashboard');
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE `key` IN
    ('process_requests','run_ai_checker','send_notifications','view_staff_dashboard');

-- Reference/lookup tables — mirror DOC_TYPES / TRANSFER_TYPES / STATUS_FLOW
-- in includes/functions.php (which themselves mirror ai_checker's rules.py)
-- exactly. requests.document_type / transfer_type / status deliberately
-- stay as they are (VARCHAR / ENUM) — these tables back a future
-- admin-managed reference page, not a type change to a live column.
CREATE TABLE document_types (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO document_types (name) VALUES
  ('Certified True Copy of Tax Declaration (CTC-TD)'),
  ('Certification of No/With Existing Improvement'),
  ('Certification of Property/No Property Holdings'),
  ('Certification of No Liens and Encumbrances'),
  ('Certification of Assessment');

CREATE TABLE transfer_types (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO transfer_types (name) VALUES ('Sale'), ('Donation'), ('Estate');

CREATE TABLE request_statuses (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(30) NOT NULL UNIQUE,
  sort_order  TINYINT UNSIGNED NOT NULL,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  badge_class VARCHAR(20) NOT NULL DEFAULT 'slate'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO request_statuses (name, sort_order, is_terminal, badge_class) VALUES
  ('Received', 1, 0, 'slate'),
  ('Processing', 2, 0, 'amber'),
  ('Approved', 3, 1, 'green'),
  ('Rejected', 4, 1, 'red'),
  ('Out for Release', 5, 1, 'blue'),
  ('Timed Out', 6, 0, 'orange');

-- Real notifications, with read/unread state — distinct from
-- request_status_log.sms_body, which is a simulated SMS transcript only.
CREATE TABLE notifications (
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

-- Login/logout history, distinct from audit_log's coarse action string.
CREATE TABLE user_sessions (
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

-- Lightweight staff workflow activity — separate in purpose from
-- audit_log (accountability for administrative changes).
CREATE TABLE activity_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  action      VARCHAR(60) NOT NULL,
  request_id  INT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-requirement validation detail — richer than request_documents.
-- file_status, with room for staff to later record a manual re-validation.
CREATE TABLE document_validation (
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

-- Time Stamp Tracking + simulated SMS log. Doubles as the data source for
-- both the client's Notifications page and the staff Notifications page —
-- one event log, two filtered views of it.
CREATE TABLE request_status_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  request_id    INT NOT NULL,
  status        VARCHAR(30) NOT NULL,
  -- Who actually made this status change: 'client' (auto-"Received" on
  -- submission), 'staff' (a deliberate action from the request detail
  -- page), or 'system' (automatic timeout detection). created_at is always
  -- DB-generated (never set by app code), so every row's timestamp is the
  -- real moment that action happened — never backdated/faked.
  actor         VARCHAR(10) NOT NULL DEFAULT 'staff',
  sms_body      TEXT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NOT NULL,
  author        VARCHAR(150) NOT NULL,
  audience      ENUM('client','staff','both') NOT NULL DEFAULT 'both',
  start_date    DATE NOT NULL,
  end_date      DATE NULL,
  image_url     VARCHAR(500) NULL,
  status        ENUM('Draft','Scheduled','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  scheduled_at  DATETIME NULL,
  published_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Editable office settings (Admin > Settings), instead of hardcoded constants.
CREATE TABLE settings (
  setting_key   VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('office_name', 'Mabini Assessor Office'),
  ('office_phone', '(043) 487-0123'),
  ('office_email', 'assessor@mabini.gov.ph'),
  ('office_hours', 'Monday to Friday, 8:00 AM - 5:00 PM'),
  -- Expected processing time (Admin > Settings), in whole days from
  -- submission — the threshold request_elapsed_info()/mark_overdue_requests()
  -- compare against to flag a request "Timed Out".
  ('docreq_processing_days', '5'),
  ('landtransfer_processing_days', '10');

-- Admin > Audit Logs: every login (client and internal), every status
-- change, every account/role change gets a row here.
CREATE TABLE audit_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  actor_type  ENUM('client','staff','admin','head','system') NOT NULL,
  actor_id    INT NULL,
  actor_name  VARCHAR(150) NOT NULL,
  action      VARCHAR(120) NOT NULL,
  target      VARCHAR(150) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Live Chat / Help stand-in: a real, stored help request a client submits
-- and staff can see and resolve — not a simulated real-time chat widget
-- (that needs websocket infrastructure well beyond this prototype's scope).
CREATE TABLE help_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  client_id   INT NULL,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  message     TEXT NOT NULL,
  status      ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Seed accounts. Demo password for ALL seeded accounts (internal AND
-- client): Passw0rd!
-- ---------------------------------------------------------------------
INSERT INTO users (role_id, name, username, password_hash, status) VALUES
  (3, 'Maricar D. Santos',     'maricar.admin', '$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e', 'Active'),
  (2, 'Jessica P. Villanueva', 'jessica.staff', '$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e', 'Active'),
  (4, 'Rodel H. Ortega',       'rodel.head',    '$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e', 'Active');

INSERT INTO clients (first_name, last_name, email, contact_number, password_hash, status) VALUES
  ('Ramon', 'Villareal', 'r.villareal@example.com', '0917-224-5510', '$2y$12$.kZ2.nAJztNKxIcAHX7q3uM8NzT7zLZWFEIaH/EusUQEEY5jYpt6e', 'Active');

INSERT INTO announcements (title, body, author, start_date, end_date, status, created_at) VALUES
  ('Office schedule for Rizal Day', 'The MAO will be closed on December 30. Document release for approved requests will resume the next business day.', 'Rodel H. Ortega', CURDATE() - INTERVAL 3 DAY, CURDATE() + INTERVAL 30 DAY, 'Published', NOW() - INTERVAL 3 DAY),
  ('Reminder: verify scanned uploads', 'Please confirm scans are legible before approving. Blurry or cropped IDs should be flagged for resubmission.', 'Rodel H. Ortega', CURDATE() - INTERVAL 1 DAY, NULL, 'Draft', NOW() - INTERVAL 1 DAY);
