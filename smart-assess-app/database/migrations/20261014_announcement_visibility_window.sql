-- Optional end of an announcement's visibility window (Asia/Manila wall time,
-- same convention as scheduled_at / published_at). NULL = no configured end.
-- Client-facing announcements additionally expire 24 hours after published_at
-- (enforced in PHP), whichever comes first.
-- Safe to run on existing databases: the nullable column is added only when it
-- is not already present. No announcement rows are changed or deleted.

SET @has_visible_until := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'announcements'
    AND COLUMN_NAME = 'visible_until'
);
SET @sql := IF(@has_visible_until = 0,
  'ALTER TABLE announcements ADD COLUMN visible_until DATETIME NULL AFTER published_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
