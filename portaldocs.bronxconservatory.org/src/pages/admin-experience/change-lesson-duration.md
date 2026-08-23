---
layout: ../../layouts/DocsLayout.astro
title: Change a Lesson's Duration
description: One-week length changes vs. standing changes with their pro-rata ledger adjustment.
---

# Change a Lesson's Duration

Like moving, this comes in a one-week flavor and a standing flavor — and the
standing flavor involves money.

## One week only (no money)

In the weekly calendar's lesson modal, the **Length** select (30/60/90/120)
changes that occurrence alone — "This week only — the standing booking keeps
its own length." The only check is a conflict sweep: lengthening a lesson
into the next booking is refused. No ledger entry is ever posted for a
single-occurrence change.

## The standing length (with a ledger adjustment)

Changing **Length** in the reservation edit modal on the schedule grid
behaves differently depending on status:

- **Pending reservation** — the duration just changes. No lessons exist yet
  and nothing has been charged, so there is nothing to reconcile. When the
  reservation is later confirmed, the lesson fee for the *new* length is
  what gets charged.
- **Confirmed reservation** — the family was already charged for the old
  length, so the save is intercepted and the admin is taken to the
  **Duration Change Accounting** screen before anything is written.

## The accounting screen

`/admin/duration_change_accounting.php` shows the whole calculation and lets
the admin adjust it before posting:

- **Lesson progress** — total lessons, used, remaining. "Used" counts
  **attended and cancelled** lessons (the family was still charged for a
  cancelled week); a lesson marked *missed* counts as remaining. One-off
  lessons are excluded entirely.
- **Refund** — the original semester fee for the old length, divided by the
  semester's `lessons_per_semester` to get a per-lesson cost; the refund is
  the original fee minus (per-lesson × used).
- **New charge** — the new length's per-lesson cost × lessons remaining.

Both amounts are **editable** before posting, which is the point of the
screen: the formula prices against the full-semester fee even when the
reservation generated fewer lessons (a mid-semester start, say), and integer
division drops sub-cent remainders — so the admin is shown the math and can
round it to what's fair.

**Post Entries & Update Duration** then:

1. Updates the reservation's duration, which re-runs the full conflict sweep
   and moves the future lessons to the new length in place. If the new,
   longer duration collides with the next booking, the change is refused
   here — before any money moves.
2. Posts up to two ledger rows, dated today: a **credit** ("Duration change
   refund: 30→60 min", entry type `other`) and a **debit** ("Duration change
   charge: 30→60 min", entry type `lessons`). Zero amounts post nothing.

## Things to watch

- **No dedupe:** re-submitting the accounting screen posts a second pair of
  entries.
- **A status change made in the same modal save as a length change is
  dropped** — the save exits into the accounting flow before the status is
  applied. Change status and length in two separate saves.
