-- Tuition is priced differently for Bronx residents vs. non-residents and
-- online students. The semester now carries both fee schedules: the existing
-- tuition columns are the Bronx-resident rates, and these are the
-- non-resident / online rates, entered on the same semester setup form.
-- (Registration, recital, and installment fees are the same for everyone.)
ALTER TABLE semesters
  ADD COLUMN lesson_fee_30_minutes_nonresident DECIMAL(8,2) NOT NULL DEFAULT 0.00
    COMMENT 'Non-residents / online students: 30-minute lessons per semester'
    AFTER guitar_ensemble_fee,
  ADD COLUMN lesson_fee_60_minutes_nonresident DECIMAL(8,2) NOT NULL DEFAULT 0.00
    COMMENT 'Non-residents / online students: 60-minute lessons per semester'
    AFTER lesson_fee_30_minutes_nonresident,
  ADD COLUMN guitar_ensemble_fee_nonresident DECIMAL(8,2) NOT NULL DEFAULT 0.00
    COMMENT 'Non-residents / online students: Guitar Ensemble per semester'
    AFTER lesson_fee_60_minutes_nonresident;
