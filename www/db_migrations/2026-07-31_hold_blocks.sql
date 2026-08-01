-- Semester hold blocks: a teacher's non-lesson time (lunch, errand, break)
-- on the semester schedule grid. Mirrors the reservation -> occurrence shape
-- of semester_lesson_reservations -> lessons.
--
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS semester_hold_block_reservations (
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
  KEY idx_shbr_grid (semester_id, location_id, teacher_user_id, status),
  CONSTRAINT fk_shbr_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_teacher FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_shbr_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semester_hold_blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_hold_block_reservation_id INT NOT NULL,
  start_datetime DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  title_override VARCHAR(200) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_hold_block_start (semester_hold_block_reservation_id, start_datetime),
  KEY idx_shb_start (start_datetime),
  CONSTRAINT fk_shb_reservation FOREIGN KEY (semester_hold_block_reservation_id) REFERENCES semester_hold_block_reservations(id) ON DELETE CASCADE,
  CONSTRAINT fk_shb_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
