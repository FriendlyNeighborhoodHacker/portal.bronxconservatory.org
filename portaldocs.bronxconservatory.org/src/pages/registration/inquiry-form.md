---
layout: ../../layouts/DocsLayout.astro
title: Inquiry Form
description: The public Request Information flow, its drop-off capture, and the emails it sends.
---

# Inquiry Form

The public **Request Information** flow at `/inquiry/` is for families who
want to talk before committing. It asks about interest and never quotes a
price.

## The steps

A short multi-page flow, each page saving as it goes:

1. **Contact** (`contact.php`) — parent name, email, phone, newsletter opt-in,
   SMS consent. The submit is protected by invisible reCAPTCHA (Enterprise,
   score-based; skipped entirely when no keys are configured). Passing it
   writes a row to `incomplete_inquiries`.
2. **Address** (`address.php`) — mailing address (nullable by design; the form
   tolerates non-US addresses). Updates the same row and bumps
   `last_step_completed` to 2.
3. **Student** (`student.php`) — the student's name, age, and whether they are
   a new or continuing student. Completing this step **promotes** the
   uncompleted row into a real lead (`leads` with `source='inquiry'`, plus a
   `lead_students` row) and deletes the `incomplete_inquiries` row.
4. **Details** (`details.php`) — which term they're interested in (a free-text
   label from the `inquiry_semester_options` setting), instruments of
   interest, instruments the family owns, prior musical background, interest
   in the free theory program and current theory knowledge, how they heard
   about BCM, and any questions or comments.
5. **Done** (`done.php`) — confirmation.

## Drop-off capture

The two-table design exists so that a family who starts the form and walks
away is not lost. A row in `incomplete_inquiries` always means "this family
gave us contact info but never told us about a student." Staff see these in
**Admin &gt; Leads &gt; Uncompleted Forms**, can add append-only notes while
chasing them, and when the family eventually finishes the form, those notes
are carried onto the resulting lead as a single note — the record of the chase
survives the promotion.

## Emails

Finishing the form sends two emails, both rendered from admin-editable
[email templates](/data-model/email-announcements):

- **`inquiry_family_confirmation`** — to the family, echoing back what they
  told us and giving the office phone number.
- **`inquiry_staff_notification`** — to the address in the
  `inquiry_notification_email` setting, with the full submission and a direct
  link to open the lead in the portal.

## What happens next

The lead sits in Admin &gt; Leads with status `new`. An admin contacts the
family, records notes, and — once instrument, length, and schedule are agreed
— converts the lead into a real family account. Because an inquiry decides
nothing up front, the admin picks the actual instrument and lesson length at
convert time.
