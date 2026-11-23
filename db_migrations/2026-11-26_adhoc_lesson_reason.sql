-- Why a one-off lesson was put on the calendar (make-up, extra, other) and an
-- optional note from the admin who added it. Only a 'makeup' one-off uses a
-- make-up credit. NULL on a one-off = added before reasons were recorded.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'adhoc_reason');
SET @sql := IF(@has = 0,
  'ALTER TABLE lessons
     ADD COLUMN adhoc_reason ENUM(''makeup'',''extra'',''other'') DEFAULT NULL
       COMMENT ''One-off lessons only: why it was added (a make-up uses a make-up credit)''
       AFTER lesson_number,
     ADD COLUMN adhoc_note VARCHAR(500) DEFAULT NULL
       COMMENT ''One-off lessons only: the admin''''s note on why it was added''
       AFTER adhoc_reason',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
