-- SMS delivery tracking for staff notifications sent through IPROG SMS.
--
-- request_status_log.delivery_status: 'pending' (recorded before contacting
--   IPROG, or IPROG's answer was unclear), 'sent' (IPROG accepted it for
--   sending; this is not proof it reached the phone) or 'failed' (IPROG
--   did not take it). Existing rows stay NULL: they were saved before SMS
--   sending existed, and the app labels them "Recorded".
-- request_status_log.sms_sent_by_id: the staff user (users.id) who sent it.
--   Existing rows stay NULL.
--
-- Only adds nullable columns, and only when they are missing, so it is safe to
-- run more than once. No existing rows are changed or deleted.

SET @has_delivery_status := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'request_status_log'
      AND COLUMN_NAME = 'delivery_status'
);

SET @sql := IF(
    @has_delivery_status = 0,
    'ALTER TABLE request_status_log ADD COLUMN delivery_status VARCHAR(20) NULL',
    'SELECT 1'
);

PREPARE sms_status_stmt FROM @sql;
EXECUTE sms_status_stmt;
DEALLOCATE PREPARE sms_status_stmt;

SET @has_sms_sent_by := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'request_status_log'
      AND COLUMN_NAME = 'sms_sent_by_id'
);

SET @sql := IF(
    @has_sms_sent_by = 0,
    'ALTER TABLE request_status_log ADD COLUMN sms_sent_by_id INT NULL',
    'SELECT 1'
);

PREPARE sms_sent_by_stmt FROM @sql;
EXECUTE sms_sent_by_stmt;
DEALLOCATE PREPARE sms_sent_by_stmt;
