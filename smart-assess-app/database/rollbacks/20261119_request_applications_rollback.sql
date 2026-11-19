-- ROLLBACK of 20261119_request_applications.sql (several documents in one application).
--
-- Safe ONLY while no application with several documents exists. It refuses otherwise.
--
-- Why: an application's documents are linked only through requests.application_id and
-- request_applications. Removing them while applications exist would leave their documents
-- (references like DR-2026-AB12CD34-2) without their application, and every application
-- reference the clients were given would stop tracking ("No request found").
--
-- What it checks first: that request_applications holds no row and that no request has an
-- application_id or item_no. If any exists, the first statement fails on purpose with the error
--   Table '<database>.rollback_refused__multi_document_applications_exist' doesn't exist
-- and the mysql client stops there. Every later step is also skipped when data exists, so even
-- "mysql --force" changes nothing.
--
-- What it never does: delete or change a request, a document record, an uploaded file or any
-- history. When it runs (no applications), it only drops the empty table, the two unused columns
-- and their index, returning the schema to its state before the migration.
--
-- Run it as:   mysql <database> < database/rollbacks/20261119_request_applications_rollback.sql
-- and confirm it ended without error. Back up the database first, as for any schema change.

SET @has_applications_table := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_applications'
);
SET @has_application_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'application_id'
);
SET @has_item_no := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'item_no'
);

-- Any application data? (Each count is taken only when its table or column exists.)
SET @sql := IF(@has_applications_table = 1, 'SELECT COUNT(*) INTO @application_rows FROM request_applications', 'SELECT 0 INTO @application_rows');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_application_id = 1, 'SELECT COUNT(*) INTO @linked_requests FROM requests WHERE application_id IS NOT NULL', 'SELECT 0 INTO @linked_requests');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@has_item_no = 1, 'SELECT COUNT(*) INTO @numbered_requests FROM requests WHERE item_no IS NOT NULL', 'SELECT 0 INTO @numbered_requests');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @refuse := (@application_rows + @linked_requests + @numbered_requests) > 0;

-- Refusal: stops the mysql client with the error named above. Nothing has been changed.
SET @sql := IF(@refuse, 'SELECT 1 FROM rollback_refused__multi_document_applications_exist', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Only when there is no application data at all:
SET @has_application_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'uq_requests_application_item'
);
SET @sql := IF(NOT @refuse AND @has_application_index > 0, 'ALTER TABLE requests DROP INDEX uq_requests_application_item', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(NOT @refuse AND @has_item_no = 1, 'ALTER TABLE requests DROP COLUMN item_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(NOT @refuse AND @has_application_id = 1, 'ALTER TABLE requests DROP COLUMN application_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(NOT @refuse AND @has_applications_table = 1, 'DROP TABLE request_applications', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
