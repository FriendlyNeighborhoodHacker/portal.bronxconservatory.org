---
layout: ../../layouts/DocsLayout.astro
title: Process Leads & Uncompleted Forms
description: Working the queue of public form submissions — statuses, notes, and drop-off follow-up.
---

# Process Leads & Uncompleted Forms

Everything the public forms produce lands in two admin queues, reached from
the **Leads** item in the top bar: the leads list (`/admin/leads.php`) and its
sibling tab, Uncompleted Forms.

## The leads list

The list is organized as **views**, not raw status filters:

- **Active** (the default) — leads with status `new`, `contacted`, or
  `scheduled`: the ones that still need work.
- **All**, plus one tab per status: New / Contacted / Scheduled / Converted /
  Declined. Each tab shows its count, computed per source filter so the tabs
  stay honest.
- A **source** filter narrows to registration or inquiry leads.

Each row shows the family (linked to the detail page), contact info, a
one-line student summary ("2 students — Lucia (Piano, 30 min), Marco (Violin,
60 min + Ensemble)"), a source pill, the semester, the quoted amount and
payment state (registration leads only — inquiries show a dash), the status,
and when it was submitted. Rows are newest-first, paged at 25/50/100.

## The lead detail page

`/admin/lead.php?id=N` shows everything the family submitted, shaped by the
source:

- **Registration leads** show scheduling preferences (location, preferred
  days, availability blocks, notes), the policies-agreed timestamp, and a
  payment card: quoted total, installment vs. full, amount due now, amount
  paid with date and Stripe reference, and the itemized quote lines.
- **Inquiry leads** show the inquiry details instead — term label, owned
  instruments, theory interest and knowledge, prior study, referral source,
  comments — and no payment card (inquiries never quote).

The students table adapts the same way: instrument/lesson-length/ensemble for
registration students, age/enrollment-status/interests for inquiry students,
plus a Converted column once conversion has happened.

## Statuses and notes move together

The **Add a note** form at the bottom is the only way status changes in the
UI, and it is append-only by design: each save inserts a `lead_notes` row
(with `status_after` recording any status change made in the same save) and
updates the lead's status in the same transaction — the history can never
disagree with the lead, and two admins working the same lead cannot clobber
each other.

The one validation: you must **write a note or change the status** — an empty
save is rejected. An empty note *with* a status change is fine; it renders in
the history as "Marked **contacted**." Notes migrated from the old
single-notes column show as authored by "Imported."

## Converted leads and the account invite

Once a lead is converted (see [Convert a Lead](/admin-experience/convert-a-lead)),
the detail page shows a banner linking to the created parent and students —
and, when the parent has an email but no password yet, a **Send Account
Invite** button.

This button is the family's only path to a login, and it is a **manual
step — conversion does not send it automatically**:

1. The invite generates a fresh verification token and emails a link
   ("Verify your account…"). Sending it again regenerates the token, which
   silently invalidates the earlier link.
2. The family clicks through `/verify_email.php` → `/set_password.php`, sets
   a password (min 8 characters), and is logged straight in.

The button only renders while `password_hash` is empty. If conversion adopted
an **existing** active account, no invite is possible or needed — the family
signs in with the password they already have.

## Uncompleted Forms

The sibling queue lists `incomplete_inquiries` rows — families who started
the public `/inquiry/` flow, gave contact info, and never named a student.
Each row shows how far they got ("Contact only" vs. "Contact and address")
and when they started. The queue is purely reverse-chronological; there are
no filters.

On the detail page (`/admin/incomplete_inquiry.php?id=N`) the admin —
typically on the phone with the family — can:

- **Save Changes** — corrections to contact and address persist to the draft
  (the stage marker only ever moves forward). Address validation is
  all-or-nothing: an empty address is fine, a half-filled one is rejected.
  Student and "anything else" fields have nowhere to live on the draft — they
  are held in the session until the form is completed, so they survive a
  rejected save but not a lost session.
- **Add notes** — append-only, same terms as lead notes.
- **Complete and Create Lead** — validates everything (student name, age,
  enrollment status, and at least one instrument of interest are required),
  then promotes the draft through the same code path as the public flow's
  final page: creates the lead (`source='inquiry'`, status `new`) and its
  student in one transaction, rolls the draft's notes into a single note on
  the lead ("Notes from the uncompleted form: …"), and **deletes the draft** —
  a crash mid-promotion leaves either a draft or a lead, never both.
  The admin lands on the new lead's detail page.
- **Delete** — permanent (notes cascade away), behind a confirmation.

One difference from the public path worth knowing: when an *admin* completes
a form, **no emails are sent** — the family confirmation and staff
notification only fire when the family finishes the form themselves.
