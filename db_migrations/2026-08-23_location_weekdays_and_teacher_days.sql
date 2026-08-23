-- Locations and teachers become day-of-week aware.
--
-- A location may hold classes on more than one weekday (the conservatory runs
-- Saturdays and Tuesdays), and a teacher who works Saturdays does not
-- necessarily work Tuesdays. Two changes, consolidated:
--
--  1. semester_location_weekdays (new): declares which weekdays each location
--     is open for a semester, with its standard hours that day. The
--     class-dates import is validated against it, blank times in that CSV
--     inherit these hours, and the schedule grid draws one table per class
--     day over them. Backfilled from the existing class-date calendar — one
--     row per (semester, location, weekday) with that weekday's earliest open
--     and latest close — touching only (semester, location) pairs with NO
--     declared rows, so a re-run never resurrects a deliberately deleted day.
--
--  2. semester_location_teachers gains day_of_week: existing (location,
--     teacher) pairs are expanded into one row per weekday their location
--     actually has class dates on, which reproduces exactly what the grid
--     drew before.
--
-- Conditional on the current shape of the database throughout, so this is
-- safe to re-run and a no-op on an installation created fresh from schema.sql.

-- ===== 1. Declared location weekdays =====

CREATE TABLE IF NOT EXISTS semester_location_weekdays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  location_id INT NOT NULL,
  day_of_week TINYINT NOT NULL COMMENT '0=Sunday..6=Saturday, PHP date(w)',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  UNIQUE KEY unique_sem_loc_weekday (semester_id, location_id, day_of_week),
  CONSTRAINT fk_slw_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_slw_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO semester_location_weekdays (semester_id, location_id, day_of_week, start_time, end_time)
SELECT d.semester_id, d.location_id, d.dow, d.opens, d.closes FROM (
  SELECT semester_id, location_id, DAYOFWEEK(date) - 1 AS dow,
         MIN(start_time) AS opens, MAX(end_time) AS closes
  FROM semester_location_dates GROUP BY semester_id, location_id, DAYOFWEEK(date) - 1
) d
WHERE NOT EXISTS (SELECT 1 FROM semester_location_weekdays w
                  WHERE w.semester_id = d.semester_id AND w.location_id = d.location_id);

-- ===== 2. Teacher assignments per day =====

-- 2a. The column, nullable for now so the backfill can tell old rows apart.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'semester_location_teachers'
      AND column_name = 'day_of_week') = 0,
  'ALTER TABLE semester_location_teachers ADD COLUMN day_of_week TINYINT DEFAULT NULL AFTER teacher_user_id',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. The new key first: fk_slt_semester leans on the old unique index, so
--     MySQL refuses to drop it until another index starting with semester_id
--     exists. Adding it while day_of_week is still NULL is fine — the old
--     key already guarantees the triples are distinct.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'semester_location_teachers'
      AND index_name = 'unique_sem_loc_teacher_day') = 0,
  'ALTER TABLE semester_location_teachers
     ADD UNIQUE KEY unique_sem_loc_teacher_day (semester_id, location_id, teacher_user_id, day_of_week)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2c. Now the old unique key can go — it has to, before the expansion, or
--     every extra day would collide with the row it was expanded from.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'semester_location_teachers'
      AND index_name = 'unique_sem_loc_teacher') > 0,
  'ALTER TABLE semester_location_teachers DROP INDEX unique_sem_loc_teacher',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2d. One row per weekday the pair's location meets on. sort_order is carried
--     across so the column order within a location is unchanged on every day.
INSERT INTO semester_location_teachers (semester_id, location_id, teacher_user_id, day_of_week, sort_order)
SELECT slt.semester_id, slt.location_id, slt.teacher_user_id, d.dow, slt.sort_order
FROM semester_location_teachers slt
JOIN (SELECT DISTINCT semester_id, location_id, DAYOFWEEK(date) - 1 AS dow
      FROM semester_location_dates) d
  ON d.semester_id = slt.semester_id AND d.location_id = slt.location_id
WHERE slt.day_of_week IS NULL;

-- 2e. A location with no class dates at all has nothing to expand over; keep
--     its pairs on Saturday, which is what the grid fell back to before.
UPDATE semester_location_teachers slt SET slt.day_of_week = 6
WHERE slt.day_of_week IS NULL
  AND NOT EXISTS (SELECT 1 FROM semester_location_dates sld
                  WHERE sld.semester_id = slt.semester_id AND sld.location_id = slt.location_id);

-- 2f. Drop the originals that step 2d expanded.
DELETE FROM semester_location_teachers WHERE day_of_week IS NULL;

-- 2g. Now that every row has a day, make it required.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'semester_location_teachers'
      AND column_name = 'day_of_week' AND is_nullable = 'YES') = 1,
  'ALTER TABLE semester_location_teachers
     MODIFY COLUMN day_of_week TINYINT NOT NULL COMMENT ''0=Sunday..6=Saturday, PHP date(w)''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
