-- Notification delivery tracking and automatic submission confirmations.
--
-- notifications.provider_message_id: IPROG's message ID for an SMS (e.g. iSms-...),
--   so its delivery can be looked up with IPROG later. NULL for email and for
--   rows saved before this update.
-- notifications.provider_status: the status IPROG reported once, shortly after
--   accepting the SMS ("pending", "completed", ...). Not proof of delivery.
-- notifications.status_log_id: the request_status_log row (notification history)
--   of the same message, so the history can show the IPROG message ID.
-- notifications.send_state: for automatic submission confirmations only:
--   'queued' (waiting for the sender), 'sending' (claimed by the sender; if it
--   stays so, the outcome is unconfirmed and it is never sent again), NULL (done,
--   and every other notification).
--
-- Only adds nullable columns and one index, and only when they are missing, so
-- it is safe to run more than once. No existing rows are changed or deleted.
-- The website queues confirmations only after all four columns exist.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'provider_message_id');
SET @sql := IF(@col = 0, 'ALTER TABLE notifications ADD COLUMN provider_message_id VARCHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'provider_status');
SET @sql := IF(@col = 0, 'ALTER TABLE notifications ADD COLUMN provider_status VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'status_log_id');
SET @sql := IF(@col = 0, 'ALTER TABLE notifications ADD COLUMN status_log_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'send_state');
SET @sql := IF(@col = 0, 'ALTER TABLE notifications ADD COLUMN send_state VARCHAR(10) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_send_state');
SET @sql := IF(@idx = 0, 'ALTER TABLE notifications ADD INDEX idx_notifications_send_state (send_state, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_status_log');
SET @sql := IF(@idx = 0, 'ALTER TABLE notifications ADD INDEX idx_notifications_status_log (status_log_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
