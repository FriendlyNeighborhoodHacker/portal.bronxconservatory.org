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
   location (either site, or Online Lessons), preferred days, availability blocks (9–11, 11–1, 1–3, 3–5, 5–7),
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

### Moving between steps

The numbered step bar links every step the family has already been through
(and the one they are up to), so they can jump back to fix an answer and
forward again. Checkout has a **Back** button to the review. The lead is
created when the review is submitted, so a family who goes back from checkout
and changes something **rewrites that same lead** on the next submit
(`LeadManagement::createLead` with `$replaceLeadId`): the quote and the amount
to pay are recomputed, a fresh Stripe payment is started, and the confirmation
email is sent again if it changed. A paid or converted lead can no longer be
changed this way. The wizard's answers are cleared on the thank-you page, so
the next registration started in the same browser is a separate lead.

### The registration fee is one per family per semester

A family registering a second child is not charged the fee again. After the
family step, the wizard checks the email
(`LeadManagement::registrationFeeAlreadyCovered`): the fee is waived when that
email's account — the parent, or any of their children — has already been
charged this semester's registration fee, or when an earlier registration from
the same email for the same semester has been paid. The students and review
pages say so, and the quote keeps the line at $0 ("waived"). A new semester is
a new fee. The charges posted at confirmation follow the same once-per-family
rule, so the two always agree.

### Who pays which tuition

One flow serves Bronx residents, families outside the Bronx, and online
students. The family step's city and ZIP code (ZIP is required and must be a
real five-digit one) decide residency: the family is a **Bronx resident** when
the city is "Bronx" (any case) or the ZIP's first five digits are
10451–10475 (`LeadManagement::isBronxResident`). Residents studying in person
are quoted the semester's standard fees; everyone else — and anyone who picks
**Online Lessons** as their location preference — is quoted the semester's
"non-residents and/or online students" fees
(`LeadManagement::usesNonresidentPricing`). The family page shows both rates,
later pages show the one that applies, and the lead records it as
`leads.nonresident_pricing`. A semester with no non-resident fees charges
everyone the standard rate. Choosing Online Lessons hides the preferred-day and
time questions (nothing is stored for them).

The charges posted when a reservation is confirmed follow the same rule, read
from the accounts at that moment (`Billing::lessonRateFor`): an online
location is the online rate; otherwise the student is a Bronx resident when
their own account or any parent's has a Bronx city or ZIP. No address on file
at all is treated as resident.

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
