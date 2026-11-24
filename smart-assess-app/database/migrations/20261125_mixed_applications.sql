-- Add an independent Released status to requests. APP applications contain
-- Document Request children only; Land Transfer remains standalone.

ALTER TABLE requests
  MODIFY COLUMN status ENUM('Received','Processing','Approved','Rejected','Out for Release','Released','Timed Out')
  NOT NULL DEFAULT 'Received';
