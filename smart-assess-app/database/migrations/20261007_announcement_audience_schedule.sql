-- Bring an existing smart_assess database's `announcements` table up to the
-- current schema.sql shape, then layer on "Post To" targeting and
-- send-now/schedule-for-later support. Written defensively (each column
-- added only if missing) because some installs were created from an older
-- schema.sql revision that predates start_date/end_date/image_url/status.
-- Run once; fresh installs get the final shape directly from schema.sql.

SET @has_start_date := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'start_date'
);
SET @sql := IF(@has_start_date = 0,
  'ALTER TABLE announcements
     ADD COLUMN start_date DATE NULL AFTER author,
     ADD COLUMN end_date DATE NULL AFTER start_date,
     ADD COLUMN image_url VARCHAR(500) NULL AFTER end_date,
     ADD COLUMN status ENUM(\'Draft\',\'Published\') NOT NULL DEFAULT \'Draft\' AFTER image_url',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Legacy rows (pre-dating start_date/status) were effectively always-on
-- announcements — carry that forward as Published rather than hiding them.
UPDATE announcements SET start_date = DATE(created_at) WHERE start_date IS NULL;
UPDATE announcements SET status = 'Published' WHERE status = 'Draft' AND start_date = DATE(created_at);
ALTER TABLE announcements MODIFY COLUMN start_date DATE NOT NULL;

SET @has_audience := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'audience'
);
SET @sql := IF(@has_audience = 0,
  'ALTER TABLE announcements ADD COLUMN audience ENUM(\'client\',\'staff\',\'both\') NOT NULL DEFAULT \'both\' AFTER author',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE announcements MODIFY COLUMN status ENUM('Draft','Scheduled','Published','Cancelled') NOT NULL DEFAULT 'Draft';

SET @has_scheduled_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'scheduled_at'
);
SET @sql := IF(@has_scheduled_at = 0,
  'ALTER TABLE announcements ADD COLUMN scheduled_at DATETIME NULL AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_published_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'published_at'
);
SET @sql := IF(@has_published_at = 0,
  'ALTER TABLE announcements ADD COLUMN published_at DATETIME NULL AFTER scheduled_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing published rows didn't track a publish timestamp separately from
-- created_at — backfill it so the management table isn't blank for them.
UPDATE announcements SET published_at = created_at WHERE status = 'Published' AND published_at IS NULL;
