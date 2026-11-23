-- Shirt size is no longer collected or shown anywhere in the portal: drop the
-- two columns that held it. Idempotent. NOT reversible — the sizes recorded
-- so far are deleted with the columns.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'shirt_size') = 1,
  'ALTER TABLE users DROP COLUMN shirt_size', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_students' AND COLUMN_NAME = 'shirt_size') = 1,
  'ALTER TABLE lead_students DROP COLUMN shirt_size', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
