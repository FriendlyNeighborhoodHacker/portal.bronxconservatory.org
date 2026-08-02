-- A lesson can be called off.
--
-- Cancelling is not deleting: the row stays so the family and the teacher can
-- still see that the lesson was scheduled and then cancelled. What changes is
-- that it drops off the admin calendar and stops holding the teacher's slot,
-- so something else can be booked in its place.
--
-- Conditional on the current shape of the database, so this is a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql) and safe to re-run.

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'cancelled_at') = 0,
  'ALTER TABLE lessons ADD COLUMN cancelled_at DATETIME DEFAULT NULL COMMENT ''Called off: hidden from the admin calendar, still shown to the family and teacher, and no longer holds the slot'' AFTER attended',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'cancelled_by_user_id') = 0,
  'ALTER TABLE lessons ADD COLUMN cancelled_by_user_id INT DEFAULT NULL AFTER cancelled_at',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'lessons' AND constraint_name = 'fk_l_cancelled_by') = 0,
  'ALTER TABLE lessons ADD CONSTRAINT fk_l_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
