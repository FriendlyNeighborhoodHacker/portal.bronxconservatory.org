-- The registration wizard now saves a drop-off draft the way the inquiry
-- flow always has — starting the moment a plausible email or phone number is typed.
-- incomplete_inquiries gains a source column so the Uncompleted Forms queue
-- can say which form the family was filling out, and last_step_completed
-- gains registration meanings (1 email/phone only, 2 family info, 3 students,
-- 4 policies, 5 payment plan).
ALTER TABLE incomplete_inquiries
  ADD COLUMN source ENUM('inquiry','registration') NOT NULL DEFAULT 'inquiry'
    COMMENT 'Which public form the visitor was filling out'
    AFTER id;
