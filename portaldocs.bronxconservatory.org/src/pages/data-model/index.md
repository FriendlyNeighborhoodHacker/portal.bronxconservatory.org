---
layout: ../../layouts/DocsLayout.astro
title: Data Model
description: The portal's MySQL schema, explained table by table for readers who know SQL.
---

# Data Model

The portal's schema lives in a single file, `schema.sql`, at the top of the
repository. That file always represents the **complete current schema**;
migrations in `db_migrations/` exist only to upgrade older production
installations and are tracked in the `schema_migrations` table. The database is
MySQL (InnoDB, utf8mb4), and the schema is heavily commented — the comments in
`schema.sql` are the authoritative source for anything this documentation
summarizes.

There are 33 tables, documented here in eight categories:

| Category | Tables |
| --- | --- |
| [Users, Roles & Profiles](/data-model/users-roles-profiles) | `users`, `student_profiles`, `teacher_profiles`, `parenthood`, `instruments`, `student_instruments`, `teacher_instruments` |
| [Semesters & Locations](/data-model/semesters-locations) | `semesters`, `locations`, `semester_locations`, `semester_location_dates`, `semester_location_teachers` |
| [Reservations, Lessons & Hold Blocks](/data-model/reservations-lessons-hold-blocks) | `semester_lesson_reservations`, `lessons`, `semester_hold_block_reservations`, `semester_hold_blocks` |
| [Lesson Notes & Resources](/data-model/lesson-notes-resources) | `lesson_notes`, `lesson_resources` |
| [Billing & Payments](/data-model/billing-payments) | `ledger_entries`, `stripe_webhook_events` |
| [Leads & Intake](/data-model/leads-intake) | `leads`, `lead_students`, `lead_notes`, `incomplete_inquiries`, `incomplete_inquiry_notes` |
| [Email & Announcements](/data-model/email-announcements) | `emails_sent`, `email_templates`, `announcements` |
| [Files & Infrastructure](/data-model/files-infrastructure) | `public_files`, `private_files`, `settings`, `schema_migrations`, `activity_log` |

## Cross-cutting conventions

A handful of patterns repeat throughout the schema and are worth internalizing
before reading the per-category pages.

### Soft delete everywhere

History is never destroyed. Deleting a person sets `users.is_deleted = 1`;
deleting a reservation or hold block sets its `status` to `'deleted'`. The rows
and everything that hangs off them stay in place — a deleted user simply cannot
sign in and disappears from lists and role resolution, and a deleted
reservation keeps its **past** lessons while its future ones are removed.

### Reservations generate occurrences

The central mechanic of the schedule: a *reservation* row describes a weekly
recurring slot, and confirming (or, for hold blocks, creating) it generates one
*occurrence* row per real calendar date, driven by the location's active-date
calendar. The occurrence tables (`lessons`, `semester_hold_blocks`) store only
per-occurrence facts and overrides; teacher, student, and location normally come
from the reservation.

Both occurrence tables also support **one-offs** booked straight onto the
calendar: the reservation FK is `NULL`, the identifying columns
(`semester_id`, `teacher_user_id`, `student_user_id`, `location_id`) are set
directly on the row, and every read `COALESCE`s the reservation's value over
the row's own.

### Money is integer cents, tied to a semester

`ledger_entries.amount_cents` is an unsigned integer — exact math, and the same
unit Stripe uses. Nearly every entry carries a `semester_id`, because the
parent portal groups balances by term and judges "is this family on schedule?"
per term. Charge-posting and payment-recording are idempotent (unique keys on
Stripe identifiers; per student + semester + entry-type checks for charges).

### Leads are a staging area

The public forms write to `leads`/`lead_students` and touch nothing else. No
`users` rows, no profiles, no reservations exist until an admin explicitly
converts the lead. Conversion is itself idempotent and re-enterable.

### Foreign-key delete behavior is consistent

`ON DELETE CASCADE` for ownership relationships (a lesson belongs to its
reservation; a profile belongs to its user), and `ON DELETE SET NULL` for
attribution columns (`created_by_user_id` and friends) — losing the author of a
record should never delete the record.
