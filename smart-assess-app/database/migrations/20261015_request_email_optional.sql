-- Email is optional on client requests (Document Request and Land Transfer).
-- Makes requests.email nullable, keeping its existing type/length. Safe to run
-- more than once: it only alters the column when it is still NOT NULL.
-- No rows are changed.

SET @email_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'email' AND IS_NULLABLE = 'NO'
);
SET @sql := IF(@email_type IS NOT NULL,
  CONCAT('ALTER TABLE requests MODIFY COLUMN email ', @email_type, ' NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
