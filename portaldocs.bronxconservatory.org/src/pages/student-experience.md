---
layout: ../layouts/DocsLayout.astro
title: Student Experience
description: The three-card student homepage — schedule, recent lessons, and materials.
---

# Student Experience

Students — mostly children checking a phone — get **three cards**. The student
menu is `[Calendar] [Materials] [Profile photo]`.

Note that many students cannot sign in at all: a child with no email has no
login, and their parents see everything on their behalf. The student
experience exists for students old enough to use it — including adult
students, who are simply users with a student profile of their own.

## The homepage

1. **Notes from Your Last Class** — the first thing under the greeting: the
   full note thread from the student's most recent class (today's included),
   with light-green **Notes & Materials** and **"See notes from all
   classes."** buttons. The latter opens a page listing every past class,
   newest first, each with its complete note thread — notes are the heart of
   the student experience, so they lead the page.
2. **Next Lesson / Upcoming Lessons** — when and where, *including breaks*:
   inactive `semester_location_dates` on the student's weekday surface as
   "Holiday Week" instead of a silent gap.
3. **My Materials** — every resource attached to this semester's lessons, in
   chronological order of the lesson it came from — the semester's
   accumulated sheet music, recordings, and links in one place.

Notes-related buttons are light green throughout, so "where the notes live"
reads at a glance on every card.

Every lesson, past or upcoming, opens its notes and materials in a modal —
and the student can add a note of their own to the thread, on the same terms
as their teacher and parents (see
[Lesson Notes & Resources](/data-model/lesson-notes-resources)).
