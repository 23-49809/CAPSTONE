-- Add the email field required by the new internal-account UI.
-- NULL is allowed so existing accounts remain valid until updated.
ALTER TABLE users
  ADD COLUMN email VARCHAR(150) NULL AFTER username,
  ADD UNIQUE KEY uq_users_email (email);
