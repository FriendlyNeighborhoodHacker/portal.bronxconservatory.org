---
layout: ../../layouts/DocsLayout.astro
title: Convert a Lead to a Family
description: Turning a staged submission into live people, reservations, and a recorded payment.
---

# Convert a Lead to a Family

Conversion is the bridge from the [staging tables](/data-model/leads-intake)
to live data. It is reached from the **Convert to Family…** button on the
lead detail page (`/admin/lead_convert.php?id=N`), which disappears once the
lead is converted.

## What the admin picks

The form opens with a **preview table** — a dry-run summary of what will
happen: whether the parent will be *created* ("no login until invited") or
will *adopt an existing account* matched by email ("phone/address are filled
in, nothing is overwritten with blanks"), what each student gets, and where a
held payment will land.

Then, per lead student (already-converted students render as "Already
converted — nothing more to choose here"):

- **Instrument** — a select over the real instrument catalog, preselected
  from the lead. When the family chose the wizard's combined "Cello/Bass"
  option, the label says so and the admin picks which; inquiry students
  default to their first interest that maps to a real instrument (Bass →
  Double Bass, Guitar Ensemble → Guitar), or none.
- **Date of birth** — optional; the label notes the family-supplied "Class
  of" year if there is one.
- **A reservation placement** — a "Choose a time…" button opens the schedule
  picker: the semester's weekly grid with occupied cells unclickable, a
  client-side fit check ("That would run into the next booking…"), and a
  server-side advisory re-check (`schedule_slot_check.php`). Placement is
  optional — "convert now and place them from the Schedule later" is always
  available, and is the only option when the semester has no
  location-teacher columns yet. The duration defaults to the lead's lesson
  length (30 for inquiry students).
- **Payment target** — shown only when the lead holds a payment: which
  student's ledger the payment should land on.

An inquiry lead has no semester of its own, so placement uses the admin's
currently selected semester.

## What the system does, in order

The eval (`lead_convert_eval.php`) runs **conflict checks before anything is
written** — deliberately, because conversion creates people before it books
slots, and a clash discovered mid-way would leave half-made records:

1. **Sibling vs. sibling** — two students in this submission booked into the
   same teacher at overlapping times (each looks free on its own).
2. **Against the schedule** — each pick is checked against existing
   reservations and hold blocks, with a specific message ("This teacher
   already has *Name*'s weekly slot at 10:00–10:30 am…").

Then `LeadManagement::convertLead` runs, step by step:

1. **Parent** — adopt-or-create by email. Adoption restores a soft-deleted
   account if that's what the email matches, and fills in only *empty*
   fields — an existing account's data is never blanked. Creation makes a
   user with no password (`password_hash = ''`) and **sends no email**.
2. **Students** — for each lead student not yet converted: a user with no
   email and no login, a student profile (DOB, class-of), instruments, shirt
   size, and the `parenthood` link. Each student's
   `converted_student_user_id` is written immediately, so progress is durable.
3. **Reservations** — created as **`pending_reach_out`**, with the slot
   re-checked at booking time. No lessons are generated and nothing is
   charged; that happens when the reservation is later
   [confirmed on the schedule](/admin-experience/view-semester-schedule).
4. **Payment** — if the lead holds a Stripe payment and a target student was
   picked, it is recorded as a `payment` credit on that student's ledger.
   A cross-student check plus a unique key on the Stripe reference guarantee
   one payment can never be recorded twice or credited to two students; if
   it is already on a ledger, the admin sees "This payment is already on a
   ledger — not recorded again."
5. **Mark converted** — the lead gets `status='converted'`,
   `converted_parent_user_id`, and `converted_at` (preserved on re-entry).

The success flash summarizes it: "Converted! Created the parent account · 2
students · 1 reservation placed (pending reach out) · payment recorded on
the ledger."

## Re-entry and failure behavior

Conversion deliberately runs **without one big transaction** — each step
commits as it goes — precisely so it is safe to run again:

- Already-converted students are skipped; the parent adoption is an upsert;
  profile writes never overwrite with nulls; instruments and parent links are
  insert-ignore; the payment insert is unique-guarded.
- If a slot conflict slips past the pre-flight (someone books it between
  check and commit), the people are already created but the lead is *not*
  marked converted — so the Convert button stays available and re-running
  continues where it left off.
- **The sharp edge:** if student 1's reservation was created and student 2's
  then failed, resubmitting with student 1's pick still filled will conflict
  with the reservation it just created. Clear student 1's pick before
  resubmitting. (Instrument/DOB choices for already-converted students are
  ignored on re-entry — fix those on the student record instead.)

## After conversion: invite the family

Conversion creates accounts but **no logins and no emails**. Giving the
family portal access is a separate, manual step: the **Send Account Invite**
button on the lead detail page (only rendered while the parent has an email
and no password). Forgetting it leaves a family that exists in the system but
cannot sign in — see
[Process Leads](/admin-experience/process-leads) for how the invite works.
