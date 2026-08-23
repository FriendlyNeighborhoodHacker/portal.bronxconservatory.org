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

1. **My Schedule** — upcoming lessons, *including breaks*: alongside real
   lessons it shows the inactive `semester_location_dates` for the current
   semester that fall on the same weekday as the student's reservation, so a
   student sees "Holiday Week — no lesson" instead of a silent gap.
2. **Recent Lessons** — the last few lessons, which is where their notes and
   materials live.
3. **My Materials** — every resource attached to this semester's lessons, in
   chronological order of the lesson it came from — the semester's
   accumulated sheet music, recordings, and links in one place.

Every lesson, past or upcoming, opens its notes and materials in a modal —
and the student can add a note of their own to the thread, on the same terms
as their teacher and parents (see
[Lesson Notes & Resources](/data-model/lesson-notes-resources)).
