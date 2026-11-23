-- Registration now serves families outside the Bronx and online students,
-- who pay the semester's non-resident / online fees. The flag records which
-- rate a registration lead was quoted at (Bronx resident = city "Bronx" or a
-- ZIP of 10451-10475, studying in person).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
               AND COLUMN_NAME = 'nonresident_pricing');
SET @sql := IF(@has = 0,
  'ALTER TABLE leads
     ADD COLUMN nonresident_pricing TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = quoted at the non-resident / online rate (not a Bronx resident, or chose Online Lessons)''
       AFTER installment_plan',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
