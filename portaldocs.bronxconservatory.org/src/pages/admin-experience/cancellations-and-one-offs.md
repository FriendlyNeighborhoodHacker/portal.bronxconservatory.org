---
layout: ../../layouts/DocsLayout.astro
title: Cancellations & One-Off Changes
description: Cancelling a lesson, marking attendance, one-week location changes, and ad hoc lessons.
---

# Cancellations & One-Off Changes

The remaining per-occurrence operations, all done from the weekly calendar
(`/admin/calendar_week.php`) — the lesson modal for existing lessons, or a
click on an empty cell to add something.

## Cancel a lesson

The red **Cancel lesson** button in the modal, behind a confirmation:
"It disappears from this calendar and frees the slot. The family and the
teacher still see it, marked cancelled."

That sentence is the whole design:

- The row is never deleted — `cancelled_at` and who cancelled it are stamped
  on it.
- The **slot is freed**: a cancelled lesson blocks nothing, so another lesson
  or a reservation move can take its time.
- It disappears from the admin weekly grid, but **stays visible** on the
  teacher's day and the family's schedule, marked cancelled — so nobody shows
  up to a lesson that isn't happening.
- **There is no un-cancel.** Cancelling is deliberately its own button, not
  part of Save, because it's the one thing on the modal that can't be undone
  from the screen. If a cancelled week is back on, book a
  one-off lesson in its place (below).
- No money moves — but note that a cancelled lesson counts as "used" in
  [duration-change accounting](/admin-experience/change-lesson-duration),
  since the family was still charged for it.

## Mark a lesson missed (or attended)

The **Attendance** select: Not marked / Attended / Missed. Teachers normally
do this from their day view (where "Unmark" has a confirmation, so a mis-tap
between lessons isn't permanent); admins can do it from the calendar modal.
The effective teacher — a substitute included — may also mark it. A missed
lesson gets a "Missed" badge; nothing else changes automatically (no
automatic make-up, no ledger entry).

## Move one week to a different room

The **Location** select in the modal changes only that occurrence — "Changing
this moves only this week, not the standing booking." Choosing the
reservation's own location clears the override, so "this lesson was moved"
always means what it looks like. (The venue-for-the-whole-semester lives on
the reservation; change that on the schedule grid.)

## Book a one-off lesson

Clicking an **empty cell** on the weekly calendar opens the add modal
(Lesson or Hold Block tab). For a lesson: pick a student (typeahead) and a
duration — teacher, location, date, and time come from the cell.

A one-off is a real commitment, so it is conflict-checked against the
teacher's schedule like anything else. But it deliberately differs from a
generated lesson:

- It belongs to **no reservation** (`lesson_number` 0) and **charges
  nothing** — money follows a confirmed reservation, and there is no
  reservation here. Uses for it: a make-up for a cancelled week, a trial
  lesson, a one-time extra.
- It isn't tied to the location's class calendar, so it can be booked on a
  date the location doesn't normally meet.
- Notes, resources, attendance, substitutes, and hand-moves all work on it
  exactly like any other lesson.

## Hold blocks on the calendar

The same add modal's **Hold Block** tab books one-off held time for a teacher
(a meeting, an appointment) on that date only; standing weekly hold blocks
(lunch every Saturday) are managed on the schedule grid. Held time occupies
the slot in every conflict check, which is how it protects a teacher's break
from being booked over.
