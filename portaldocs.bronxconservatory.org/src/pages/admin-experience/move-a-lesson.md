---
layout: ../../layouts/DocsLayout.astro
title: Move a Lesson's Time
description: Changing a standing weekly slot vs. rescheduling a single occurrence.
---

# Move a Lesson's Time

There are two different moves, done on two different screens:

| You want to change | Where | What moves |
| --- | --- | --- |
| The **standing weekly slot** for the rest of the semester | Schedule grid, Edit mode, drag the reservation | The reservation + all its future lessons |
| **One week only** | Weekly calendar, Edit mode, drag the lesson | That single occurrence |

## Moving the standing slot (the reservation)

On `/admin/schedule.php`, press **Edit** and drag the reservation's cell onto
an empty slot — a different time, a different teacher's column, even a
different location's column. When the cell holds a single booking, anywhere
on the cell starts the drag. The drop can't land on an occupied cell, and a
client-side check refuses drops that would run past the end of the day or
into the next booking. **Standing hold blocks drag the same way** (they post
to their own endpoint but follow the same conflict rules); one-off hold
blocks on the weekly calendar do not.

**⌘Z (Ctrl+Z)** undoes the last move — up to 20 per editing session. The
undo re-posts the item to where it came from and is validated like any
other move, so a since-occupied origin refuses the undo with the reason.

The server (`ReservationManagement::updateReservation`) then:

1. **Validates the destination** — the teacher must actually be assigned to
   that location this semester, and the slot is swept for conflicts *before
   anything is written*: against other reservations and hold blocks
   (teacher-wide, across all locations — a teacher can't be in two places),
   and against every future occurrence date, including one-off lessons and
   hold blocks. The first conflict aborts the whole move with a specific
   message ("This teacher already has Ana's weekly slot at 3:00–3:30 pm…").
2. **Updates the reservation row** (teacher, location, day, time).
3. **Moves the future lessons**, if the reservation is confirmed:
   - **Same day of week** — each future lesson still sitting at the old
     standing time is *updated in place* to the new time, so its notes,
     resources, attendance marks, substitute, and location override all
     survive.
   - **Different day of week** — the future lessons are regenerated on the
     new weekday's active dates. **Per-occurrence data on the dropped dates
     is lost** (notes, attendance, substitutes), so prefer same-day moves
     when a week has history on it.

Two things are always left alone: **past lessons** stay exactly where they
were taught, and **weeks that were individually hand-moved** (their time no
longer matches the standing time) keep their custom time — the move only
carries the weeks still following the schedule.

Pending reservations move the same way but have no lessons yet, so only the
reservation row changes.

## Rescheduling a single week

On `/admin/calendar_week.php`, press **Edit** and drag the lesson to an empty
cell. One drag can change the time, the date (within the displayed week), the
teacher, and the location at once:

- **Dropped on a different teacher's column** — that teacher becomes the
  **substitute for that week only**; the reservation is untouched. Dropping
  it back on its own teacher's column clears the substitute.
- **Dropped on a different location's column** — recorded as a one-week
  location override.
- The destination is conflict-checked against whoever ends up teaching;
  cancelled lessons don't block a drop (a cancelled slot is free).

A cancelled lesson itself cannot be moved. After a hand-move, the lesson
shows a "Time moved from 3:00 pm" note in the calendar — and, as above, it is
thereafter excluded from standing-slot moves.

The lesson modal deliberately has no time field: moving is always done by
dragging, so there is exactly one way to do it and it is always
conflict-checked.
