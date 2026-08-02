-- Internal notes on an uncompleted information-request form become an
-- append-only history, matching lead_notes: an author, a timestamp, and no way
-- for one admin to overwrite another's record of the chase. Any text already in
-- incomplete_inquiries.admin_notes is carried across as that form's first note,
-- authorless (the old column never recorded who wrote it), and the column is
-- then dropped.
--
-- Order matters: create, then backfill, then drop. Each step is dynamically
-- prepared, so the backfill statement is never even compiled once the column is
-- gone. Every step is conditional on the current shape of the database, so this
-- is a no-op on an installation that is already current (including one created
-- fresh from schema.sql) and safe to re-run.

CREATE TABLE IF NOT EXISTS incomplete_inquiry_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incomplete_inquiry_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_iin_inquiry (incomplete_inquiry_id, created_at),
  CONSTRAINT fk_iin_inquiry FOREIGN KEY (incomplete_inquiry_id) REFERENCES incomplete_inquiries(id) ON DELETE CASCADE,
  CONSTRAINT fk_iin_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- The NOT EXISTS guard makes a re-run after a partial apply a no-op.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'incomplete_inquiries' AND column_name = 'admin_notes') = 1,
  'INSERT INTO incomplete_inquiry_notes (incomplete_inquiry_id, created_by_user_id, body, created_at)
     SELECT i.id, NULL, i.admin_notes, COALESCE(i.updated_at, i.created_at)
     FROM incomplete_inquiries i
     WHERE i.admin_notes IS NOT NULL AND TRIM(i.admin_notes) <> ''''
       AND NOT EXISTS (SELECT 1 FROM incomplete_inquiry_notes n
                       WHERE n.incomplete_inquiry_id = i.id AND n.created_by_user_id IS NULL)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'incomplete_inquiries' AND column_name = 'admin_notes') = 1,
  'ALTER TABLE incomplete_inquiries DROP COLUMN admin_notes',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
