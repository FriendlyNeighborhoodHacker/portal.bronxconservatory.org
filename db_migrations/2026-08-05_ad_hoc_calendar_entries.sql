-- One-off lessons and hold blocks, booked straight onto the weekly calendar
-- without a standing weekly reservation behind them.
--
-- Until now every occurrence inherited its semester, teacher, student and
-- location from its reservation. A one-off has no reservation, so it carries
-- those facts itself and the parent link becomes optional. Every read
-- COALESCEs the reservation's value over the occurrence's, so existing rows —
-- where the occurrence columns are all NULL — behave exactly as before.
--
-- Conditional on the current shape of the database, so this is a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql) and safe to re-run.

-- ===== lessons =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lessons'
      AND column_name = 'semester_lesson_reservation_id' AND is_nullable = 'NO') = 1,
  'ALTER TABLE lessons MODIFY COLUMN semester_lesson_reservation_id INT DEFAULT NULL COMMENT ''NULL for a one-off lesson booked straight onto the calendar''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'semester_id') = 0,
  'ALTER TABLE lessons
     ADD COLUMN semester_id INT DEFAULT NULL AFTER semester_lesson_reservation_id,
     ADD COLUMN teacher_user_id INT DEFAULT NULL AFTER semester_id,
     ADD COLUMN student_user_id INT DEFAULT NULL AFTER teacher_user_id,
     ADD COLUMN location_id INT DEFAULT NULL AFTER student_user_id,
     ADD CONSTRAINT fk_l_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_l_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_l_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_l_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== semester_hold_blocks =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'semester_hold_blocks'
      AND column_name = 'semester_hold_block_reservation_id' AND is_nullable = 'NO') = 1,
  'ALTER TABLE semester_hold_blocks MODIFY COLUMN semester_hold_block_reservation_id INT DEFAULT NULL COMMENT ''NULL for a one-off hold booked straight onto the calendar''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'semester_hold_blocks' AND column_name = 'semester_id') = 0,
  'ALTER TABLE semester_hold_blocks
     ADD COLUMN semester_id INT DEFAULT NULL AFTER semester_hold_block_reservation_id,
     ADD COLUMN teacher_user_id INT DEFAULT NULL AFTER semester_id,
     ADD COLUMN location_id INT DEFAULT NULL AFTER teacher_user_id,
     ADD CONSTRAINT fk_shb_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_shb_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_shb_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
