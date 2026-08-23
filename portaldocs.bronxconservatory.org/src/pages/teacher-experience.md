---
layout: ../layouts/DocsLayout.astro
title: Teacher Experience
description: One main view — the teaching day — plus Teaching Days and the calendar.
---

# Teacher Experience

Teachers check the portal between students, so their experience is **one main
view**: the teaching day. The teacher menu is
`[Teaching Days] [Calendar] [Profile photo]`.

## The day view

One card per lesson, in chronological order: time, student name, class name,
room/location (online lessons get an icon). Two design decisions shape it:

- **Navigation is by teaching day, not by date.** Lessons are sparse — most
  dates have nothing on them — so there is deliberately no date picker. When
  there are no lessons today, the page shows the next day that has any, under
  "Upcoming Lessons." Arrows move to the previous/next teaching day, and the
  Teaching Days view is the list.
- **Attendance is easy to mark and easy to take back.** Each card has
  attended/missed marking, and an "Unmark" action with a confirmation —
  because a mis-tap between two lessons should not be permanent.
  (`lessons.attended`: NULL = unmarked, 1 = attended, 0 = missed.)

Each card also carries the lesson's **notes and materials inline**, so the
teacher never has to leave the day to write a note or attach something.
"Add a resource" attaches a file (stored in `private_files`) or a link, each
with a title, in a modal.

## Teaching Days

Just the dates the teacher works, with where they are and how many lessons —
the view that answers "am I at Bronx CC on Saturday?" Each day opens the
hour-by-hour day view.

## Calendar

- **Semester view** — the whole semester as one chronological list: every
  lesson with its date, time, and location, plus the holiday weeks when a
  location where the teacher works is closed.
- **Weekly view** — a plain chronological list of that week's lessons, each
  clickable.

## What teachers see of the schedule

A teacher's standing weekly slots are their reservations; their non-teaching
time (lunch, errands) appears as [hold blocks](/data-model/reservations-lessons-hold-blocks)
with a title. When an admin assigns them as a **substitute** for another
teacher's lesson on a particular date, that lesson shows up in their day.
