---
layout: ../../layouts/DocsLayout.astro
title: View the Calendar & a Week
description: The semester's date list and the weekly grid of real lessons.
---

# View the Calendar & a Week

**Calendar** in the top bar has two views: **Semester** and **Week**. Where
the [Semester Schedule](/admin-experience/view-semester-schedule) shows the
abstract weekly pattern, these show real dates and real lessons.

## The semester view

`/admin/calendar.php` is deliberately **not a month grid** — a month grid
wastes most of its space on a program that meets one day a week. Instead it
lists every class date in the semester chronologically: class days in green,
breaks and holidays in purple with their title ("Holiday Week" — the class
dates CSV's Notes column), each with its hours and locations. Locations that
share a date and title are listed together; a date appears twice when its
locations disagree. Clicking any date opens that week.

## The weekly view

`/admin/calendar_week.php?date=…` is the schedule-grid shape for one real
week (Sunday–Saturday, with Previous/Next), showing actual `lessons` and
hold-block occurrences instead of the standing pattern. Like the schedule it
draws one grid per class day — but headed by the real date ("Tuesday, Sep 8")
and ordered chronologically within the week, each with only that day's
teachers. The differences from the abstract grid all follow from "real":

- **Cancelled lessons don't appear** — their slot is genuinely free here.
  (The family and teacher still see them, marked cancelled.)
- Cells are placed by each lesson's **effective** teacher and location —
  substitutes and one-week room changes included. A substitute who has no
  regular column this semester gets a temporary one, so nothing ever
  vanishes off the edge of the grid.
- Status notes ride on each cell: **Missed** / **Attended**, **Time moved**
  (from what time), **Substitute teacher: …**.
- The color code is simpler than the schedule grid's — no balance colors:
  scheduled = pastel blue, substitute = pastel orange, missed = white
  italic, hold block = grey.
- An empty week still draws the grid — that's precisely when you want to
  click a slot and put something in it.

## Clicking a lesson

The lesson modal collects the per-occurrence operations, each covered in its
own page:

- **Length** — this week only
  ([Change a Lesson's Duration](/admin-experience/change-lesson-duration))
- **Attendance** — not marked / attended / missed
- **Substitute teacher**
  ([Assign a Substitute](/admin-experience/assign-a-substitute))
- **Location** — this week only
- **Cancel lesson** — its own red button, since it can't be undone
  ([Cancellations & One-Off Changes](/admin-experience/cancellations-and-one-offs))
- A link to the student's page — notes, parents, and charges live there.

Saving applies the changes in sequence and stops at the first refusal
(length and substitute go first, since they're the two that can be refused
by a conflict).

## Clicking an empty cell, and dragging

An empty cell adds a **one-off lesson or hold block** on that date only (see
[Cancellations & One-Off Changes](/admin-experience/cancellations-and-one-offs)).
Press **Edit** to drag a lesson to another time — or onto another teacher's
column, which makes that teacher the substitute for the week (see
[Move a Lesson's Time](/admin-experience/move-a-lesson)). Grey hold-block
occurrences can be clicked to retime, retitle, or remove just that week.
