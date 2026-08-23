---
layout: ../../layouts/DocsLayout.astro
title: View the Semester Schedule
description: The weekly grid — cell colors, adding and confirming reservations, and drag-to-move.
---

# View the Semester Schedule

`/admin/schedule.php` — the **Schedule** item in the top bar, and the page
admins land on. It shows the *abstract weekly pattern* for the selected
semester: what usually happens each week. (For a real week's actual lessons,
see [the calendar](/admin-experience/view-week-schedule).)

## The grid

One grid per class day, stacked — Saturdays on top, then on through the week
— because each day has its own columns and its own hours.

- **Columns** are location–teacher pairs — the `semester_location_teachers`
  rows **for that day**, grouped under location headers in their import
  order. A Saturday-only teacher has no Tuesday column, and a Saturday-only
  location does not appear in the Tuesday grid at all. No columns yet means
  no grid: "Import location teachers to build the grid."
- **Rows** are 30-minute slots over that day's real opening hours from the
  class-date calendar (9:00 am–4:30 pm when there are no dates yet, widened
  automatically to fit whatever is booked). A longer booking spans rows.
- **Cells** hold a reservation (student name + status), a hold block (title
  + "Held", grey diagonal hatch), or nothing. An accidental double-booking
  renders both occupants on a red hatch so nothing is ever hidden.

On narrower screens the grid condenses (short names) and pages by location,
with a jump-to-teacher select on phones. Hovering any cell shows its full
context ("SA 10:00 am · 30 min · Marisol Vega · Bronx Community College").

## The color code

The colors carry meaning and are locked by the design spec:

| Cell state | Appearance |
| --- | --- |
| Pending reach out | White, italic gray text |
| Pending confirmation | White, normal text |
| Confirmed — no outstanding balance | Pastel blue |
| Confirmed — full semester's charges outstanding | Pastel yellow |
| Confirmed — about half outstanding (±50¢) | Pastel purple |
| Confirmed — any other outstanding balance | Dark blue, white text |
| Hold block | Grey with diagonal hatch |

The balance behind the color is the student's **all-time** balance — a
family that still owes from last semester shows a balance color here even if
this term is paid, which is deliberate: the grid is the org's collection
radar.

## Clicking a cell

- **Empty cell → "Reserve this slot."** Location, teacher, day, and time are
  prefilled from the cell; two tabs:
  - **Student Lesson** — pick the student (typeahead), length (30/60/90/120),
    and starting status.
  - **Hold Block** — a title ("What is this time for?") and a length; the
    slot is then held on every class date this semester.
- **Reservation → the edit modal.** Shows the student's outstanding balance,
  a link to their record, and two controls — **Length** (see
  [Change a Lesson's Duration](/admin-experience/change-lesson-duration))
  and **Status** — plus **Delete reservation** ("Future lessons will be
  removed; past lessons are kept").
- **Hold block → its edit modal** — retitle, resize, or delete on the same
  future-only terms.

## What confirming does

The status change is where the schedule meets the money
(`ReservationManagement::setStatus`):

- **→ Confirmed:** the slot is re-checked for conflicts first (a week may
  have been hand-moved into it while this sat pending), then the semester's
  **lessons are generated** — one per active class date at that location on
  that weekday, numbered 1..N by the calendar (holidays generate nothing;
  past dates *are* generated when confirming mid-semester) — and the
  semester's **charges post** to the student's ledger: registration,
  lessons (priced by duration; 90/120-minute lessons prorate off the
  30-minute rate), and recital fee, each skipped if zero **or already live
  for this student+semester** — so a second instrument never double-charges
  the per-student fees. The **installment plan fee** posts only if the admin
  checks "include installment fee" in the confirmation dialog (otherwise the
  daily sweep applies it later if the balance goes unpaid). Before anything
  posts, a **charge-review dialog** itemizes the lines; the endpoint refuses
  the change without its acknowledgement.
  (The guitar ensemble fee is *not* posted automatically — see
  [ledger entries](/admin-experience/accept-a-check).)
- **Confirmed → pending:** future lessons are deleted (past ones stay) and
  the charges are **reversed** — offsetting credits described "Reversal:
  lessons (registration unconfirmed)"; the original debits remain for the
  audit trail. The same review dialog shows the credits first — or warns
  that none will be issued when the student has already had a lesson this
  semester or still holds another confirmed reservation, in which case
  adjust the ledger by hand.
- **Delete** soft-deletes the reservation (terminal — it can't be
  un-deleted), removes future lessons, and reverses charges on the same
  terms (with the same review dialog when the reservation was confirmed).

## Edit mode: drag a weekly slot

Dragging is off by default so reading the schedule can't move someone's
lesson by a trackpad brush. Press **Edit**, drag a reservation to any empty
slot — another time, teacher, or location — and press **Done**. The mechanics
and conflict rules are covered in
[Move a Lesson's Time](/admin-experience/move-a-lesson); a refused move
shows the server's reason and changes nothing.
