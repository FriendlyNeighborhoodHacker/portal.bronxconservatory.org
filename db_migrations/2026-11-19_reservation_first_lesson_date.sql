-- Late starts: a weekly slot can begin part-way through the semester.
-- NULL = the reservation runs from the semester's first class date.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'semester_lesson_reservations'
               AND COLUMN_NAME = 'first_lesson_date');
SET @sql := IF(@has = 0,
  'ALTER TABLE semester_lesson_reservations
     ADD COLUMN first_lesson_date DATE DEFAULT NULL
       COMMENT ''Late start: lessons are generated only on class dates on or after this; NULL = whole semester''
       AFTER duration_minutes',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
