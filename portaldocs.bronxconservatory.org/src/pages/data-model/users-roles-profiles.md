---
layout: ../../layouts/DocsLayout.astro
title: Users, Roles & Profiles
description: The users table, role-defining profile tables, parenthood links, and instrument reference data.
---

# Users, Roles & Profiles

**Tables:** `users`, `student_profiles`, `teacher_profiles`, `parenthood`,
`instruments`, `student_instruments`, `teacher_instruments`

## `users`

One row per **person** — parents, students, teachers, and admins alike. There
is no role column; roles are derived from related rows (see the
[User Model](/user-model) page for the full resolution logic):

- **admin** — the `is_admin` flag
- **developer** — the `is_developer` flag; combined with `is_admin` it unlocks
  the Admin &gt; Maintenance section (migrations, activity log, email log).
  Only a developer can grant or revoke it on others (a non-developer admin
  sees the flag read-only), nobody can change their own, and both flags can
  be set on a person who has an email but hasn't activated their account yet
  — the access simply takes effect once they do
- **teacher** — existence of a `teacher_profiles` row
- **parent** — existence of a `parenthood` row as `parent_user_id`
- **student** — existence of a `student_profiles` row

Key columns:

- **Name:** `first_name`, `last_name`, `suffix`, `preferred_name`.
- **`email`** — the login identifier, deliberately *nullable-unique*: child
  students often have no email and cannot sign in, but any email that exists
  must be unique.
- **`password_hash`** — defaults to `''`, which means "cannot sign in yet". A
  password arrives via an invite or the forgot-password flow
  (`email_verify_token`, `password_reset_token_hash`,
  `password_reset_expires_at` support those flows).
- **Contact & address:** `secondary_email`, `cell_phone`, `home_phone`,
  `preferred_contact_method` ENUM(`email`,`phone`,`text`), and a five-part
  address block.
- **Emergency & personal:** `emergency_contact_name`/`_phone`,
  `medical_notes`, `shirt_size`.
- **`photo_public_file_id`** → `public_files` (`ON DELETE SET NULL`) — the
  profile photo.
- **`is_deleted`** — soft delete. The row and its history stay, but the user
  can no longer sign in and is excluded from lists and role resolution.

## `student_profiles`

Role-defining: a row here makes its user a student. The primary key **is**
`user_id` (FK → `users`, `ON DELETE CASCADE`) — one profile per user, no
separate id. An adult who takes lessons themselves gets a `student_profiles`
row on their own `users` row; there is no separate "adult student" concept.

Columns: `date_of_birth`, `class_of` (a `YEAR` — expected graduation),
`experience_level` ENUM(`none`,`beginner`,`intermediate`,`advanced`),
`school_name`, `grade`, `demographic`.

`demographic` is ENUM(`B`,`L`,`W`,`AAPI`,`O`), NULL when not recorded, and is
**admin-only**: it is read and written solely through
`StudentTeacherManagement::demographicForStudent` / `setStudentDemographic`,
which both require an admin `UserContext`, and it is deliberately left out of
`childrenOfParent`, `listStudentsFiltered`, and every other query behind a
family- or teacher-facing screen. Unlike the other columns here it is not
part of `ensureStudentProfile`, whose `COALESCE` upsert could never clear a
value back to "not recorded".

## `teacher_profiles`

Role-defining in the same way, keyed by `user_id`. Columns: `bio` and `gender`
ENUM(`female`,`male`,`nonbinary`) — the gender column exists specifically to
honor families' teacher-gender preferences from the registration form.

## `parenthood`

The parent↔child edge, and what defines the "parent" role. `parent_user_id`
and `child_user_id` both reference `users` (`ON DELETE CASCADE`) with a unique
key on the pair, plus an optional `role` ENUM(`mother`,`father`,`guardian`).
A family is expressed entirely through these edges — there is no separate
`families` table — so shared custody, multiple guardians, and a child with
parents in two households all fall out naturally.

## `instruments`

A fixed reference list, seeded with BCM's seven instruments: Piano, Guitar,
Voice, Violin, Viola, Cello, and Double Bass. `name` is unique and
`sort_order` fixes display order. It is a table rather than an ENUM so
dropdowns and per-student multi-selects come for free.

## `student_instruments` and `teacher_instruments`

Straightforward many-to-many join tables: which instruments a student studies,
and which instruments a teacher teaches. Each has a unique key on the
(user, instrument) pair and cascades on delete from both sides.
