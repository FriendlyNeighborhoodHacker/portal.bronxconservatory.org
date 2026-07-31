-- Bronx Conservatory of Music Family Portal — application schema
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ exist only to upgrade
-- older production installations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- One users row per person: parents, students, teachers, and admins.
-- Roles are derived from related rows, not stored here:
--   admin   — is_admin flag
--   teacher — teacher_profiles row
--   parent  — parenthood row (as parent_user_id)
--   student — student_profiles row
-- Email is the login identifier and stays nullable-unique: child students
-- often have no email and cannot sign in. An empty password_hash means the
-- user cannot sign in yet; they can gain a password via an invite or the
-- forgot-password flow.
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

INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'BCM Family Portal'),
  ('announcement', ''),
  ('timezone', 'America/New_York'),
  ('login_image_file_id', ''),
  ('site_base_url', 'https://portal.bronxconservatory.org'),
  ('family_token_expiry_days', '30'),
  ('contact_phone', '(718) 841-7415')
ON DUPLICATE KEY UPDATE value=VALUES(value);

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

-- ===== Families =====
-- The registration unit: one family per registration form submission (parents
-- and their student children). status drives the admin Action Queue:
--   needs_follow_up   — submitted "I'd like to talk first"; admin will call
--   ready_to_enroll   — submitted the fast path; admin assigns a schedule
--   schedule_assigned — admin entered a schedule and emailed the family
--   enrolled          — family confirmed (or admin marked) enrollment
CREATE TABLE families (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family_name VARCHAR(200) NOT NULL COMMENT 'Usually the parent last name, e.g. "Martinez"',
  status ENUM('needs_follow_up','ready_to_enroll','schedule_assigned','enrolled') NOT NULL DEFAULT 'needs_follow_up',
  primary_parent_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_families_status ON families(status);

-- Denormalized family pointer on users (parents AND students carry it) so the
-- action queue and dashboards resolve a family's people in one query.
ALTER TABLE users
  ADD COLUMN family_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_family
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE SET NULL;

ALTER TABLE families
  ADD CONSTRAINT fk_families_primary_parent
    FOREIGN KEY (primary_parent_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_users_family ON users(family_id);

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

-- ===== Registration submissions =====
-- One row per submitted registration form (a family re-enrolling later adds
-- another row). Scheduling preferences live here, not on the family, so the
-- admin can always see what was asked for at each registration.
CREATE TABLE registration_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family_id INT NOT NULL,
  submitted_by_user_id INT DEFAULT NULL,
  path ENUM('complete_enrollment','talk_first') NOT NULL,
  preferred_days VARCHAR(50) DEFAULT NULL COMMENT 'CSV of Mon,Tue,...,Sun',
  time_window VARCHAR(50) DEFAULT NULL COMMENT 'CSV of morning,afternoon,evening',
  preferred_location_id INT DEFAULT NULL,
  teacher_gender_pref ENUM('none','female','male') NOT NULL DEFAULT 'none',
  constraints_text TEXT DEFAULT NULL COMMENT 'Free-text scheduling constraints, e.g. "siblings need back-to-back"',
  how_heard ENUM('friend_family','school','community_org','social_media','website','other') DEFAULT NULL,
  consent_photo_release TINYINT(1) NOT NULL DEFAULT 0,
  consent_terms TINYINT(1) NOT NULL DEFAULT 0,
  consent_liability TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rs_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_rs_location FOREIGN KEY (preferred_location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_rs_family ON registration_submissions(family_id);

-- ===== Recurring lessons (templates) =====
-- A weekly template; real lesson rows are materialized from it via
-- LessonManagement::generateOccurrencesThrough (idempotent — per-occurrence
-- substitutes, cancellations, attendance, and notes need real rows).
CREATE TABLE recurring_lessons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_type ENUM('individual','group') NOT NULL DEFAULT 'individual',
  name VARCHAR(200) DEFAULT NULL COMMENT 'Group class name, e.g. "Saturday Violin Ensemble"',
  instrument_id INT DEFAULT NULL,
  teacher_user_id INT NOT NULL,
  student_user_id INT DEFAULT NULL COMMENT 'Individual lessons only; group rosters live in recurring_lesson_students',
  location_id INT DEFAULT NULL,
  room VARCHAR(50) DEFAULT NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  day_of_week TINYINT NOT NULL COMMENT '0=Sunday ... 6=Saturday (PHP date("w"))',
  start_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  start_date DATE NOT NULL,
  end_date DATE DEFAULT NULL COMMENT 'NULL = open-ended',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rl_instrument FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE SET NULL,
  CONSTRAINT fk_rl_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rl_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rl_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_rl_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE recurring_lesson_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recurring_lesson_id INT NOT NULL,
  student_user_id INT NOT NULL,
  UNIQUE KEY unique_rls (recurring_lesson_id, student_user_id),
  CONSTRAINT fk_rls_rl FOREIGN KEY (recurring_lesson_id) REFERENCES recurring_lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_rls_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recurring_lesson_teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recurring_lesson_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  UNIQUE KEY unique_rlt (recurring_lesson_id, teacher_user_id),
  CONSTRAINT fk_rlt_rl FOREIGN KEY (recurring_lesson_id) REFERENCES recurring_lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_rlt_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Lessons (occurrences) =====
-- One row per actual lesson on the calendar, whether one-off or materialized
-- from a recurring template. For individual lessons the student is
-- student_user_id and attendance is the attended column; group lessons keep
-- their roster and per-student attendance in group_lesson_students.
CREATE TABLE lessons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_type ENUM('individual','group') NOT NULL DEFAULT 'individual',
  name VARCHAR(200) DEFAULT NULL COMMENT 'Group class name',
  instrument_id INT DEFAULT NULL,
  teacher_user_id INT NOT NULL,
  student_user_id INT DEFAULT NULL COMMENT 'Individual lessons only',
  location_id INT DEFAULT NULL,
  room VARCHAR(50) DEFAULT NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  start_datetime DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  attended TINYINT(1) DEFAULT NULL COMMENT 'Individual lessons: NULL=unmarked',
  substitute_teacher_user_id INT DEFAULT NULL COMMENT 'Teacher override: who actually taught',
  recurring_lesson_id INT DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_l_instrument FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_l_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_sub_teacher FOREIGN KEY (substitute_teacher_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_recurring FOREIGN KEY (recurring_lesson_id) REFERENCES recurring_lessons(id) ON DELETE SET NULL,
  CONSTRAINT fk_l_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_l_teacher_start ON lessons(teacher_user_id, start_datetime);
CREATE INDEX idx_l_student_start ON lessons(student_user_id, start_datetime);
CREATE INDEX idx_l_recurring_start ON lessons(recurring_lesson_id, start_datetime);

CREATE TABLE group_lesson_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT NOT NULL,
  student_user_id INT NOT NULL,
  attended TINYINT(1) DEFAULT NULL COMMENT 'NULL=unmarked',
  UNIQUE KEY unique_gls (lesson_id, student_user_id),
  CONSTRAINT fk_gls_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_gls_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_gls_student ON group_lesson_students(student_user_id);

CREATE TABLE group_lesson_teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  UNIQUE KEY unique_glt (lesson_id, teacher_user_id),
  CONSTRAINT fk_glt_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_glt_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_glt_teacher ON group_lesson_teachers(teacher_user_id);

-- ===== Notes =====
-- Internal admin notes about a family or a person (the spec's student_notes /
-- teacher_notes / parent_notes / family follow-up notes as one table).
-- Visible to admins only.
CREATE TABLE notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('family','student','teacher','parent') NOT NULL,
  family_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL COMMENT 'The person the note is about (student/teacher/parent notes)',
  body TEXT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_n_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_n_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_n_family ON notes(family_id);
CREATE INDEX idx_n_user ON notes(user_id);

-- Notes the teacher writes after a lesson. Visible to the student, their
-- parents, and admins. One note per teacher per lesson; the teacher
-- dashboard's auto-save upserts into it.
CREATE TABLE lesson_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_lesson_teacher (lesson_id, teacher_user_id),
  CONSTRAINT fk_ln_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_ln_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Lesson resources =====
-- Recordings, sheet music, etc. Attached to a lesson and/or directly to a
-- student. The binary lives in private_files; downloads go through
-- resource_download.php which checks the student/parent/teacher relationship.
CREATE TABLE lesson_resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT DEFAULT NULL,
  student_user_id INT DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  private_file_id INT NOT NULL,
  uploaded_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lr_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_file FOREIGN KEY (private_file_id) REFERENCES private_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_lr_student ON lesson_resources(student_user_id);
CREATE INDEX idx_lr_lesson ON lesson_resources(lesson_id);

-- ===== Announcements =====
-- Holiday schedules, recital information, general updates. audience limits
-- which dashboards show it.
CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  audience ENUM('all','parents','students','teachers') NOT NULL DEFAULT 'all',
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_a_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_a_published ON announcements(published_at);

-- ===== Family access tokens (email limited-auth flow) =====
-- The "Great news — here's your schedule" email contains a token link that
-- authenticates its recipient for exactly one family, only within the /f/
-- pages (review schedule, confirm enrollment). The raw 64-hex-char token
-- exists only inside the email; the DB stores its sha256.
CREATE TABLE family_access_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'The user this token authenticates as',
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fat_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  CONSTRAINT fk_fat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_fat_family_user ON family_access_tokens(family_id, user_id);

-- ===== Notification Log =====
-- Every notification email is recorded here; senders check it first so runs
-- are idempotent.
-- notification_type:
--   lesson_reminder   — daily digest of upcoming lessons (future runner)
--   schedule_assigned — the "Great news" email when an admin assigns a schedule
--   account_invite    — the set-up-your-account invite after registration
CREATE TABLE notification_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  family_id INT DEFAULT NULL,
  lesson_id INT DEFAULT NULL,
  recipient_user_id INT NOT NULL,
  notification_type ENUM('lesson_reminder','schedule_assigned','account_invite') NOT NULL,
  notification_date DATE NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email_address VARCHAR(255) DEFAULT NULL,
  delivery_status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_nl_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE SET NULL,
  CONSTRAINT fk_nl_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_nl_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_nl_dedup ON notification_log(lesson_id, recipient_user_id, notification_type, notification_date);
CREATE INDEX idx_nl_date ON notification_log(notification_date);

-- Seed admin user: sign in with email "lilly" / password "lilly".
-- Regenerate the hash with: php -r "echo password_hash('lilly', PASSWORD_DEFAULT);"
INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
VALUES ('Lilly','Rosenthal','lilly','$2y$12$2IMMsNZ3pwUpTPmcXKQFr.P2grgudYlZZ/m2Y4jTxV1tjGDI9bX7.',1,NOW());
