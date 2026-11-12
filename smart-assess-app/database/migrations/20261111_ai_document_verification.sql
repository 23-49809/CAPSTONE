-- AI document verification: one job per request, processed asynchronously by
-- bin/ai-verification-dispatch.php through the private AI worker.
--
-- The AI result is advice for Staff only. It never changes requests.status,
-- never approves or rejects anything, and is never shown to clients, Admin or
-- the Department Head.
--
-- status:  pending -> processing -> completed | failed | needs_manual_review
--          (failed and needs_manual_review can be queued again by Staff)
-- outcome: passed | needs_review | missing_information (set when completed)
--
-- findings_json and document_checks_json hold the validated, structured
-- result. Raw OCR text is never stored; findings may quote at most a short
-- evidence snippet. The request's uploaded files stay in private_uploads.
--
-- attempts counts only tries that this request could have caused (a bad or
-- invalid answer, a model timeout, a lost lease). Worker outages and a busy
-- worker do not use attempts; they are counted in transient_failures (limit
-- 300). A worker that dies during this request is counted in crash_failures
-- (limit 3). Settings an administrator must fix use neither.
--
-- Creates one new table and changes nothing else, so it is safe to run more
-- than once. No existing rows are changed or deleted.

CREATE TABLE IF NOT EXISTS ai_verification_jobs (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  request_id           INT NOT NULL,
  status               ENUM('pending','processing','completed','failed','needs_manual_review') NOT NULL DEFAULT 'pending',
  outcome              ENUM('passed','needs_review','missing_information') NULL,
  outcome_reason       VARCHAR(500) NULL,
  findings_json        JSON NULL,
  document_checks_json JSON NULL,
  model_name           VARCHAR(100) NULL,
  model_digest         VARCHAR(80) NULL,
  ocr_engine           VARCHAR(100) NULL,
  attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts         TINYINT UNSIGNED NOT NULL DEFAULT 3,
  transient_failures   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  crash_failures       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- The Privacy Policy effective time (Unix seconds) the website used when it
  -- queued this job. The dispatcher refuses to run while any waiting job's
  -- value differs from its own setting, so the two servers cannot silently
  -- use different dates. NULL: queued without a recorded date (never claimed).
  privacy_effective_from BIGINT NULL,
  next_attempt_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claim_token          CHAR(32) NULL,
  lease_expires_at     DATETIME NULL,
  started_at           DATETIME NULL,
  completed_at         DATETIME NULL,
  error_code           VARCHAR(60) NULL,
  staff_review_status  ENUM('not_reviewed','reviewed') NOT NULL DEFAULT 'not_reviewed',
  reviewed_by          INT NULL,
  reviewed_at          DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- One job per request: a second submission event or a retry reuses the row.
  UNIQUE KEY uniq_ai_job_request (request_id),
  KEY idx_ai_job_queue (status, next_attempt_at),
  KEY idx_ai_job_lease (status, lease_expires_at),
  CONSTRAINT fk_ai_job_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_job_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adds transient_failures, crash_failures and privacy_effective_from to a table created by an earlier
-- draft of this migration (local test databases only; it has never run in
-- production). Each does nothing when the column already exists.
SET @sa_ai_add_column = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ai_verification_jobs ADD COLUMN transient_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_attempts',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_verification_jobs' AND COLUMN_NAME = 'transient_failures'
);
PREPARE sa_ai_add_column_stmt FROM @sa_ai_add_column;
EXECUTE sa_ai_add_column_stmt;
DEALLOCATE PREPARE sa_ai_add_column_stmt;

SET @sa_ai_add_column = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ai_verification_jobs ADD COLUMN crash_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER transient_failures',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_verification_jobs' AND COLUMN_NAME = 'crash_failures'
);
PREPARE sa_ai_add_column_stmt FROM @sa_ai_add_column;
EXECUTE sa_ai_add_column_stmt;
DEALLOCATE PREPARE sa_ai_add_column_stmt;

SET @sa_ai_add_column = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE ai_verification_jobs ADD COLUMN privacy_effective_from BIGINT NULL AFTER crash_failures',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_verification_jobs' AND COLUMN_NAME = 'privacy_effective_from'
);
PREPARE sa_ai_add_column_stmt FROM @sa_ai_add_column;
EXECUTE sa_ai_add_column_stmt;
DEALLOCATE PREPARE sa_ai_add_column_stmt;

-- Rollback (only with approval, after a backup, and after stopping the
-- dispatcher timer): DROP TABLE ai_verification_jobs;
-- This removes all AI results and Staff review marks; requests, documents and
-- status history are not affected, and submissions keep working without it.
