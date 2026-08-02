-- A parent paying their balance from the portal now uses an embedded card
-- form (a PaymentIntent), not a hosted Checkout Session — so there is no
-- session id to deduplicate on. This adds the same uniqueness guarantee for
-- the intent, which is what lets the webhook and the browser's return trip
-- both try to record a payment while only one row is ever written.
--
-- Existing rows are unaffected: manual entries leave the intent NULL (MySQL
-- treats NULLs in a unique key as distinct), and a Stripe payment was already
-- unique per (session, student), which makes it unique per (intent, student)
-- too.
--
-- Conditional on the current shape of the database, so this is a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql) and safe to re-run.

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ledger_entries'
      AND index_name = 'unique_stripe_intent_student') = 0,
  'ALTER TABLE ledger_entries ADD UNIQUE KEY unique_stripe_intent_student (stripe_payment_intent_id, for_student_user_id)',
  'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
