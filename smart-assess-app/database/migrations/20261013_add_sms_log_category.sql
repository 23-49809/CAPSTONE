-- Store the category staff choose when sending an SMS notification, so the
-- SMS Notification History can be filtered by it.
-- Safe to run on existing databases: the nullable column is added only when
-- it is not already present. Existing log rows are left unchanged (NULL),
-- and the app labels those as "Request Status Update".

SET @has_sms_category := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'request_status_log'
    AND COLUMN_NAME = 'sms_category'
);
SET @sql := IF(@has_sms_category = 0,
  'ALTER TABLE request_status_log ADD COLUMN sms_category VARCHAR(60) NULL AFTER sms_body',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
