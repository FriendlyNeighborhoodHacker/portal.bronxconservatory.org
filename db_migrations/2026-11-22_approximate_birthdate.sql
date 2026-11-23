-- A date of birth the conservatory never recorded: worked out from the
-- student's graduating class (lead conversion: March 1 of class year - 18) or
-- from an age on an old roster. The flag marks it as a stand-in so nobody
-- treats it as the real birthday; saving a real date clears it.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_profiles'
               AND COLUMN_NAME = 'date_of_birth_is_approximate');
SET @sql := IF(@has = 0,
  'ALTER TABLE student_profiles
     ADD COLUMN date_of_birth_is_approximate TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = date_of_birth is a stand-in derived from class_of or an age, not the real birthday''
       AFTER date_of_birth',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
