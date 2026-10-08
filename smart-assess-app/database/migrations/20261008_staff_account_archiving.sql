-- Staff account archiving: Active -> Archived -> Restored or Permanently
-- Deleted. Additive only — no existing column changes, no data loss.
--
-- "Permanently Delete" deliberately does NOT run a real SQL DELETE on
-- `users`. activity_logs.user_id has ON DELETE CASCADE, so a hard delete
-- would silently destroy that staff member's entire activity history, and
-- audit_log's actor_name would become orphaned prose with no row behind
-- it. Instead it's a second soft-delete marker (deleted_at/deleted_by):
-- the row — and everything that references it — stays intact, but the
-- account is filtered out of both the Staff Accounts and Archived
-- Accounts lists forever and can never log in again. From the UI, it is
-- permanently gone; from the database, government audit history is
-- preserved, which is the safer architecture for this kind of system.
ALTER TABLE users
  ADD COLUMN archived_at DATETIME NULL AFTER status,
  ADD COLUMN archived_by INT NULL AFTER archived_at,
  ADD COLUMN restored_at DATETIME NULL AFTER archived_by,
  ADD COLUMN restored_by INT NULL AFTER restored_at,
  ADD COLUMN deleted_at DATETIME NULL AFTER restored_by,
  ADD COLUMN deleted_by INT NULL AFTER deleted_at,
  ADD CONSTRAINT fk_users_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_deleted_by  FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL,
  ADD INDEX idx_archived_at (archived_at),
  ADD INDEX idx_deleted_at (deleted_at);
