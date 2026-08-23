-- Hold blocks split into types: a plain hold (lunch, an errand — the
-- teacher's unpaid, unchanging time) versus a paid group class held in the
-- slot (Guitar Ensemble, Musicianship Skills). The distinction matters to
-- accounting and, later, to class attendance; on the schedule grids each
-- type gets its own color.
--
-- block_type lives on the reservation; occurrences inherit it through their
-- reservation the way title does. On semester_hold_blocks the column is set
-- only for a one-off block booked straight onto the calendar (which has no
-- reservation to inherit from), mirroring its semester/teacher/location
-- columns.
--
-- Backfill: existing blocks were distinguished by title alone, so titles
-- naming the classes are promoted to their type. Everything else stays
-- 'hold', which is what it behaved as.
--
-- Idempotent: safe to re-run.

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'semester_hold_block_reservations'
               AND column_name = 'block_type');

SET @sql := IF(@col = 0,
  "ALTER TABLE semester_hold_block_reservations
     ADD COLUMN block_type ENUM('hold','guitar_ensemble','musicianship') NOT NULL DEFAULT 'hold'
       COMMENT 'hold = unpaid teacher time; the others are paid group classes'
     AFTER title",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'semester_hold_blocks'
               AND column_name = 'block_type');

SET @sql := IF(@col = 0,
  "ALTER TABLE semester_hold_blocks
     ADD COLUMN block_type ENUM('hold','guitar_ensemble','musicianship') DEFAULT NULL
       COMMENT 'Set only on a one-off block; reads COALESCE the reservation''s value over this'
     AFTER title_override",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE semester_hold_block_reservations
   SET block_type = 'guitar_ensemble'
 WHERE block_type = 'hold' AND title LIKE '%ensemble%';

UPDATE semester_hold_block_reservations
   SET block_type = 'musicianship'
 WHERE block_type = 'hold' AND title LIKE '%musicianship%';

UPDATE semester_hold_blocks
   SET block_type = 'guitar_ensemble'
 WHERE block_type IS NULL AND semester_hold_block_reservation_id IS NULL
   AND title_override LIKE '%ensemble%';

UPDATE semester_hold_blocks
   SET block_type = 'musicianship'
 WHERE block_type IS NULL AND semester_hold_block_reservation_id IS NULL
   AND title_override LIKE '%musicianship%';
