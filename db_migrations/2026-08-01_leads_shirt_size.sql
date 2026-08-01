-- The registration form collects each student's shirt size (optional), which
-- is copied to users.shirt_size when the lead is converted.
--
-- Conditional on the current shape of the database, so this is a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql) and safe to re-run.

SET @add_shirt_size := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'lead_students') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'shirt_size') = 0,
    'ALTER TABLE lead_students ADD COLUMN shirt_size VARCHAR(10) DEFAULT NULL COMMENT ''Optional — copied to users.shirt_size on convert'' AFTER guitar_ensemble',
    'DO 0'
  )
);
PREPARE stmt FROM @add_shirt_size; EXECUTE stmt; DEALLOCATE PREPARE stmt;
