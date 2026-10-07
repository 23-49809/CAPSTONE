-- Add the internal account contact and official position fields.
-- Run once against existing smart_assess databases; fresh installs get these
-- columns directly from database/schema.sql.
ALTER TABLE users
  ADD COLUMN contact_number VARCHAR(20) NULL AFTER username,
  ADD COLUMN position_title VARCHAR(80) NULL AFTER contact_number;
