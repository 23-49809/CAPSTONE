-- Roll back the Released status addition only when no request uses it.
-- No requests, application parents, or histories are deleted or changed.
SET @released_rows := (SELECT COUNT(*) FROM requests WHERE status = 'Released');
SET @refuse := @released_rows > 0;

SET @sql := IF(@refuse,
  'SELECT 1 FROM rollback_refused__released_requests_exist',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(NOT @refuse,
  'ALTER TABLE requests MODIFY COLUMN status ENUM(''Received'',''Processing'',''Approved'',''Rejected'',''Out for Release'',''Timed Out'') NOT NULL DEFAULT ''Received''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
