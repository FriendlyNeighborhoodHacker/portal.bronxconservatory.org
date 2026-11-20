-- Online lessons: a location can be marked online (no address; lesson views
-- say "Online"). Rooms: a teacher's room at a location on a day for the
-- semester — or, at an online location, their meeting link.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'is_online');
SET @sql := IF(@has = 0,
  'ALTER TABLE locations
     ADD COLUMN is_online TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = lessons here are taught online: no address, own days and hours''
       AFTER address',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'semester_location_teachers' AND COLUMN_NAME = 'room');
SET @sql := IF(@has = 0,
  'ALTER TABLE semester_location_teachers
     ADD COLUMN room VARCHAR(255) DEFAULT NULL
       COMMENT ''The teacher''''s room there that day; at an online location, their meeting link''
       AFTER day_of_week',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
