-- The applicant-selected type of each valid ID (Property Owner's and Authorized Representative's),
-- stored on the ID's front-side row in request_documents. It is only what the applicant chose in the
-- form ("Select Type of Valid ID"): Staff see it labelled "selected by the applicant, not verified",
-- with a warning when it differs from the type the automatic check identified. It never confirms the
-- document's type, never changes what is required (every ID needs its front and back), and it is never
-- sent to the automatic document check, which still reads the uploaded ID itself. Values are type
-- codes from DocumentRequirements::idTypeOptions() (the government IDs the recognition registry
-- knows), checked by web/api.php before saving; any other stored value is never shown.
--
-- The ID's back side needs no schema change: it is its own request_documents row (doc_key
-- ownerIdBack / requesterIdBack).
--
-- Adds one nullable column only when it is not already present, so it is safe to run more than
-- once. Existing rows keep NULL; nothing is deleted or changed. Until it has run, submissions work as
-- before and the selected type is simply not kept (web/api.php checks for the column).
-- Undo: database/rollbacks/20261121_request_document_id_type_hint_rollback.sql

SET @has_id_type_hint := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'request_documents'
    AND COLUMN_NAME = 'id_type_hint'
);
SET @sql := IF(@has_id_type_hint = 0,
  'ALTER TABLE request_documents ADD COLUMN id_type_hint VARCHAR(40) NULL DEFAULT NULL AFTER file_status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
