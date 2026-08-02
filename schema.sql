-- Bronx Conservatory of Music Portal — application schema
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ (a sibling of this
-- file at the top level, outside the web root) exist only to upgrade older
-- production installations (tracked in schema_migrations, applied via
-- Admin > Migrations).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- One users row per person: parents, students, teachers, and admins.
-- Roles are derived from related rows, not stored here:
--   admin     — is_admin flag
--   developer — is_developer flag; with is_admin it unlocks the Maintenance
--               section (migrations, activity log, email log)
--   teacher — teacher_profiles row
--   parent  — parenthood row (as parent_user_id)
--   student — student_profiles row
-- Email is the login identifier and stays nullable-unique: child students
-- often have no email and cannot sign in. An empty password_hash means the
-- user cannot sign in yet; they can gain a password via an invite or the
-- forgot-password flow.
-- Deleting a student/teacher/parent sets is_deleted = 1 (soft delete): the
-- row and its history stay, but the user can no longer sign in and is
-- excluded from lists and role resolution.
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  suffix VARCHAR(20) DEFAULT NULL,
  preferred_name VARCHAR(100) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  secondary_email VARCHAR(255) DEFAULT NULL,
  cell_phone VARCHAR(30) DEFAULT NULL,
  home_phone VARCHAR(30) DEFAULT NULL,
  preferred_contact_method ENUM('email','phone','text') DEFAULT NULL,
  address_street_1 VARCHAR(255) DEFAULT NULL,
  address_street_2 VARCHAR(255) DEFAULT NULL,
  address_city VARCHAR(100) DEFAULT NULL,
  address_state VARCHAR(50) DEFAULT NULL,
  address_zip VARCHAR(20) DEFAULT NULL,
  emergency_contact_name VARCHAR(200) DEFAULT NULL,
  emergency_contact_phone VARCHAR(30) DEFAULT NULL,
  medical_notes TEXT DEFAULT NULL COMMENT 'Medical conditions or allergies (optional)',
  shirt_size VARCHAR(10) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'App administrator',
  is_developer TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'With is_admin, unlocks Admin > Maintenance (migrations, logs)',
  is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete: cannot sign in, hidden from lists',
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- ===== Settings key-value table =====
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Costs are dollar strings; Settings exposes them in cents
-- (Settings::registrationCostCents() etc.). registration_cost /
-- semester_lesson_cost / recital_fee price a semester confirmation;
-- registration_cost and recital_fee ALSO price the public registration
-- quote (registration: once per family; recital: per lesson block), and
-- tuition_30 / tuition_60 / tuition_ensemble / installment_fee exist only
-- for that quote. registration_semester_id is the semester the public
-- registration wizard is open for ('' = registration closed).
-- inquiry_semester_options are the Semester choices on the public information
-- request form (a JSON array of labels — these are free text, not semesters
-- rows), and inquiry_notification_email is where its staff notification goes.
INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'BCM Portal'),
  ('announcement', ''),
  ('timezone', 'America/New_York'),
  ('login_image_file_id', ''),
  ('site_base_url', 'https://portal.bronxconservatory.org'),
  ('contact_phone', '(718) 841-7415'),
  ('registration_cost', '35.00'),
  ('semester_lesson_cost', '300.00'),
  ('recital_fee', '10.00'),
  ('tuition_30', '420.00'),
  ('tuition_60', '840.00'),
  ('tuition_ensemble', '270.00'),
  ('installment_fee', '20.00'),
  ('semester_weeks', '15'),
  ('registration_semester_id', ''),
  ('inquiry_semester_options', '["Fall 2026","Spring 2027","Summer 2027"]'),
  ('inquiry_notification_email', 'info@bronxconservatory.org')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Schema migrations tracking =====
