-- student_profiles.demographic: the student's demographic group, recorded for
-- the conservatory's own reporting.
--
-- Admin-only by construction: the column is read and written through
-- StudentTeacherManagement::demographicForStudent /
-- setStudentDemographic, both of which require an admin UserContext, and it
-- is deliberately absent from every family- and teacher-facing query
-- (childrenOfParent, listStudentsFiltered, the student and teacher pages).
--
-- NULL means not recorded, which is what every existing student starts as —
-- nothing is inferred or backfilled.
--
-- Idempotent: safe to re-run.

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'student_profiles'
               AND column_name = 'demographic');

SET @sql := IF(@col = 0,
  "ALTER TABLE student_profiles
     ADD COLUMN demographic ENUM('B','L','W','AAPI','O') DEFAULT NULL
       COMMENT 'Admin-only: never shown to families or teachers. NULL = not recorded.'
     AFTER grade",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
