---
layout: ../../layouts/DocsLayout.astro
title: Registration Form
description: The public registration wizard — quote, policies, payment plan, and Stripe checkout.
---

# Registration Form

The public registration wizard at `/register/` serves families who are ready
to enroll — new and returning alike. It is only open when the
`registration_semester_id` setting names a semester; otherwise visitors are
told registration is closed.

## The steps

1. **Family** (`family.php`) — parent/guardian contact info and address (the
   wizard enforces its own address rules), preferred contact method, SMS
   consent.
2. **Students** (`students.php`) — one block per student, repeatable for
   siblings: name, graduation year (`class_of`), instrument (the form's
   choices include a combined "Cello/Bass" that an admin resolves to a real
   instrument at convert time), lesson length (30 or 60 minutes), optional
   Guitar Ensemble, shirt size. Plus scheduling preferences: preferred
   location, preferred days, availability blocks (9–11, 11–1, 1–3, 3–5, 5–7),
   and free-text scheduling notes ("siblings need back-to-back").
3. **Policies** (`policies.php`) — the conservatory's policies, agreed to with
   a timestamp (`policies_agreed_at`).
4. **Payment plan** (`payment_plan.php`) — pay in full, or in two installments
   for a one-time `installment_plan_fee` (priced on the semester), with the
   remaining tuition due by the semester's **second installment due date**.
   The page also carries the standing disclosure that appears wherever fees
   are shown: the installment fee is charged automatically if the full
   balance isn't paid by the end of the semester's first day.
5. **Review** (`review.php`) — the itemized quote, computed from the
   semester's fee columns (registration fee, lesson fees by length, guitar
   ensemble fee, recital fee, installment fee). The quote lines are stored on
   the lead as `quote_json` along with `amount_quoted_cents` and
   `amount_due_now_cents`.
6. **Checkout** (`checkout.php` / `submit_eval.php`) — Stripe payment. The
   final submit is protected by invisible reCAPTCHA (Enterprise,
   score-based). The browser returns via `return.php`; the Stripe
   webhook records the payment independently, and unique keys make sure only
   one of the two records it.
7. **Done** (`done.php`).

The wizard **saves as the family goes**, the same drop-off capture the
inquiry flow pioneered — starting the moment a plausible **email or phone
number is typed** on the family page: a background save writes a draft row to
`incomplete_inquiries` (source `registration`, stage "Email or phone only") before
Continue is ever pressed. Completing the family step fills the draft in, and
each later step bumps its progress marker — so a family who starts
registering and stops appears in Admin › Leads › Uncompleted Forms, showing
exactly where they stopped ("Email only", "Policies agreed", "Payment plan
chosen"…), and a phone call can finish the job. The final submit deletes the
draft, carrying any staff notes onto the lead.

Submitting creates a lead with `source='registration'` and one
`lead_students` row per student. **Any payment is held on the lead** —
`amount_paid_cents`, `stripe_checkout_session_id`, `paid_at` — until an admin
converts the lead, at which point the payment moves to a chosen student's
ledger.

## Design intent: two paths from one form

The registration spec (docs/registration_flow.md) frames this as one form
with two exits: **"Register & Pay Now"** (the gold button, straight to
Stripe) and **"Register — I'd Like to Talk First"** (the outlined button,
into the admin follow-up queue). Both create identical records; only the
status differs. Either way, a human reviews every submission before it
becomes part of the roster — payment just changes how the follow-up
conversation starts.

## Relationship to the printed spec

The shipped wizard diverges from the original one-page-form spec in mechanics
(it is multi-step, and it writes to the lead staging tables rather than
creating live family records), but keeps its principles: one flow for all
family types, scheduling preferences captured up front, and the pay button at
the end of the flow, not the beginning.
