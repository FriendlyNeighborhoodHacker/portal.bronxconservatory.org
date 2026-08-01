-- The registration form's Lesson Preferences step asks for "Class of"
-- (graduation year, optional) instead of the student's age, so it lines up
-- with student_profiles.class_of and survives into the school year.
--
-- Runs after 2026-08-01_leads.sql. Every step is conditional on the current
-- shape of the database, so this is a no-op on an installation that is
-- already current (including one created fresh from schema.sql) and safe to
-- re-run.

SET @add_class_of := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'lead_students') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'class_of') = 0,
    'ALTER TABLE lead_students ADD COLUMN class_of YEAR DEFAULT NULL COMMENT ''Graduation year, optional — copied to student_profiles.class_of on convert'' AFTER last_name',
    'DO 0'
  )
);
PREPARE stmt FROM @add_class_of; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- An age in years is not a graduation year, so the old values are dropped
-- rather than converted.
SET @drop_age := (
  SELECT IF(COUNT(*) > 0, 'ALTER TABLE lead_students DROP COLUMN age', 'DO 0')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'age'
);
PREPARE stmt FROM @drop_age; EXECUTE stmt; DEALLOCATE PREPARE stmt;
