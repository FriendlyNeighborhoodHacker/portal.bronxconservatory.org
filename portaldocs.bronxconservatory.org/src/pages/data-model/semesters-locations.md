---
layout: ../../layouts/DocsLayout.astro
title: Semesters & Locations
description: The semester as the organizing unit — pricing, per-location calendars, and teacher assignments.
---

# Semesters & Locations

**Tables:** `semesters`, `locations`, `semester_locations`,
`semester_location_dates`, `semester_location_teachers`

## `locations`

The physical sites where BCM teaches, seeded with *Access Bronx Charter
School* and *Bronx Community College*. Columns: `name`, `address`,
`is_active`. Locations are global; which ones are in use for a given term is a
per-semester decision (below).

## `semesters`

**The organizing unit of the whole schedule.** A semester is a `season`
ENUM(`fall`,`spring`,`summer`,`test`) plus a `year`, unique together, with a
`start_date` and `end_date`.

"Current semester" resolution
(`SemesterManagement::resolveDefaultSemester`): the semester containing
today, else the next future one, else the most recent past one. `test`
semesters are ignored unless nothing else exists.

The semester also carries **all pricing** (moved here from the settings table,
so each term can have its own prices):

| Column | Meaning |
| --- | --- |
| `registration_fee` | Charged once per student when their reservation is confirmed |
| `lesson_fee_30_minutes` | Bronx residents: full-semester price for weekly 30-minute private lessons |
| `lesson_fee_60_minutes` | Bronx residents: full-semester price for weekly 60-minute private lessons |
| `guitar_ensemble_fee` | Bronx residents: full-semester price for 30-minute Guitar Ensemble |
| `lesson_fee_30_minutes_nonresident` | Non-residents / online students: 30-minute lessons |
| `lesson_fee_60_minutes_nonresident` | Non-residents / online students: 60-minute lessons |
| `guitar_ensemble_fee_nonresident` | Non-residents / online students: Guitar Ensemble |
| `recital_fee` | Charged per lesson block |
| `installment_plan_fee` | One-time fee for paying tuition in two installments |
| `lessons_per_semester` | Default 15; used for per-lesson price display on the registration form |

These fee columns are `DECIMAL(8,2)` dollars; they are converted to integer
cents when charges are posted to the ledger.

## `semester_locations`

Which locations are in use for a semester — step 2 of the semester creation
wizard. Just the unique (`semester_id`, `location_id`) pair, cascading from
both sides.

## `semester_location_weekdays`

The **declared class days**: which weekdays each location holds classes on
for a semester, with that day's standard hours — imported by CSV in the
wizard (step 3), unique on (`semester_id`, `location_id`,
`day_of_week`) with `start_time`/`end_time`. This is the explicit,
higher-level statement the rest of the semester hangs off: the class-dates
import rejects a date on an undeclared weekday (and blank times inherit the
declared hours), an explicit day in the location-teachers import must be a
day the location is open, and the schedule grid draws a day's band over these
hours as soon as the day is declared — before any dates are imported.
Derivations use the **union** of declared weekdays and actual class-date
weekdays, so a one-off date on an odd weekday still counts. Locations that
predate declarations (none declared) are simply unchecked.

## `semester_location_dates`

The **class calendar per location**, imported by CSV in the wizard (step 3).
One row per (semester, location, date) — unique together — with `start_time`,
`end_time`, a `status` ENUM(`active`,`inactive`), and a `title` (the CSV notes
column: "Day 1", "Holiday Week").

Inactive rows are breaks and holidays: they are surfaced to students on their
schedule ("Holiday Week") but **generate no lessons**. This table is what
drives lesson generation — a confirmed reservation produces one lesson per
*active* date at its location that falls on its day of week.

## `semester_location_teachers`

Which teachers teach at which location **on which day of the week** for a
semester — wizard step 4, unique on (`semester_id`, `location_id`,
`teacher_user_id`, `day_of_week`). These are the **columns of the Semester
Schedule** in the admin UI — the schedule draws one grid per class day, and a
teacher who works Saturdays but not Tuesdays has a Saturday row only —
and `sort_order` fixes the column order within a location. A reservation can
only be placed in a column that exists here, which is why carrying a schedule
forward to a new semester skips reservations whose teacher no longer teaches
at that location on that day.
