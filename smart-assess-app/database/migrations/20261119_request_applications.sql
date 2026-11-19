-- Several document requests in one application (Document Requests only).
--
-- One application = one reference number shown to the client (DR-2026-AB12CD34).
-- Each selected document stays its own row in `requests`, linked to the application, with
-- its own document type, purpose, status, assigned Staff member, files and document check,
-- and its own item reference: the application reference plus "-1", "-2", ...
--
-- Safe to run on existing databases, and safe to run twice:
--   * creates one new table only if it does not exist;
--   * adds two NULLABLE columns and one index to `requests` only if they are missing;
--   * changes, moves or deletes NO existing row: older requests keep application_id NULL
--     and their own reference, and are shown and tracked exactly as before.
-- No foreign key is added (the application keeps the link consistent), so the existing
-- `requests` table is not tied to the column types of the new table.
--
-- Roll back: run database/rollbacks/20261119_request_applications_rollback.sql (kept outside this
-- folder so it is never run by mistake with the migrations), never hand-written DROP statements.
-- It is safe only while no multi-document application exists, and it refuses otherwise (with an
-- error naming the reason), because removing the link would leave those applications' documents
-- without their application and stop every application reference from tracking. It never
-- deletes a request, a document record, an uploaded file or any history.

CREATE TABLE IF NOT EXISTS request_applications (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference_no  VARCHAR(20)  NOT NULL,
  flow          VARCHAR(20)  NOT NULL DEFAULT 'docreq',
  client_id     INT NULL,
  item_count    TINYINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_request_applications_reference (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_application_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'application_id'
);
SET @sql := IF(@has_application_id = 0,
  'ALTER TABLE requests ADD COLUMN application_id INT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_item_no := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'item_no'
);
SET @sql := IF(@has_item_no = 0,
  'ALTER TABLE requests ADD COLUMN item_no TINYINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_application_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'uq_requests_application_item'
);
SET @sql := IF(@has_application_index = 0,
  'ALTER TABLE requests ADD UNIQUE INDEX uq_requests_application_item (application_id, item_no)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
