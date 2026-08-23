---
layout: ../../layouts/DocsLayout.astro
title: Leads & Intake
description: The staging tables behind the public registration and inquiry forms.
---

# Leads & Intake

**Tables:** `leads`, `lead_students`, `lead_notes`, `incomplete_inquiries`,
`incomplete_inquiry_notes`

The two public forms — the [Registration Form](/registration/registration-form)
and the [Inquiry Form](/registration/inquiry-form) — write **only** to these
tables. They are deliberately *not* live data: no `users`, no profiles, no
reservations exist until an admin converts the lead in Admin &gt; Leads.

## `leads`

One submission from one of the two public forms, discriminated by
**`source`** ENUM(`registration`,`inquiry`):

- **registration** — the wizard that quotes a price and may take payment.
  `semester_id` records which semester registration was open for at submit
  time.
- **inquiry** — the "Request Information" form, which asks about interest and
  never quotes. `semester_id` is always `NULL`; instead `semester_label`
  holds a free-text option from the `inquiry_semester_options` setting (not a
  `semesters` row).

**`status`** ENUM(`new`,`contacted`,`scheduled`,`converted`,`declined`) is the
admin's working state for the lead.

The rest of the columns fall into groups:

- **Parent contact:** `parent_first_name`/`parent_last_name`, `email`,
  `phone`, `sms_consent`, `newsletter_opt_in`, and a nullable address block
  (nullable because an inquiry can be abandoned mid-flow or come from outside
  the US; the registration wizard enforces its own address rules).
- **Scheduling preferences (registration):** `location_preference`,
  `preferred_days` (JSON array of day names), `availability_blocks` (JSON
  array of `9-11`, `11-1`, `1-3`, `3-5`, `5-7`), `scheduling_notes`.
- **Inquiry-only:** `owned_instruments` (JSON) + `owned_instruments_other`,
  `music_background`, `theory_program_interest` ENUM(`yes`,`no`,`need_info`),
  `theory_knowledge` (same vocabulary as `student_profiles.experience_level`),
  `inquiry_comments`.
- **Both:** `referral_source` (validated in PHP so options can change without
  DDL), `policies_agreed_at`, `installment_plan`.
- **Money:** `quote_json` (the itemized quote as computed at submit),
  `amount_quoted_cents`, `amount_due_now_cents`, `amount_paid_cents`,
  `stripe_checkout_session_id` (unique), `stripe_payment_intent_id`,
  `paid_at`. A payment made during registration is **held on the lead** until
  conversion moves it to a student's ledger.
- **Conversion tracking:** `converted_parent_user_id`, `converted_at`.

## `lead_students`

The students named on a lead. The two sources fill different columns:

| | Registration lead | Inquiry lead |
| --- | --- | --- |
| Filled | `instrument` (as entered, incl. "Cello/Bass"), `lesson_length_minutes` (30 or 60), `class_of`, `guitar_ensemble`, `shirt_size` | `age`, `enrollment_status` ENUM(`new`,`continuing`), `instruments_of_interest` (JSON) + `instruments_other` |
| Left NULL | inquiry columns | `instrument`, `lesson_length_minutes` — nothing has been decided yet |

The mapping to a real `instruments` row is picked by the admin at convert
time. **`converted_student_user_id`** makes conversion idempotent:
already-converted rows are skipped on re-entry, so a conversion that failed
half-way can simply be run again.

## `lead_notes`

An admin's internal notes on a lead — **append-only**, so who said what and
when is never lost and two admins working the same lead cannot clobber each
other. `body` may be empty when the entry only records a status change;
`status_after` records a status change made in the same save, keeping the
history and the lead in agreement. `created_by_user_id` is `NULL` for notes
migrated from the old single `leads.admin_notes` column.

## `incomplete_inquiries`

Drop-off capture for **both** public flows, discriminated by `source`
ENUM(`inquiry`,`registration`):

- **Inquiry:** page 1 (contact info) writes a row so staff can reach out to a
  visitor who never finishes; page 2 (address) updates it
  (`last_step_completed`: 1 = contact only, 2 = address too). Page 3 (student
  info) **promotes the row into a real lead and deletes it**.
- **Registration:** the wizard's family step writes a row (contact + address
  at once, so it starts at step 2), and later steps bump the marker
  (3 = students, 4 = policies, 5 = payment plan) so staff can see exactly
  where the family stopped. Creating the registration lead at final submit
  deletes it, carrying any staff notes onto the lead.

So a row here always means "this family started a form and never finished."
They appear in Admin &gt; Leads &gt; Uncompleted Forms as a peer to leads
(with a pill saying which form), but are never converted directly.

## `incomplete_inquiry_notes`

Internal notes on an uncompleted form, on the same append-only terms as
`lead_notes`. When the form is finished and becomes a lead, these are carried
across as a single note on that lead, so the record of the chase survives the
conversion.
