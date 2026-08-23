-- Billing::postSemesterConfirmationCharges posts an installment_plan_fee
-- debit when a semester's installment fee is set, but the enum never gained
-- the value — MySQL truncated the insert. Widen the enum to match
-- Billing::ENTRY_TYPES.
ALTER TABLE ledger_entries
  MODIFY entry_type ENUM('registration','lessons','recital_fee','installment_plan_fee','payment','scholarship_application','other') NOT NULL;
