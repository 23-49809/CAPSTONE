-- Add announcement targeting ("Post To") and send-now/schedule-for-later
-- support. Run once against existing smart_assess databases; fresh installs
-- get these columns directly from database/schema.sql.
ALTER TABLE announcements
  ADD COLUMN audience ENUM('client','staff','both') NOT NULL DEFAULT 'both' AFTER author,
  MODIFY COLUMN status ENUM('Draft','Scheduled','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  ADD COLUMN scheduled_at DATETIME NULL AFTER status,
  ADD COLUMN published_at DATETIME NULL AFTER scheduled_at;

-- Existing published rows didn't track a publish timestamp separately from
-- created_at — backfill it so the management table isn't blank for them.
UPDATE announcements SET published_at = created_at WHERE status = 'Published' AND published_at IS NULL;
