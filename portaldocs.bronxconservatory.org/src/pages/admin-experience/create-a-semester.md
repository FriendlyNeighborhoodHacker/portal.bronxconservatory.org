---
layout: ../../layouts/DocsLayout.astro
title: Create a Semester
description: The setup wizard, the five CSV imports, and pre-populating from a previous semester.
---

# Create a Semester

A semester is created through a wizard: two forms, then a chain of CSV
imports. Each import's Cancel button goes to the *next* step, so an admin who
has nothing to upload yet can walk straight through and come back later —
every step can be re-run any time from **Admin › Semesters**.

## Before the first semester: bootstrap

On a fresh install, Schedule and Calendar redirect to
`/admin/setup/index.php` — "Welcome! Let's set up the portal." — which walks
through the prerequisites:

1. **Locations** — confirm or CSV-import the teaching sites.
2. **Teachers** — CSV-import the teaching staff. Rows match existing people
   by email (then by phone), get a `users` row with an empty password (no
   login), and a teacher profile.
3. *(Optional)* **Students & Parents** — one CSV for the whole roster: one
   row per person, where a student row's "Parents" column names or emails
   their parents (each must match another row or an existing person).
   Anyone listed as a parent becomes a parent; everyone else becomes a
   student; siblings naturally share parent rows.
4. **Create your first semester** — unlocked once locations and teachers
   exist.

## Step 1 — the semester itself

`/admin/semester/new.php`: season (fall / spring / summer / test), year,
start and end dates, and **all the pricing** — registration fee, 30- and
60-minute lesson fees, guitar ensemble fee, recital fee, installment plan
fee, and lessons-per-semester (default 15, used for per-lesson price
display and duration-change math). Duplicate season+year is rejected
("Fall 2026 already exists"). On success the new semester immediately
becomes the admin's selected semester.

Dates and pricing can be edited later (`/admin/semester/edit.php`) — with a
warning that changing the date range does **not** add or remove lessons
(lessons come from the imported class dates), and a count of class dates
that would fall outside the new range.

## Step 2 — active locations and their class days

Checkbox list of which locations are in use this semester (a new semester
pre-checks everything), and — per location — **which days of the week it
holds classes, with that day's standard hours** (e.g. Bronx Community College:
Saturday 9:00 am–5:00 pm and Tuesday 3:30–8:00 pm). Every selected location
needs at least one day. The class-dates import (step 3) rejects dates that
fall on undeclared days, and rows with blank times inherit these hours.
Re-opening this page prefills what is already declared (or, for a location
predating declarations, the days its imported dates imply), so re-saving
never silently drops a day. Saving builds the import chain and sends the
admin into it. Removing a location later does not clean up its dates, teacher
assignments, or reservations — prune those yourself.

## Steps 3–7 — the CSV imports

All five use the same four-stage pipeline: **Upload** (file or pasted text,
comma or tab) → **Mapping** (each CSV column mapped to a field; close names
are auto-matched, so different headers are fine) → **Validation** (every row
shown as Valid or Error with a message; error rows are simply skipped) →
**Commit** ("3 created, 1 updated, 2 skipped"). Re-importing is safe by
design: rows that already exist are updated or skipped, never duplicated.

### Step 3 — Class dates (`location_dates`)

One row per class date per location: `Location Name`, `Date`, `Start Time`,
`End Time`, `Status` (active/inactive, blank = active), `Notes` ("Day 1",
"Holiday Week" — shown on calendars as the date's title). A location may meet
on more than one weekday — Saturdays at both sites and Tuesday evenings at
one, say — with each date carrying its own hours; each weekday's track keeps
its own "Day 1…Day N" numbering, matching how lessons are numbered. Inactive
rows are breaks: no lessons are generated for them, and families see the
notes text. Dates outside the semester range are a warning, not an error.

Committing also **re-syncs existing lessons and hold blocks** at each
touched location — confirmed reservations drop future lessons on
dates that are no longer active and generate any newly active ones.

### Step 4 — Location teachers (`location_teachers`)

`Teacher Name`, `Location Name`, `Day` (optional) — which teachers teach
where, and on which day of the week. These become the **columns of the
Semester Schedule** — the schedule draws one grid per class day, and a
teacher appears only in the grids of the days listed here; nothing can be
scheduled for a teacher at a location on a day without one. A blank `Day`
assigns the teacher to every weekday the location holds classes on. The
import is purely additive (existing assignments are skipped, never removed).

### Step 5 — Hold blocks (`hold_blocks`)

`Teacher Name`, `Location Name`, `Day`, `Start Time`, `End Time`, `Title`
("Lunch") — a teacher's standing non-lesson time. Each row holds that slot
on every class date this semester. End must be after start, at most 4 hours;
the teacher must already have a column at that location; and every row is
conflict-checked three ways — against earlier rows in the same file, against
existing weekly bookings, and against every future materialized date.

### Step 6 — The schedule itself (`reservations`)

One row per weekly lesson slot — how an existing schedule moves into the
portal: `Student Name` (or email), `Teacher Name`, `Location Name`, `Day`,
`Start Time`, `Duration Minutes` (blank = 30), `Status` (pending reach out /
pending confirmation / confirmed).

- Confirmed rows **generate their lessons but post no charges** — balances
  carried over from the old system come in step 7, so nobody is billed
  twice.
- Rows are checked against the location's calendar ("no active class dates
  on Saturdays this semester") and five layers of conflicts, including
  student double-booking — "a CSV has no eyes on it," so the importer checks
  what the grid leaves to the admin's eyes.
- A row whose slot is already reserved *for the same student* is "Already
  reserved (no change)".

### Step 7 — Opening charges and payments (`ledger_entries`)

One row per charge families already ran up and per payment they already
made, on the dates they happened: `Student Name`, `Entry Type`
(registration / lessons / recital fee / payment / scholarship / other),
`Amount` (always positive), `Date` (blank = semester start), `Debit or
Credit` (defaults by type; required for "other"), `Description`. This is
what makes the schedule grid color-code who owes what from day one.
**Re-uploading the same file is a no-op** — rows already on the ledger are
skipped exactly.

The chain ends on the Semester Schedule.

## The other way in: pre-populate from a previous semester

Instead of the step-6 CSV, `/admin/semester/prepopulate.php` copies a
previous semester's schedule: every student keeps their teacher, location,
day, and time, and **everything arrives as `pending_reach_out`** — the list
to call through. Nothing is confirmed, no lessons are generated, and nobody
is charged until each reservation is confirmed.

A preview table shows exactly what will happen per reservation — including
each one's status *last* semester — before committing:

- **Skipped with an error:** the teacher no longer teaches at that location
  this semester (import location teachers first), or the slot is now taken
  (by another carried-forward reservation or an existing booking/hold
  block).
- **"Already carried over (no change)":** matching is by student + teacher +
  location — not by slot — so re-running after times have been shuffled
  never creates duplicates. (Corollary: if a student was moved to a
  *different teacher* in the new semester, re-running would carry them
  again — delete the stale copy.)
- Deleted reservations, and reservations whose student or teacher has since
  been soft-deleted, don't come across at all.

Hold blocks are not carried forward — import them per semester (step 5).
