-- Installment plan: the explicit date the remaining balance is due.
-- NULL keeps the legacy behavior (due before the semester's half-way lesson).
ALTER TABLE semesters
  ADD COLUMN second_installment_due_date DATE DEFAULT NULL
    COMMENT 'Installment plan: date the remaining balance is due; NULL = legacy half-way-lesson rule'
    AFTER lessons_per_semester;
