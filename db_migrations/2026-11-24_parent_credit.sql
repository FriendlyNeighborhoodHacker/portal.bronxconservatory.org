-- Parent credit: money a parent has paid that is not (yet) set against any
-- one child's charges — typically a registration payment for a family with
-- more than one child, converted before every child has a confirmed slot.
-- It is the parent's own small ledger: a 'credit' row adds to it, a 'debit'
-- row is credit used, and its balance is credits minus debits. When a child
-- is charged, Billing draws on it (after the child's own credit): the child's
-- ledger gets a payment line and this table gets the matching debit.
CREATE TABLE IF NOT EXISTS parent_credit_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_user_id INT NOT NULL,
  entry_date DATE NOT NULL,
  accounting_type ENUM('credit','debit') NOT NULL COMMENT 'credit = added to the parent credit; debit = used',
  amount_cents INT UNSIGNED NOT NULL,
  semester_id INT DEFAULT NULL,
  description VARCHAR(500) DEFAULT NULL,
  stripe_checkout_session_id VARCHAR(255) DEFAULT NULL,
  stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
  applied_to_student_user_id INT DEFAULT NULL COMMENT 'debit rows: the child whose charges it paid',
  ledger_entry_id INT DEFAULT NULL COMMENT 'debit rows: the payment line it created on that child''s ledger',
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_pce_stripe_session_parent (stripe_checkout_session_id, parent_user_id),
  KEY idx_pce_parent (parent_user_id),
  CONSTRAINT fk_pce_parent FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pce_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  CONSTRAINT fk_pce_student FOREIGN KEY (applied_to_student_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pce_ledger FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_pce_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
