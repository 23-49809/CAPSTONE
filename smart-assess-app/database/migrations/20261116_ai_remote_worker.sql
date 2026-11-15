-- AI document verification: processor selection and the remote (pull) worker.
--
-- STAGE 1: this file is part of the code review only. It has NOT been applied
-- to production. Apply it only with approval, after a backup, with the
-- dispatcher timer disabled, and IMMEDIATELY followed by the new grant
-- "GRANT SELECT ON <db>.ai_processor_settings" in
-- deploy/mysql/ai-dispatch-db-user.sql.example (MySQL only grants on an existing
-- table). Until that grant exists a dispatcher run refuses to claim (fails closed).
-- After this migration the processor is 'off': nothing is claimed by anyone
-- until an operator selects one with bin/ai-processor.php.
--
-- ai_processor_settings: one row (id = 1) that names the ONLY processor allowed
--   to claim AI jobs:
--     off    - nobody claims jobs (the default after this migration)
--     aws    - the AWS dispatcher (bin/ai-verification-dispatch.php)
--     remote - the remote pull worker (web/ai-remote.php + bin/ai-remote-pull.php)
--   Every claim reads this row with a shared lock inside the claim transaction,
--   and bin/ai-processor.php changes it only while no job is being processed,
--   so two processors can never work at the same time. Before this migration
--   exists, the AWS dispatcher behaves exactly as before.
--
-- ai_remote_nonces: request nonces the remote endpoint has accepted, kept for
--   twice the allowed clock skew, so a captured request cannot be replayed.
--
-- ai_verification_jobs.remote_result_token: the claim token whose result the
--   remote endpoint stored, so a repeated "complete" of the same claim is
--   answered as already stored instead of being processed twice.
--
-- Changes no existing row; safe to run more than once.

CREATE TABLE IF NOT EXISTS ai_processor_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  processor ENUM('off', 'aws', 'remote') NOT NULL DEFAULT 'off',
  changed_at DATETIME NULL,
  changed_by VARCHAR(100) NULL,
  CONSTRAINT ai_processor_settings_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ai_processor_settings (id, processor) VALUES (1, 'off');

-- ai_remote_events: what the remote endpoint did (claim, download, complete, fail, rejected,
--   replay, rate_limited, maintenance), with the job ID only: no names, numbers, text, IP
--   addresses or secrets. Used for the hourly claim/download limits and for monitoring
--   (bin/ai-processor.php --status). Rows older than 30 days are removed automatically.
CREATE TABLE IF NOT EXISTS ai_remote_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(20) NOT NULL,
  job_id INT UNSIGNED NULL,
  created_at BIGINT NOT NULL,
  KEY idx_ai_remote_events_event_time (event, created_at),
  KEY idx_ai_remote_events_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- ai_remote_downloads: which document of which claim was already served. The primary key makes
--   "each document once per claim" atomic: a second download of the same document under the
--   same claim is refused. claim_hash is the SHA-256 of the claim token (never the token itself).
--   A failed download is retried only through a new claim (the job is given back with
--   remote_download_failed), which counts against the claim limits.
CREATE TABLE IF NOT EXISTS ai_remote_downloads (
  job_id INT UNSIGNED NOT NULL,
  claim_hash CHAR(64) NOT NULL,
  doc_key VARCHAR(40) NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (job_id, claim_hash, doc_key),
  KEY idx_ai_remote_downloads_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS ai_remote_nonces (
  nonce CHAR(32) NOT NULL PRIMARY KEY,
  seen_at BIGINT NOT NULL,
  KEY idx_ai_remote_nonces_seen_at (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

SET @sa_ai_add_column = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ai_verification_jobs ADD COLUMN remote_result_token CHAR(32) NULL AFTER claim_token',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_verification_jobs' AND COLUMN_NAME = 'remote_result_token'
);
PREPARE sa_ai_add_column_stmt FROM @sa_ai_add_column;
EXECUTE sa_ai_add_column_stmt;
DEALLOCATE PREPARE sa_ai_add_column_stmt;

-- Rollback (only with approval, after a backup, with every processor stopped):
--   ALTER TABLE ai_verification_jobs DROP COLUMN remote_result_token;
--   DROP TABLE ai_remote_nonces;
--   DROP TABLE ai_remote_events;
--   DROP TABLE ai_remote_downloads;
--   DROP TABLE ai_processor_settings;
-- Without these the AWS dispatcher works exactly as before this migration.
