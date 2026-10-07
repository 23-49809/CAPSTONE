-- Time Stamp Tracking: let a request be automatically flagged "Timed Out"
-- when it sits open past its expected processing window, and record WHO
-- actually made each status change (client/staff/system). Run once
-- against existing smart_assess databases; fresh installs get this
-- directly from schema.sql.

ALTER TABLE requests
  MODIFY COLUMN status ENUM('Received','Processing','Approved','Rejected','Out for Release','Timed Out')
    NOT NULL DEFAULT 'Received';

SET @has_actor := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_status_log' AND COLUMN_NAME = 'actor'
);
SET @sql := IF(@has_actor = 0,
  'ALTER TABLE request_status_log ADD COLUMN actor VARCHAR(10) NOT NULL DEFAULT \'staff\' AFTER status',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing rows predate the actor column — the one case we can state with
-- certainty is the initial "Received" entry, which the client-submission
-- code path logs, not staff. Everything else stays at the 'staff' default.
UPDATE request_status_log SET actor = 'client' WHERE status = 'Received' AND actor = 'staff';

INSERT INTO settings (setting_key, setting_value) VALUES
  ('docreq_processing_days', '5'),
  ('landtransfer_processing_days', '10')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
