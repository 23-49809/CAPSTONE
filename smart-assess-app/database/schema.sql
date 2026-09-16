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
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT chk_users_role CHECK (role_id IN (2,3,4))
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
  status                ENUM('Received','Processing','Approved','Rejected','Out for Release')
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

-- Time Stamp Tracking + simulated SMS log. Doubles as the data source for
-- both the client's Notifications page and the staff Notifications page —
-- one event log, two filtered views of it.
CREATE TABLE request_status_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  request_id    INT NOT NULL,
  status        VARCHAR(30) NOT NULL,
  sms_body      TEXT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  body        TEXT NOT NULL,
  author      VARCHAR(150) NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NULL,
  image_url   VARCHAR(500) NULL,
  status      ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  ('office_hours', 'Monday to Friday, 8:00 AM - 5:00 PM');

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

INSERT INTO announcements (title, body, author, created_at) VALUES
  ('Office schedule for Rizal Day', 'The MAO will be closed on December 30. Document release for approved requests will resume the next business day.', 'Rodel H. Ortega', NOW() - INTERVAL 3 DAY),
  ('Reminder: verify scanned uploads', 'Please confirm scans are legible before approving. Blurry or cropped IDs should be flagged for resubmission.', 'Rodel H. Ortega', NOW() - INTERVAL 1 DAY);
