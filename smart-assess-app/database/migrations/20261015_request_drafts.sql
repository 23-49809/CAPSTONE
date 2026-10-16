-- Guest application drafts (auto-save and resume) for Document Request and
-- Land Transfer. Drafts are kept apart from `requests`: nothing here is an
-- official request, appears to staff, or triggers notifications until the
-- client completes the final submission.
--
-- A draft is found only by the SHA-256 hash of its random recovery code
-- (100 bits); the code itself is never stored. Drafts expire after 7 days
-- without activity and 30 days after creation at most, and are removed by
-- the cleanup in api.php (also runnable as bin/cleanup-drafts.php).
-- Safe to run more than once. Existing tables and rows are not changed.

CREATE TABLE IF NOT EXISTS request_drafts (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash          CHAR(64) NOT NULL,
  flow                ENUM('docreq','landtransfer') NOT NULL,
  step                TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_step            TINYINT UNSIGNED NOT NULL DEFAULT 1,
  form_data           MEDIUMTEXT NOT NULL,
  revision            INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at          DATETIME NOT NULL,
  submitted_request_id INT NULL,
  submitted_reference VARCHAR(30) NULL,
  submitted_at        DATETIME NULL,
  UNIQUE KEY uq_draft_token (token_hash),
  INDEX idx_draft_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS request_draft_files (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  draft_id      BIGINT UNSIGNED NOT NULL,
  doc_key       VARCHAR(40) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(80) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_draft_doc (draft_id, doc_key),
  FOREIGN KEY (draft_id) REFERENCES request_drafts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-client (hashed IP) counters for rate limiting draft creation, uploads
-- and recovery-code lookups. Rows older than one day are pruned.
CREATE TABLE IF NOT EXISTS request_draft_rate (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_hash CHAR(64) NOT NULL,
  bucket      VARCHAR(20) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_draft_rate (client_hash, bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
