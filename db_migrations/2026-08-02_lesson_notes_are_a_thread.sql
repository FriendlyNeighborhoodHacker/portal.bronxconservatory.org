-- Lesson notes become real notes: each one its own row, kept with who wrote
-- it and when, instead of a single editable box per author that auto-save
-- overwrote as the teacher typed. Families write them too now, so a lesson
-- accumulates a short thread rather than one person's latest draft.
--
-- Existing notes are left exactly as they are — they simply become the first
-- note in their lesson's thread. All this does is remove the unique key that
-- forced one row per author, adding a plain index on lesson_id first so the
-- foreign key still has one to use.
--
-- Conditional on the current shape of the database, so this is a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql) and safe to re-run.

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'lesson_notes'
      AND index_name = 'idx_ln_lesson') = 0,
  'CREATE INDEX idx_ln_lesson ON lesson_notes(lesson_id, created_at)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'lesson_notes'
      AND index_name = 'unique_lesson_author') > 0,
  'ALTER TABLE lesson_notes DROP INDEX unique_lesson_author',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
