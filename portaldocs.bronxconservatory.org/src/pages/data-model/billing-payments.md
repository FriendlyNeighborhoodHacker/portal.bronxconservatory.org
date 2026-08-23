---
layout: ../../layouts/DocsLayout.astro
title: Billing & Payments
description: The ledger that makes every balance explainable, and Stripe webhook deduplication.
---

# Billing & Payments

**Tables:** `ledger_entries`, `stripe_webhook_events`

## `ledger_entries`

Light double-entry-style accounting so balances are **explainable**. A
student's balance is simply:

```sql
SUM(debits) - SUM(credits)   -- per for_student_user_id
```

Key columns:

- **`for_student_user_id`** — money is tracked per *student*, not per parent.
  A parent's balance is the sum over their children.
- **`accounting_type`** ENUM(`debit`,`credit`) — charges are debits; payments,
  scholarships, and downward adjustments are credits.
- **`entry_type`** ENUM(`registration`, `lessons`, `recital_fee`, `payment`,
  `scholarship_application`, `other`) — what kind of line this is.
- **`amount_cents`** `INT UNSIGNED` — integer cents: exact math, and the same
  unit Stripe uses.
- **`semester_id`** — ties money to a term. It is what the parent portal
  groups a family's balance by, and what "are they on schedule?" is judged
  against (half the term's charges before it starts, the rest by its half-way
  lesson). Only entries that genuinely belong to no term — an old book fee,
  say — leave it `NULL`. `ON DELETE SET NULL`.
- **`stripe_checkout_session_id`** / **`stripe_payment_intent_id`** — set on
  entries recorded from Stripe.
- **`created_by_user_id`** — `NULL` for webhook-recorded Stripe payments.

### Idempotency

Recording money must survive being attempted twice — the Stripe webhook and
the browser's return trip can both try to record the same payment. Two unique
keys guarantee only one wins:

- (`stripe_checkout_session_id`, `for_student_user_id`) — for Checkout
  payments
- (`stripe_payment_intent_id`, `for_student_user_id`) — for embedded card-form
  payments, where there is a PaymentIntent but no Checkout Session

Charge posting is idempotent too: confirming a reservation posts the
registration / lessons / recital-fee debits at most once per student +
semester + entry type (`Billing::postSemesterConfirmationCharges`), and
un-confirming reverses them (`reverseSemesterConfirmationCharges`).

### How entries are created

- **Confirming a reservation** posts the semester's registration, lessons, and
  recital-fee debits, priced from the `semesters` row.
- **Online payment** — Stripe (Checkout for registration; Elements on the
  parent Balance & Payments page). Recorded by `Billing::recordStripePayment`
  or `recordStripeIntentPayment`.
- **Manual payment** — an admin accepting a check or cash
  (`Billing::recordManualPayment`).
- **Scholarship** — an admin-specified credit
  (`Billing::applyScholarship`).
- **Custom entry** — an admin-specified debit or credit for anything else
  (`Billing::addCustomEntry`).
- **CSV import** — opening balances when migrating an existing roster into the
  portal (semester wizard step 7); re-uploading the same file is a no-op.

## `stripe_webhook_events`

A dedup ledger for incoming Stripe webhook deliveries: `stripe_event_id` is
unique, so an event id is processed at most once and webhook retries (and the
success-redirect fallback) are harmless. Also stores `event_type`, the raw
`payload_json`, and `processed_at` for debugging.