-- Source of truth for which db_migrations/*.sql files have been applied.
-- Fresh installs load schema.sql (already current), so every migration file
-- that exists at install time should be recorded as applied by the operator
-- or simply left absent (db_migrations starts empty as of the 2026-07 rebuild).
-- Applied via Admin > Migrations (lib/MigrationRunner.php).
CREATE TABLE schema_migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===== Files Storage (DB-backed uploads) =====

-- Public files (profile photos, login logo). Public by design, immutable once
-- stored; served via public_file_download.php or the on-disk cache.
CREATE TABLE public_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_pf_sha256 ON public_files(sha256);
CREATE INDEX idx_pf_created_by ON public_files(created_by_user_id);
CREATE INDEX idx_pf_created_at ON public_files(created_at);

-- Private files (lesson resources: recordings, sheet music). Served only
-- through an authorization-checked download endpoint (resource_download.php)
-- and never written to the disk cache.
CREATE TABLE private_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_prf_sha256 ON private_files(sha256);
CREATE INDEX idx_prf_created_by ON private_files(created_by_user_id);

ALTER TABLE users
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- ===== Activity Log =====
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Instruments =====
-- Fixed reference list (Piano, Guitar, Voice, Violin, Viola, Cello, Double
-- Bass). A reference table rather than an enum so dropdowns and per-student
-- multi-selects come for free.
CREATE TABLE instruments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO instruments (name, sort_order) VALUES
  ('Piano', 1), ('Guitar', 2), ('Voice', 3), ('Violin', 4),
  ('Viola', 5), ('Cello', 6), ('Double Bass', 7)
ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order);

-- ===== Student / Teacher profiles =====
-- Row existence defines the role (see Users above). An adult who takes
-- lessons themselves gets a student_profiles row on their own users row.
CREATE TABLE student_profiles (
  user_id INT PRIMARY KEY,
  date_of_birth DATE DEFAULT NULL,
  class_of YEAR DEFAULT NULL,
  experience_level ENUM('none','beginner','intermediate','advanced') DEFAULT NULL,
  school_name VARCHAR(200) DEFAULT NULL,
  grade VARCHAR(20) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE teacher_profiles (
  user_id INT PRIMARY KEY,
  bio TEXT DEFAULT NULL,
  gender ENUM('female','male','nonbinary') DEFAULT NULL COMMENT 'Used to honor family teacher-gender preferences',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_instruments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_user_id INT NOT NULL,
  instrument_id INT NOT NULL,
  UNIQUE KEY unique_student_instrument (student_user_id, instrument_id),
  CONSTRAINT fk_si_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_instrument FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE teacher_instruments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_user_id INT NOT NULL,
  instrument_id INT NOT NULL,
  UNIQUE KEY unique_teacher_instrument (teacher_user_id, instrument_id),
  CONSTRAINT fk_ti_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_instrument FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Parenthood =====
CREATE TABLE parenthood (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_user_id INT NOT NULL,
  child_user_id INT NOT NULL,
  role ENUM('mother','father','guardian') DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_parent_child (parent_user_id, child_user_id),
  CONSTRAINT fk_ph_parent FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ph_child FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_ph_child ON parenthood(child_user_id);

-- ===== Locations =====
CREATE TABLE locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO locations (name) VALUES
  ('Access Bronx Charter School'),
  ('Bronx Community College');

-- ===== Semesters =====
-- The organizing unit of the whole schedule. "Current semester" resolution
-- (SemesterManagement::resolveDefaultSemester): the semester containing
-- today, else the next future one, else the most recent past one; 'test'
-- semesters are ignored unless nothing else exists.
CREATE TABLE semesters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  season ENUM('fall','spring','summer','test') NOT NULL,
  year SMALLINT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_season_year (season, year),
  CONSTRAINT fk_sem_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sem_dates ON semesters(start_date, end_date);

-- Locations in use for a given semester (semester wizard step 2).
CREATE TABLE semester_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  location_id INT NOT NULL,
  UNIQUE KEY unique_sem_loc (semester_id, location_id),
  CONSTRAINT fk_semloc_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_semloc_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The class calendar per location: which dates classes are held (imported by
-- CSV in the semester wizard). Inactive rows are breaks/holidays and are
-- surfaced to students ("Holiday Week") but generate no lessons.
CREATE TABLE semester_location_dates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  location_id INT NOT NULL,
  date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  title VARCHAR(200) DEFAULT NULL COMMENT '"Day 1", "Holiday Week" — the CSV notes column',
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_sem_loc_date (semester_id, location_id, date),
  CONSTRAINT fk_sld_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_sld_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sld_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sld_date ON semester_location_dates(semester_id, date);

-- Which teachers teach at which location for a semester (semester wizard
-- step 4). These pairs are the columns of the Semester Schedule grid;
-- sort_order fixes the column order within a location.
CREATE TABLE semester_location_teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  location_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY unique_sem_loc_teacher (semester_id, location_id, teacher_user_id),
  CONSTRAINT fk_slt_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_slt_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_slt_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Semester lesson reservations =====
-- Reserves a weekly slot (teacher + location + day + time) for a student for
-- a whole semester. Confirming a reservation generates its lessons rows from
-- the location's active dates and posts the semester's charges to the
-- student's ledger; reverting to pending deletes only FUTURE lessons.
-- Deleting soft-deletes (status='deleted') and removes future lessons; past
-- lessons are kept unchanged.
CREATE TABLE semester_lesson_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  location_id INT NOT NULL,
  student_user_id INT NOT NULL,
  status ENUM('pending_reach_out','pending_confirmation','confirmed','deleted') NOT NULL DEFAULT 'pending_reach_out',
  day_of_week TINYINT NOT NULL COMMENT '0=Sunday ... 6=Saturday (PHP date("w"))',
  start_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_slr_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_slr_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_slr_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_slr_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_slr_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_slr_grid ON semester_lesson_reservations(semester_id, location_id, teacher_user_id, status);
CREATE INDEX idx_slr_student ON semester_lesson_reservations(student_user_id, semester_id);

-- ===== Lessons (occurrences) =====
-- One row per actual lesson on the calendar, generated from a confirmed
-- reservation (never created ad hoc). Teacher, student, and location come
-- from the reservation; this row stores only per-occurrence facts and
-- overrides. lesson_number is the ordinal of the lesson within the semester
-- (1st, 2nd, ...), derived from the location's active-date calendar so it is
-- stable across regeneration.
CREATE TABLE lessons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_lesson_reservation_id INT DEFAULT NULL COMMENT 'NULL for a one-off lesson booked straight onto the calendar',
  -- Set only on a one-off, where there is no reservation to inherit from.
  -- Every read COALESCEs the reservation's value over these.
  semester_id INT DEFAULT NULL,
  teacher_user_id INT DEFAULT NULL,
  student_user_id INT DEFAULT NULL,
  location_id INT DEFAULT NULL,
  start_datetime DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  lesson_number INT NOT NULL COMMENT 'Ordinal within the semester for this reservation; 0 for a one-off',
  location_id_override INT DEFAULT NULL,
  substitute_teacher_user_id INT DEFAULT NULL COMMENT 'Teacher override: who actually taught',
  attended TINYINT(1) DEFAULT NULL COMMENT 'NULL=unmarked, 1=attended, 0=missed',
  cancelled_at DATETIME DEFAULT NULL COMMENT 'Called off: hidden from the admin calendar, still shown to the family and teacher, and no longer holds the slot',
  cancelled_by_user_id INT DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_reservation_number (semester_lesson_reservation_id, lesson_number),
  CONSTRAINT fk_l_reservation FOREIGN KEY (semester_lesson_reservation_id) REFERENCES semester_lesson_reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_loc_override FOREIGN KEY (location_id_override) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_sub_teacher FOREIGN KEY (substitute_teacher_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_l_start ON lessons(start_datetime);
CREATE INDEX idx_l_reservation_start ON lessons(semester_lesson_reservation_id, start_datetime);

-- ===== Semester hold block reservations =====
-- A teacher's non-lesson time at a location: lunch, an errand, a standing
-- break. Structurally the same weekly slot as a lesson reservation, but held
-- for the teacher rather than a student, so it has a title instead of a
-- student and no billing or confirmation state — its blocks materialize as
-- soon as it is created. Deleting soft-deletes (status='deleted') and removes
-- future blocks; past blocks are kept as a record of the teacher's day.
CREATE TABLE semester_hold_block_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  location_id INT NOT NULL,
  status ENUM('active','deleted') NOT NULL DEFAULT 'active',
  day_of_week TINYINT NOT NULL COMMENT '0=Sunday ... 6=Saturday (PHP date("w"))',
  start_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title VARCHAR(200) NOT NULL COMMENT '"Lunch", "Errand" — shown in the grid cell',
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_shbr_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_shbr_grid ON semester_hold_block_reservations(semester_id, location_id, teacher_user_id, status);

-- ===== Semester hold blocks (occurrences) =====
-- One row per actual held slot on the calendar, generated from a hold block
-- reservation exactly the way lessons are generated from a lesson
-- reservation. Unlike lessons these carry no ordinal (a lunch break has no
-- "number"), so the unique key is on the datetime instead. title_override
-- lets one week say something different from the standing title.
CREATE TABLE semester_hold_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_hold_block_reservation_id INT DEFAULT NULL COMMENT 'NULL for a one-off hold booked straight onto the calendar',
  -- Set only on a one-off; title_override carries its title. Every read
  -- COALESCEs the reservation's value over these.
  semester_id INT DEFAULT NULL,
  teacher_user_id INT DEFAULT NULL,
  location_id INT DEFAULT NULL,
  start_datetime DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title_override VARCHAR(200) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_hold_block_start (semester_hold_block_reservation_id, start_datetime),
  CONSTRAINT fk_shb_reservation FOREIGN KEY (semester_hold_block_reservation_id) REFERENCES semester_hold_block_reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_shb_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_shb_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shb_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_shb_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_shb_start ON semester_hold_blocks(start_datetime);

-- ===== Lesson notes =====
-- Notes written after a lesson by the teacher (auto-save upsert on the
-- teacher dashboard) or by an admin (from the calendar's lesson modal).
-- Visible to the student, their parents, and admins. One note per author per
-- lesson.
CREATE TABLE lesson_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_lesson_author (lesson_id, created_by_user_id),
  CONSTRAINT fk_ln_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_ln_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Lesson resources =====
-- Materials attached to a lesson: an uploaded file (recording, sheet music —
-- stored in private_files) or an external link. Downloads go through
-- resource_download.php which checks the student/parent/teacher relationship
-- via the lesson's reservation. file/url requiredness by resource_type is
-- enforced in ResourceManagement.
CREATE TABLE lesson_resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT NOT NULL,
  resource_type ENUM('file','link') NOT NULL,
  title VARCHAR(255) NOT NULL,
  private_file_id INT DEFAULT NULL COMMENT 'Required when resource_type=file',
  url VARCHAR(1000) DEFAULT NULL COMMENT 'Required when resource_type=link',
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lr_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_file FOREIGN KEY (private_file_id) REFERENCES private_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_lr_lesson ON lesson_resources(lesson_id);

-- ===== Announcements =====
-- General announcements shown on dashboards while valid_until has not
-- passed ("recent active announcements").
CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  valid_until DATE NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_a_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_a_valid_until ON announcements(valid_until);

-- ===== Ledger =====
-- Light accounting so balances are explainable. Balance for a student =
-- SUM(debits) - SUM(credits). Confirming a semester reservation posts the
-- registration / lessons / recital_fee debits (idempotent per student +
-- semester + entry_type); payments, scholarships, and adjustments are
-- credits. Amounts are integer cents (exact math; matches Stripe's unit).
--
-- semester_id is what ties money to a term: it is what the parent portal
-- groups a family's balance by, and what "are they on schedule?" is judged
-- against (half the term's charges before it starts, the rest by its
-- half-way lesson). Only entries that genuinely belong to no term — an old
-- book fee, say — leave it NULL.
CREATE TABLE ledger_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  for_student_user_id INT NOT NULL,
  entry_date DATE NOT NULL,
  accounting_type ENUM('debit','credit') NOT NULL,
  entry_type ENUM('registration','lessons','recital_fee','payment','scholarship_application','other') NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  semester_id INT DEFAULT NULL,
  description VARCHAR(500) DEFAULT NULL,
  stripe_checkout_session_id VARCHAR(255) DEFAULT NULL,
  stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL COMMENT 'NULL for webhook-recorded Stripe payments',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_stripe_session_student (stripe_checkout_session_id, for_student_user_id),
  -- The same guarantee for money taken through an embedded card form, where
  -- there is a PaymentIntent but no Checkout Session: the webhook and the
  -- browser's return trip can both try to record it, and only one wins.
  UNIQUE KEY unique_stripe_intent_student (stripe_payment_intent_id, for_student_user_id),
  CONSTRAINT fk_le_student FOREIGN KEY (for_student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_le_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  CONSTRAINT fk_le_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_le_student ON ledger_entries(for_student_user_id, semester_id);
CREATE INDEX idx_le_semester ON ledger_entries(semester_id);

-- ===== Stripe webhook events =====
-- Dedup ledger for incoming webhook deliveries: an event id is processed at
-- most once, so retries and the success-redirect fallback are harmless.
CREATE TABLE stripe_webhook_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stripe_event_id VARCHAR(255) NOT NULL UNIQUE,
  event_type VARCHAR(100) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===== Leads =====
-- A submission from one of the two public forms: the registration wizard
-- (source='registration', which quotes and may take payment) or the
-- information request (source='inquiry', which asks about interest and never
-- quotes). Deliberately NOT live data: no users/student_profiles/reservations
-- are touched until an admin converts the lead (Admin > Leads). Payment (if
-- any) is held on the lead until conversion moves it to a student's ledger via
-- Billing::recordStripePayment.
--
-- The address columns are nullable because an inquiry can be abandoned or come
-- from outside the US; the registration wizard enforces its own address rules
-- in register/family_eval.php.
CREATE TABLE leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT DEFAULT NULL COMMENT 'The semester registration was open for at submit time; always NULL for inquiries',
  status ENUM('new','contacted','scheduled','converted','declined') NOT NULL DEFAULT 'new',
  source ENUM('registration','inquiry') NOT NULL DEFAULT 'registration' COMMENT 'Which public form produced this lead',
  parent_first_name VARCHAR(100) NOT NULL,
  parent_last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  sms_consent TINYINT(1) NOT NULL DEFAULT 0,
  newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  address_country VARCHAR(100) DEFAULT 'United States',
  address_street_1 VARCHAR(255) DEFAULT NULL,
  address_street_2 VARCHAR(255) DEFAULT NULL,
  address_city VARCHAR(100) DEFAULT NULL,
  address_state VARCHAR(100) DEFAULT NULL COMMENT 'US state code, or a free-text province',
  address_zip VARCHAR(20) DEFAULT NULL,
  location_preference VARCHAR(200) DEFAULT NULL,
  preferred_days VARCHAR(200) DEFAULT NULL COMMENT 'JSON array of day names',
  availability_blocks VARCHAR(200) DEFAULT NULL COMMENT 'JSON array of 9-11,11-1,1-3,3-5,5-7',
  scheduling_notes TEXT DEFAULT NULL,
  semester_label VARCHAR(100) DEFAULT NULL COMMENT 'Inquiry only: an option from Settings inquiry_semester_options, not a semesters row',
  owned_instruments VARCHAR(255) DEFAULT NULL COMMENT 'Inquiry only: JSON array of LeadManagement::OWNED_INSTRUMENT_CHOICES',
  owned_instruments_other VARCHAR(200) DEFAULT NULL,
  music_background TEXT DEFAULT NULL COMMENT 'Inquiry only: level and length of prior study',
  theory_program_interest ENUM('yes','no','need_info') DEFAULT NULL,
  theory_knowledge ENUM('none','beginner','intermediate','advanced') DEFAULT NULL COMMENT 'Same vocabulary as student_profiles.experience_level',
  referral_source VARCHAR(100) DEFAULT NULL COMMENT 'How they heard about us; validated in PHP so options can change without DDL',
  inquiry_comments TEXT DEFAULT NULL COMMENT 'Inquiry only: questions or concerns (scheduling_notes is the registration equivalent)',
  policies_agreed_at DATETIME DEFAULT NULL,
  installment_plan TINYINT(1) NOT NULL DEFAULT 0,
  quote_json LONGTEXT DEFAULT NULL COMMENT 'Itemized quote lines as computed at submit',
  amount_quoted_cents INT UNSIGNED NOT NULL DEFAULT 0,
  amount_due_now_cents INT UNSIGNED NOT NULL DEFAULT 0,
  amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
  stripe_checkout_session_id VARCHAR(255) DEFAULT NULL UNIQUE,
  stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  converted_parent_user_id INT DEFAULT NULL,
  converted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lead_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  CONSTRAINT fk_lead_conv_parent FOREIGN KEY (converted_parent_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_leads_status ON leads(status, created_at);
CREATE INDEX idx_leads_email ON leads(email);
CREATE INDEX idx_leads_source ON leads(source, created_at);

-- The students named on a lead. A registration lead fills instrument (the
-- wizard's choice as entered, incl. "Cello/Bass") and lesson_length_minutes;
-- an inquiry lead fills age, enrollment_status and instruments_of_interest
-- instead, and leaves both of those NULL — nothing has been decided yet. The
-- real instruments-table mapping is picked by the admin at convert time.
-- converted_student_user_id makes conversion idempotent: already-converted
-- rows are skipped on re-entry.
CREATE TABLE lead_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  class_of YEAR DEFAULT NULL COMMENT 'Registration: graduation year — copied to student_profiles.class_of on convert',
  age TINYINT UNSIGNED DEFAULT NULL COMMENT 'Inquiry: age as given. Not derived from or to class_of',
  enrollment_status ENUM('new','continuing') DEFAULT NULL COMMENT 'Inquiry: new or continuing student',
  instrument VARCHAR(50) DEFAULT NULL COMMENT 'Registration only: Voice/Piano/Violin/Viola/Guitar/Cello-Bass',
  instruments_of_interest VARCHAR(255) DEFAULT NULL COMMENT 'Inquiry only: JSON array of LeadManagement::INQUIRY_INSTRUMENT_INTERESTS',
  instruments_other VARCHAR(200) DEFAULT NULL,
  lesson_length_minutes INT DEFAULT NULL COMMENT 'Registration only: 30 or 60. NULL on inquiry leads',
  guitar_ensemble TINYINT(1) NOT NULL DEFAULT 0,
  shirt_size VARCHAR(10) DEFAULT NULL COMMENT 'Optional — copied to users.shirt_size on convert',
  converted_student_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ls_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_ls_conv_student FOREIGN KEY (converted_student_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_ls_lead ON lead_students(lead_id);

-- An admin's internal notes on a lead: append-only, so who said what and when
-- is never lost and two admins working the same lead cannot clobber each
-- other. status_after records a status change made in the same save, keeping
-- the history and the lead in agreement. created_by_user_id is NULL for notes
-- carried over from the old single leads.admin_notes column.
CREATE TABLE lead_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL COMMENT 'NULL for notes migrated from leads.admin_notes',
  body TEXT NOT NULL COMMENT 'May be empty when the entry only records a status change',
  status_after ENUM('new','contacted','scheduled','converted','declined') DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lead_note_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lead_note_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_lead_notes_lead ON lead_notes(lead_id, created_at);

-- ===== Uncompleted information-request forms =====
-- Page 1 of the public /inquiry/ flow writes a row here so staff can reach out
-- to a visitor who drops off; page 2 updates it. Page 3 promotes it into a real
-- lead (leads + lead_students) and DELETES this row, so a row here always means
-- "this family never told us about a student." Peer to leads in the admin
-- (Admin > Leads > Uncompleted Forms); never converted directly.
CREATE TABLE incomplete_inquiries (
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_ii_created ON incomplete_inquiries(created_at);
CREATE INDEX idx_ii_email ON incomplete_inquiries(email);

-- Internal notes on an uncompleted form, on the same append-only terms as
-- lead_notes: who said what, when, never overwritten. When the form is
-- finished and becomes a lead these are carried across as a single note on
-- that lead, so the record of the chase survives the conversion.
CREATE TABLE incomplete_inquiry_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  incomplete_inquiry_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_iin_inquiry FOREIGN KEY (incomplete_inquiry_id) REFERENCES incomplete_inquiries(id) ON DELETE CASCADE,
  CONSTRAINT fk_iin_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_iin_inquiry ON incomplete_inquiry_notes(incomplete_inquiry_id, created_at);

-- ===== Admin-editable email templates =====
-- Transactional emails whose wording staff can change without a deploy
-- (Admin > Email Templates). The code owns template_key, name and
-- available_variables — they describe what the calling code actually passes —
-- while the admin owns subject and body_html. Rendering escapes every
-- substituted value (EmailTemplateManagement::render), so a template can never
-- be made to emit injected markup, and an unknown {{placeholder}} renders
-- empty rather than leaking to a family.
CREATE TABLE email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) DEFAULT NULL COMMENT 'When this email is sent, shown to the admin',
  subject VARCHAR(255) NOT NULL,
  body_html LONGTEXT NOT NULL,
  available_variables VARCHAR(1000) DEFAULT NULL COMMENT 'JSON array of {{variable}} names the code supplies',
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_et_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- The shipped wording. Migrations INSERT IGNORE these, so an edited copy is
-- never overwritten by re-running a migration.
INSERT INTO email_templates (template_key, name, description, subject, body_html, available_variables) VALUES
(
  'inquiry_family_confirmation',
  'Information request — family confirmation',
  'Sent to the family when they finish the public Request Information form.',
  'Thank you for your interest in the Bronx Conservatory of Music',
  '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">\n<p>Hello {{parent_first_name}},</p>\n<p>Thank you for asking about lessons for {{student_first_name}}. We have your request and a member of our team will be in touch soon to talk through instruments, teachers, and times.</p>\n<p>If you would like to reach us first, call <strong>{{contact_phone}}</strong> or reply to this email.</p>\n<h3 style="margin-bottom:4px;">What you told us</h3>\n<ul style="margin-top:0;">\n<li>Student: {{student_first_name}} {{student_last_name}}, age {{student_age}} ({{enrollment_status}})</li>\n<li>Instruments of interest: {{instruments_of_interest}}</li>\n<li>Term: {{semester_label}}</li>\n<li>Music theory: {{theory_knowledge}} (free theory program: {{theory_program_interest}})</li>\n<li>Prior study: {{music_background}}</li>\n<li>Questions or concerns: {{comments}}</li>\n</ul>\n<p>We look forward to making music with you.</p>\n<p>— The Bronx Conservatory of Music</p>\n</div>',
  '["parent_first_name","parent_last_name","parent_email","parent_phone","student_first_name","student_last_name","student_age","enrollment_status","instruments_of_interest","semester_label","owned_instruments","music_background","theory_program_interest","theory_knowledge","referral_source","comments","mailing_address","newsletter_opt_in","sms_consent","contact_phone","site_title","lead_admin_url"]'
),
(
  'inquiry_staff_notification',
  'Information request — staff notification',
  'Sent to the staff notification address (Admin > Settings) on every completed Request Information form.',
  'New information request — {{parent_first_name}} {{parent_last_name}}',
  '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">\n<p>Someone finished the Request Information form.</p>\n<table cellpadding="0" cellspacing="0">\n<tr><td style="padding:2px 12px 2px 0;"><strong>Parent</strong></td><td>{{parent_first_name}} {{parent_last_name}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Email</strong></td><td>{{parent_email}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Phone</strong></td><td>{{parent_phone}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Address</strong></td><td>{{mailing_address}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Student</strong></td><td>{{student_first_name}} {{student_last_name}}, age {{student_age}} ({{enrollment_status}})</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Interested in</strong></td><td>{{instruments_of_interest}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Term</strong></td><td>{{semester_label}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Owns</strong></td><td>{{owned_instruments}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Prior study</strong></td><td>{{music_background}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Theory program</strong></td><td>{{theory_program_interest}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Theory level</strong></td><td>{{theory_knowledge}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Heard about us</strong></td><td>{{referral_source}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Newsletter</strong></td><td>{{newsletter_opt_in}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Texts OK</strong></td><td>{{sms_consent}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Comments</strong></td><td>{{comments}}</td></tr>\n</table>\n<p><a href="{{lead_admin_url}}">Open this lead in the portal</a></p>\n</div>',
  '["parent_first_name","parent_last_name","parent_email","parent_phone","student_first_name","student_last_name","student_age","enrollment_status","instruments_of_interest","semester_label","owned_instruments","music_background","theory_program_interest","theory_knowledge","referral_source","comments","mailing_address","newsletter_opt_in","sms_consent","contact_phone","site_title","lead_admin_url"]'
);

INSERT INTO users (first_name,last_name,email,password_hash,is_admin,is_developer,email_verified_at)
VALUES ('Brian','Rosenthal','brian.rosenthal@gmail.com','$2y$12$2IMMsNZ3pwUpTPmcXKQFr.P2grgudYlZZ/m2Y4jTxV1tjGDI9bX7.',1,1,NOW());

INSERT INTO users (first_name,last_name,email,password_hash,is_admin,is_developer,email_verified_at)
VALUES ('Lilly','Rosenthal','lillyjrosenthal123@gmail.com','$2y$12$2IMMsNZ3pwUpTPmcXKQFr.P2grgudYlZZ/m2Y4jTxV1tjGDI9bX7.',1,1,NOW());
