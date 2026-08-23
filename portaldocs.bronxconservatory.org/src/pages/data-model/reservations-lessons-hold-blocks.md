---
layout: ../../layouts/DocsLayout.astro
title: Reservations, Lessons & Hold Blocks
description: Weekly recurring slots, the lesson occurrences they generate, and teachers' held time.
---

# Reservations, Lessons & Hold Blocks

**Tables:** `semester_lesson_reservations`, `lessons`,
`semester_hold_block_reservations`, `semester_hold_blocks`

This is the heart of the schedule. The pattern appears twice, in parallel:

| | Recurring definition | Generated occurrences |
| --- | --- | --- |
| Student lessons | `semester_lesson_reservations` | `lessons` |
| Teacher's held time | `semester_hold_block_reservations` | `semester_hold_blocks` |

## `semester_lesson_reservations`

Reserves a weekly slot — teacher + location + `day_of_week` (0=Sunday …
6=Saturday, matching PHP `date("w")`) + `start_time` + `duration_minutes`
(default 30) — for one **student** for a whole semester. FKs to the semester,
teacher, location, student, and creator.

The `status` ENUM is the reservation's lifecycle, and it has side effects:

- **`pending_reach_out`** — the default; the org still needs to call the
  family. Rows carried forward from a previous semester also start here.
- **`pending_confirmation`** — the family has been reached; awaiting a yes.
- **`confirmed`** — the slot is real. Moving to confirmed **generates the
  `lessons` rows** from the location's active dates *and posts the semester's
  charges* (registration, lessons, recital fee) to the student's ledger.
  Moving *backwards* from confirmed deletes only **future** lessons and
  reverses the charges.
- **`deleted`** — soft delete. Future lessons are removed; past lessons are
  kept unchanged, so history survives.

## `lessons`

One row per actual lesson on the calendar, generated from a confirmed
reservation — never created ad hoc, with one exception (one-offs, below).

- **`semester_lesson_reservation_id`** — the owning reservation
  (`ON DELETE CASCADE`). `NULL` marks a **one-off** lesson booked straight
  onto the calendar; only then are `semester_id`, `teacher_user_id`,
  `student_user_id`, and `location_id` set on the lesson itself. Every read
  `COALESCE`s the reservation's values over these columns.
- **`start_datetime`**, **`duration_minutes`** — when, and for how long.
- **`lesson_number`** — the ordinal of the lesson within the semester (1st,
  2nd, …), derived from the location's active-date calendar so it is stable
  across regeneration; `0` for a one-off. Unique on
  (`semester_lesson_reservation_id`, `lesson_number`).
- **Per-occurrence overrides:** `location_id_override` (moved rooms this
  week), `substitute_teacher_user_id` (who actually taught).
- **`attended`** — `NULL` = unmarked, `1` = attended, `0` = missed. Set by the
  teacher from their day view.
- **`cancelled_at`** / `cancelled_by_user_id` — a called-off lesson: hidden
  from the admin calendar, still shown to the family and teacher, and no
  longer holds the slot.

## `semester_hold_block_reservations`

A teacher's **non-lesson** standing time at a location — lunch, an errand, a
recurring break. Structurally the same weekly slot as a lesson reservation
(semester, teacher, location, day, time, duration), but held for the teacher
rather than a student, so it carries a `title` ("Lunch") instead of a student,
and its status ENUM is just `active`/`deleted` — **no confirmation and no
billing**. Its blocks materialize as soon as it is created. Deleting removes
future blocks and keeps past ones as a record of the teacher's day.

Hold blocks matter to scheduling: they occupy grid cells, so a reservation (or
a carried-forward schedule) cannot claim a slot a hold block covers.

## `semester_hold_blocks`

The occurrences, generated from a hold block reservation exactly the way
lessons are generated from a lesson reservation — including the same one-off
pattern (nullable reservation FK with `COALESCE`d identifying columns, plus
`title_override` to carry a one-off's title or let one week differ from the
standing title). Unlike lessons they carry no ordinal — a lunch break has no
"number" — so the unique key is (`semester_hold_block_reservation_id`,
`start_datetime`) instead.
