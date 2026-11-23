-- Why a lesson was cancelled, and whether the student is owed a make-up for
-- it. The reason decides "rebookable" except for a parent cancelling with 24
-- hours' notice, where the admin says. NULL on both = cancelled before these
-- were recorded. Make-up credits are worked out from these (LessonManagement).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'cancel_reason');
SET @sql := IF(@has = 0,
  'ALTER TABLE lessons
     ADD COLUMN cancel_reason ENUM(''teacher'',''rebook_planned'',''parent_24h'',''parent_late'') DEFAULT NULL
       COMMENT ''Why it was cancelled: the teacher had to; we plan to rebook; the parent with / without 24 hours notice''
       AFTER cancelled_by_user_id,
     ADD COLUMN cancel_rebookable TINYINT(1) DEFAULT NULL
       COMMENT ''1 = the student is owed a make-up lesson for this cancellation; 0 = not; NULL = not recorded''
       AFTER cancel_reason',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
