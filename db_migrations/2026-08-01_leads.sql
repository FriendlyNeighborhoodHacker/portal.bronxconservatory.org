-- Public registration wizard: leads + lead_students tables and the pricing /
-- registration-window settings the wizard reads.
--
-- OPERATOR NOTE: schema.sql's seeds for registration_cost and recital_fee
-- changed to the public-form prices (35.00 and 10.00). This migration does
-- NOT overwrite your existing values for those two keys — review them by
-- hand in Admin > Settings (they also price the legacy semester-confirmation
-- charges).

CREATE TABLE IF NOT EXISTS leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semester_id INT DEFAULT NULL COMMENT 'The semester registration was open for at submit time',
  status ENUM('new','contacted','scheduled','converted','declined') NOT NULL DEFAULT 'new',
  parent_first_name VARCHAR(100) NOT NULL,
  parent_last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  sms_consent TINYINT(1) NOT NULL DEFAULT 0,
  address_street_1 VARCHAR(255) NOT NULL,
  address_street_2 VARCHAR(255) DEFAULT NULL,
  address_city VARCHAR(100) NOT NULL,
  address_state VARCHAR(50) NOT NULL,
  address_zip VARCHAR(20) NOT NULL,
  location_preference VARCHAR(200) DEFAULT NULL,
  preferred_days VARCHAR(200) DEFAULT NULL COMMENT 'JSON array of day names',
  availability_blocks VARCHAR(200) DEFAULT NULL COMMENT 'JSON array of 9-11,11-1,1-3,3-5,5-7',
  scheduling_notes TEXT DEFAULT NULL,
  policies_agreed_at DATETIME DEFAULT NULL,
  installment_plan TINYINT(1) NOT NULL DEFAULT 0,
  quote_json LONGTEXT DEFAULT NULL COMMENT 'Itemized quote lines as computed at submit',
  amount_quoted_cents INT UNSIGNED NOT NULL DEFAULT 0,
  amount_due_now_cents INT UNSIGNED NOT NULL DEFAULT 0,
  amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
  stripe_checkout_session_id VARCHAR(255) DEFAULT NULL UNIQUE,
  stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  admin_notes TEXT DEFAULT NULL,
  converted_parent_user_id INT DEFAULT NULL,
  converted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lead_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  CONSTRAINT fk_lead_conv_parent FOREIGN KEY (converted_parent_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_leads_status ON leads(status, created_at);
CREATE INDEX idx_leads_email ON leads(email);

CREATE TABLE IF NOT EXISTS lead_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  age TINYINT UNSIGNED DEFAULT NULL,
  instrument VARCHAR(50) NOT NULL COMMENT 'As chosen: Voice/Piano/Violin/Viola/Guitar/Cello-Bass',
  lesson_length_minutes INT NOT NULL DEFAULT 30 COMMENT '30 or 60',
  guitar_ensemble TINYINT(1) NOT NULL DEFAULT 0,
  converted_student_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ls_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_ls_conv_student FOREIGN KEY (converted_student_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_ls_lead ON lead_students(lead_id);

-- New settings only; existing keys (registration_cost, recital_fee) are
-- deliberately left alone.
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('tuition_30', '420.00'),
  ('tuition_60', '840.00'),
  ('tuition_ensemble', '270.00'),
  ('installment_fee', '20.00'),
  ('registration_semester_id', '');
