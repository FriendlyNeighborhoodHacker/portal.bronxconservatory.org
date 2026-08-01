-- The number of lessons in a full semester, used by the public registration
-- form to show what tuition works out to per lesson
-- ("$420 for 15 weeks ($28 per lesson)").
--
-- Fills the value in when the row is missing or blank, but never overwrites
-- a value an admin has set — safe to re-run.
INSERT INTO settings (key_name, value) VALUES ('semester_weeks', '15')
ON DUPLICATE KEY UPDATE value = IF(settings.value IS NULL OR settings.value = '', VALUES(value), settings.value);
