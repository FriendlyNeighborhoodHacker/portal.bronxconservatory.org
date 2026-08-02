-- Lead notes become an append-only history with an author and a timestamp,
-- replacing the single leads.admin_notes blob that two admins could silently
-- clobber. Each existing blob is carried across as that lead's first note,
-- authorless (the old column never recorded who wrote it), and the column is
-- then dropped — a dead column with a live-looking name is what invites the
-- next reader to overwrite history again.
--
-- Order matters: create, then backfill, then drop. Each step is dynamically
-- prepared, so the backfill statement is never even compiled once the column
-- is gone. Every step is conditional on the current shape of the database, so
-- this is a no-op on an installation that is already current (including one
-- created fresh from schema.sql) and safe to re-run.

CREATE TABLE IF NOT EXISTS lead_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL COMMENT 'NULL for notes migrated from leads.admin_notes',
  body TEXT NOT NULL COMMENT 'May be empty when the entry only records a status change',
  status_after ENUM('new','contacted','scheduled','converted','declined') DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead_notes_lead (lead_id, created_at),
  CONSTRAINT fk_lead_note_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lead_note_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- The NOT EXISTS guard makes a re-run after a partial apply a no-op.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'admin_notes') = 1,
  'INSERT INTO lead_notes (lead_id, created_by_user_id, body, status_after, created_at)
     SELECT l.id, NULL, l.admin_notes, NULL, COALESCE(l.updated_at, l.created_at)
     FROM leads l
     WHERE l.admin_notes IS NOT NULL AND TRIM(l.admin_notes) <> ''''
       AND NOT EXISTS (SELECT 1 FROM lead_notes n
                       WHERE n.lead_id = l.id AND n.created_by_user_id IS NULL)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'admin_notes') = 1,
  'ALTER TABLE leads DROP COLUMN admin_notes',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
