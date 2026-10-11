-- Persist request processing timestamps and elapsed duration.
-- Safe to run on existing databases: each nullable column is added only
-- when it is not already present. No request records are deleted or reset.

SET @has_processing_started_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'processing_started_at'
);
SET @sql := IF(@has_processing_started_at = 0,
  'ALTER TABLE requests ADD COLUMN processing_started_at DATETIME NULL AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_activity_actor_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'activity_logs'
    AND COLUMN_NAME = 'actor_name'
);
SET @sql := IF(@has_activity_actor_name = 0,
  'ALTER TABLE activity_logs ADD COLUMN actor_name VARCHAR(150) NULL AFTER user_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_processing_staff_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'processing_staff_id'
);
SET @sql := IF(@has_processing_staff_id = 0,
  'ALTER TABLE requests ADD COLUMN processing_staff_id BIGINT UNSIGNED NULL AFTER processing_started_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_processing_staff_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'processing_staff_name'
);
SET @sql := IF(@has_processing_staff_name = 0,
  'ALTER TABLE requests ADD COLUMN processing_staff_name VARCHAR(150) NULL AFTER processing_staff_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_processing_completed_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'processing_completed_at'
);
SET @sql := IF(@has_processing_completed_at = 0,
  'ALTER TABLE requests ADD COLUMN processing_completed_at DATETIME NULL AFTER processing_started_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_processing_duration_seconds := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'requests'
    AND COLUMN_NAME = 'processing_duration_seconds'
);
SET @sql := IF(@has_processing_duration_seconds = 0,
  'ALTER TABLE requests ADD COLUMN processing_duration_seconds BIGINT UNSIGNED NULL AFTER processing_completed_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
