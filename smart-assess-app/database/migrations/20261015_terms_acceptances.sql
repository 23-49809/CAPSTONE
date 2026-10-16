-- Terms and Conditions acceptance (guest clients, no account needed).
-- One row per acceptance from the popup shown before a Document Request or
-- Land Transfer form opens: when it was accepted, which terms version, and
-- for which application type. The final submission must present a valid,
-- unused acceptance (checked in api.php); the row is then linked to the
-- request it was used for. Rows are kept as the acceptance record.
-- The client receives a random token; only its SHA-256 hash is stored.
-- Safe to run more than once. Existing tables and rows are not changed.

CREATE TABLE IF NOT EXISTS terms_acceptances (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash    CHAR(64) NOT NULL,
  terms_version VARCHAR(40) NOT NULL,
  flow          ENUM('docreq','landtransfer') NOT NULL,
  accepted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_hash   CHAR(64) NULL,
  request_id    INT NULL,
  used_at       DATETIME NULL,
  UNIQUE KEY uq_terms_token (token_hash),
  INDEX idx_terms_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
