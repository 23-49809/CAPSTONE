-- Staff openings of uploaded originals (web/request-file.php), one row per Staff
-- member and document. An AI document check can be marked "reviewed" only by a
-- Staff member who has opened every original of the request that the application
-- can serve (AiVerification::markReviewed()).
--
-- Kept in its own table, not in audit_log, so openings never crowd security events
-- (logins, account changes) out of the Admin's audit view, and so the lookup is
-- indexed. A row records that the file was sent to that Staff member's browser; it
-- does not prove the document was inspected or understood.
--
-- Column types match production (users.id and request_documents.id are INT,
-- InnoDB, utf8mb4). Deleting a document or a user account removes its rows.
--
-- Creates one new table and changes nothing else, so it is safe to run more than
-- once. No existing rows are changed or deleted. Until it has run, opening a file
-- works as before, and "Mark as reviewed" answers that the opening record is not
-- set up yet.

CREATE TABLE IF NOT EXISTS request_document_views (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  user_id              INT NOT NULL,
  request_document_id  INT NOT NULL,
  first_opened_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_opened_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  open_count           INT UNSIGNED NOT NULL DEFAULT 1,
  -- One row per Staff member and document; repeated openings update it.
  UNIQUE KEY uniq_doc_view_user_document (user_id, request_document_id),
  KEY idx_doc_view_document (request_document_id),
  CONSTRAINT fk_doc_view_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_view_document FOREIGN KEY (request_document_id) REFERENCES request_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
