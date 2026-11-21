-- ROLLBACK of 20261121_request_document_id_type_hint.sql (applicant-selected ID type).
--
-- What it does: drops request_documents.id_type_hint, the one column that migration added, and only
-- when it exists, so it is safe to run more than once (a second run changes nothing).
--
-- What it never does: delete or change a request, a document record (front or back side), an uploaded
-- file, any other column, or any history. The ID back sides stay: they are ordinary request_documents
-- rows (ownerIdBack / requesterIdBack), not part of this migration.
--
-- Effect: the applicant-selected ID types already stored are lost (they were only the applicants'
-- unverified hints); the application keeps working and simply stops storing and showing them
-- (web/api.php and web/bootstrap.php check for the column).
--
-- Run it as:   mysql <database> < database/rollbacks/20261121_request_document_id_type_hint_rollback.sql
-- and confirm it ended without error. Back up the database first, as for any schema change.

SET @has_id_type_hint := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'request_documents'
    AND COLUMN_NAME = 'id_type_hint'
);
SET @sql := IF(@has_id_type_hint = 1,
  'ALTER TABLE request_documents DROP COLUMN id_type_hint',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
