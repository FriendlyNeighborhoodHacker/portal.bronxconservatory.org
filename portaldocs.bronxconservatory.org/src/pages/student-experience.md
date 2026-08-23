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
   classes."** buttons. A white **"See all notes"** button also sits at the
   top right of the page. Both open a page listing every class — upcoming
   lessons included, latest first — each with its complete note thread;
   notes are the heart of the student experience, so they lead the page.
2. **Next Lesson / Upcoming Lessons** — when and where, *including breaks*:
   inactive `semester_location_dates` on the student's weekday surface as
   "Holiday Week" instead of a silent gap. The Next Lesson card carries its
   notes-and-materials thread **inline** — the chat bubbles, the add-a-note
   box, and the materials button sit right on the card, no modal needed.
3. **My Materials** — every resource attached to this semester's lessons, in
   chronological order of the lesson it came from — the semester's
   accumulated sheet music, recordings, and links in one place.

Notes-related buttons are light green throughout, so "where the notes live"
reads at a glance on every card.

Every lesson, past or upcoming, opens its notes and materials in a modal.
Notes render as light-green chat bubbles — the thread reads like a
conversation — and the student can add both **notes and materials** of their
own (a practice recording, a link), on the same terms as their teacher and
parents (see [Lesson Notes & Resources](/data-model/lesson-notes-resources)).
After adding, the page refreshes itself when the modal closes, so a lesson's
Notes & Materials button appears immediately.
