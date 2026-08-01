-- users.is_developer: with is_admin, unlocks Admin > Maintenance (migrations,
-- activity log, email log). Those pages used to be open to every admin, so
-- every current admin is granted the flag here — nobody loses access on
-- upgrade, and an admin can uncheck "Developer" on /admin/user_edit.php for
-- the admins who should not have it.
--
-- Idempotent: safe to re-run.

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'is_developer');

SET @sql := IF(@col = 0,
  "ALTER TABLE users ADD COLUMN is_developer TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'With is_admin, unlocks Admin > Maintenance (migrations, logs)'
     AFTER is_admin",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Only on the first run (when the column was just added) do current admins
-- keep the access they already had.
SET @sql := IF(@col = 0,
  'UPDATE users SET is_developer = 1 WHERE is_admin = 1',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
