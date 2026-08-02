-- The public "Request More Information" flow (/inquiry/).
--
-- Page 1 of that flow saves an uncompleted form so staff can reach out to a
-- visitor who drops off; page 3 promotes it into a real lead. That means:
--   * a new incomplete_inquiries table,
--   * a source column so inquiry leads and registration leads share one queue,
--   * columns for what the inquiry asks and registration does not,
--   * and nullable address / instrument / lesson-length columns, because an
--     inquiry has no instrument decided yet and may come from outside the US.
--
-- Relaxing the address columns is inert for the registration wizard: it always
-- wrote trimmed strings (never NULL), and its own validation lives in
-- register/family_eval.php, which is unchanged.
--
-- Every step is conditional on the current shape of the database, so this is a
-- no-op on an installation that is already current (including one created
-- fresh from schema.sql) and safe to re-run.
--
-- Operator note: inquiry_semester_options ships with placeholder terms —
-- review them in Admin > Settings.

-- ===== incomplete_inquiries =====
-- Indexes are declared inline so CREATE TABLE IF NOT EXISTS covers them and
-- this file stays re-runnable.
CREATE TABLE IF NOT EXISTS incomplete_inquiries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  sms_consent TINYINT(1) NOT NULL DEFAULT 0,
  address_country VARCHAR(100) DEFAULT NULL,
  address_street_1 VARCHAR(255) DEFAULT NULL,
  address_street_2 VARCHAR(255) DEFAULT NULL,
  address_city VARCHAR(100) DEFAULT NULL,
  address_state VARCHAR(100) DEFAULT NULL COMMENT 'US state code, or a free-text province',
  address_zip VARCHAR(20) DEFAULT NULL,
  last_step_completed TINYINT NOT NULL DEFAULT 1 COMMENT '1 = contact only, 2 = address too',
  admin_notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ii_created (created_at),
  KEY idx_ii_email (email)
) ENGINE=InnoDB;

-- ===== leads: new columns =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'source') = 0,
  'ALTER TABLE leads ADD COLUMN source ENUM(''registration'',''inquiry'') NOT NULL DEFAULT ''registration'' COMMENT ''Which public form produced this lead'' AFTER status',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND index_name = 'idx_leads_source') = 0,
  'CREATE INDEX idx_leads_source ON leads(source, created_at)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'newsletter_opt_in') = 0,
  'ALTER TABLE leads ADD COLUMN newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_consent',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'address_country') = 0,
  'ALTER TABLE leads ADD COLUMN address_country VARCHAR(100) DEFAULT ''United States'' AFTER newsletter_opt_in',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'semester_label') = 0,
  'ALTER TABLE leads ADD COLUMN semester_label VARCHAR(100) DEFAULT NULL COMMENT ''Inquiry only: an option from Settings inquiry_semester_options, not a semesters row'' AFTER scheduling_notes',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'owned_instruments') = 0,
  'ALTER TABLE leads ADD COLUMN owned_instruments VARCHAR(255) DEFAULT NULL COMMENT ''Inquiry only: JSON array of LeadManagement::OWNED_INSTRUMENT_CHOICES'' AFTER semester_label',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'owned_instruments_other') = 0,
  'ALTER TABLE leads ADD COLUMN owned_instruments_other VARCHAR(200) DEFAULT NULL AFTER owned_instruments',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'music_background') = 0,
  'ALTER TABLE leads ADD COLUMN music_background TEXT DEFAULT NULL COMMENT ''Inquiry only: level and length of prior study'' AFTER owned_instruments_other',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'theory_program_interest') = 0,
  'ALTER TABLE leads ADD COLUMN theory_program_interest ENUM(''yes'',''no'',''need_info'') DEFAULT NULL AFTER music_background',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'theory_knowledge') = 0,
  'ALTER TABLE leads ADD COLUMN theory_knowledge ENUM(''none'',''beginner'',''intermediate'',''advanced'') DEFAULT NULL COMMENT ''Same vocabulary as student_profiles.experience_level'' AFTER theory_program_interest',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'referral_source') = 0,
  'ALTER TABLE leads ADD COLUMN referral_source VARCHAR(100) DEFAULT NULL COMMENT ''How they heard about us; validated in PHP so options can change without DDL'' AFTER theory_knowledge',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'inquiry_comments') = 0,
  'ALTER TABLE leads ADD COLUMN inquiry_comments TEXT DEFAULT NULL COMMENT ''Inquiry only: questions or concerns'' AFTER referral_source',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== leads: relax the address columns =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads'
      AND column_name = 'address_street_1' AND is_nullable = 'NO') = 1,
  'ALTER TABLE leads MODIFY COLUMN address_street_1 VARCHAR(255) DEFAULT NULL',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads'
      AND column_name = 'address_city' AND is_nullable = 'NO') = 1,
  'ALTER TABLE leads MODIFY COLUMN address_city VARCHAR(100) DEFAULT NULL',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- address_state also widens to 100 for province names, so this one is
-- conditional on either the nullability or the old length.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads' AND column_name = 'address_state'
      AND (is_nullable = 'NO' OR character_maximum_length = 50)) = 1,
  'ALTER TABLE leads MODIFY COLUMN address_state VARCHAR(100) DEFAULT NULL COMMENT ''US state code, or a free-text province''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leads'
      AND column_name = 'address_zip' AND is_nullable = 'NO') = 1,
  'ALTER TABLE leads MODIFY COLUMN address_zip VARCHAR(20) DEFAULT NULL',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== lead_students: new columns =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'age') = 0,
  'ALTER TABLE lead_students ADD COLUMN age TINYINT UNSIGNED DEFAULT NULL COMMENT ''Inquiry: age as given. Not derived from or to class_of'' AFTER class_of',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'enrollment_status') = 0,
  'ALTER TABLE lead_students ADD COLUMN enrollment_status ENUM(''new'',''continuing'') DEFAULT NULL COMMENT ''Inquiry: new or continuing student'' AFTER age',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'instruments_of_interest') = 0,
  'ALTER TABLE lead_students ADD COLUMN instruments_of_interest VARCHAR(255) DEFAULT NULL COMMENT ''Inquiry only: JSON array of LeadManagement::INQUIRY_INSTRUMENT_INTERESTS'' AFTER instrument',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students' AND column_name = 'instruments_other') = 0,
  'ALTER TABLE lead_students ADD COLUMN instruments_other VARCHAR(200) DEFAULT NULL AFTER instruments_of_interest',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== lead_students: relax instrument and lesson length =====

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students'
      AND column_name = 'instrument' AND is_nullable = 'NO') = 1,
  'ALTER TABLE lead_students MODIFY COLUMN instrument VARCHAR(50) DEFAULT NULL COMMENT ''Registration only: Voice/Piano/Violin/Viola/Guitar/Cello-Bass''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'lead_students'
      AND column_name = 'lesson_length_minutes' AND is_nullable = 'NO') = 1,
  'ALTER TABLE lead_students MODIFY COLUMN lesson_length_minutes INT DEFAULT NULL COMMENT ''Registration only: 30 or 60. NULL on inquiry leads''',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===== Settings =====
-- INSERT IGNORE, never ON DUPLICATE KEY UPDATE: an admin's edited list of
-- semester options must survive a re-run.
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('inquiry_semester_options', '["Fall 2026","Spring 2027","Summer 2027"]'),
  ('inquiry_notification_email', 'info@bronxconservatory.org');
