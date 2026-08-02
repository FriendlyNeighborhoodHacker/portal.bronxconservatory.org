-- Move registration, lesson, and recital pricing from global settings to per-semester columns.
-- This migration adds 7 pricing columns to semesters, backfills from the current global settings
-- (only on first run), then removes the old settings keys.

-- Add registration_fee column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'registration_fee');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN registration_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''Charged once per student when their semester reservation is confirmed'' AFTER end_date',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lesson_fee_30_minutes column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'lesson_fee_30_minutes');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN lesson_fee_30_minutes DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''Full-semester price for weekly 30-minute private lessons'' AFTER registration_fee',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lesson_fee_60_minutes column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'lesson_fee_60_minutes');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN lesson_fee_60_minutes DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''Full-semester price for weekly 60-minute private lessons'' AFTER lesson_fee_30_minutes',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add guitar_ensemble_fee column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'guitar_ensemble_fee');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN guitar_ensemble_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''Full-semester price for 30-minute Guitar Ensemble'' AFTER lesson_fee_60_minutes',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add recital_fee column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'recital_fee');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN recital_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''Charged per lesson block'' AFTER guitar_ensemble_fee',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add installment_plan_fee column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'installment_plan_fee');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN installment_plan_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT ''One-time fee for paying tuition in two installments'' AFTER recital_fee',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lessons_per_semester column if missing
SET @add_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'semesters' AND column_name = 'lessons_per_semester');
SET @sql := IF(@add_col = 0,
  'ALTER TABLE semesters ADD COLUMN lessons_per_semester INT NOT NULL DEFAULT 15 COMMENT ''Used for per-lesson price display on registration form'' AFTER installment_plan_fee',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill pricing columns from global settings (only on first run when columns were just added).
-- This runs only when the columns were newly added, and backfills every existing semester
-- with the current global settings values.
SET @reg_fee := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'registration_cost'), 0);
SET @lesson_30 := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'tuition_30'), 0);
SET @lesson_60 := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'tuition_60'), 0);
SET @ensemble := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'tuition_ensemble'), 0);
SET @recital := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'recital_fee'), 0);
SET @installment := COALESCE((SELECT CAST(value AS DECIMAL(8,2)) FROM settings WHERE key_name = 'installment_fee'), 0);
SET @lessons_per := COALESCE((SELECT CAST(value AS INT) FROM settings WHERE key_name = 'semester_weeks'), 15);

-- Only backfill if columns exist but still have default values (not yet backfilled)
UPDATE semesters s
SET
  s.registration_fee = @reg_fee,
  s.lesson_fee_30_minutes = @lesson_30,
  s.lesson_fee_60_minutes = @lesson_60,
  s.guitar_ensemble_fee = @ensemble,
  s.recital_fee = @recital,
  s.installment_plan_fee = @installment,
  s.lessons_per_semester = @lessons_per
WHERE s.registration_fee = 0.00 AND s.lesson_fee_30_minutes = 0.00;

-- Delete the old global settings keys (idempotent).
DELETE FROM settings WHERE key_name IN (
  'registration_cost',
  'semester_lesson_cost',
  'recital_fee',
  'tuition_30',
  'tuition_60',
  'tuition_ensemble',
  'installment_fee',
  'semester_weeks'
);
