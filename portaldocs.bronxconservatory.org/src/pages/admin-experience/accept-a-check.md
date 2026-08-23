---
layout: ../../layouts/DocsLayout.astro
title: Accept a Check & Other Ledger Entries
description: Recording manual payments, scholarships, and custom adjustments against a student's balance.
---

# Accept a Check & Other Ledger Entries

Money taken outside the portal — a check, cash, Zelle — is recorded from the
**Charges and Payments** section of the student's edit page
(`/admin/student_edit.php?id=N`).

## What the section shows

- **Current balance** — the student's *all-time* balance
  (`SUM(debits) − SUM(credits)` across every semester): "$X due", "$X
  credit", or "Paid in full."
- **A line-item table** for the currently selected semester: date, type,
  description, charge, credit.
- If the selected semester's entries don't add up to the all-time balance, an
  **"Earlier charges and payments"** disclosure lists everything else — the
  balance is always explainable from what's on screen.

Three buttons open modals:

## Record Payment

For a check or cash: amount, date (defaults to today — backdate it to when
the check actually arrived), and a description like "Check #123". Lands as a
**credit** of entry type `payment` on the given date.

## Apply Scholarship

Amount and description ("Sliding scale"). Lands as a **credit** of entry type
`scholarship_application`, always dated today.

## Custom Ledger Entry

An adjustment with an explanation — a recital opt-out credit, a make-up
charge. The admin picks **credit (reduces balance)** or **charge (increases
balance)**, an amount, and a required description; it lands as entry type
`other`, dated today.

> One custom entry to know about: **the Guitar Ensemble fee is never posted
> automatically.** Confirming a reservation charges registration, lessons,
> recital, and installment fees — the ensemble fee exists on the semester (and
> in the registration quote) but has no automatic posting, so it must be
> entered by hand as a custom charge.

## What happens on save

Each modal posts to `/admin/ledger_entry_eval.php`, which validates (amount
must be positive; the date must parse; custom entries require a description)
and inserts exactly one `ledger_entries` row attributed to the admin. There is
no dedupe — double-submitting a modal records the entry twice, and fixing that
means a counter-entry, since ledger rows are never deleted.

Because every balance in the system is *derived* from the ledger, the effect
is immediate everywhere on next page load: the student's balance header, the
parent's Balance & Payments page, and the color of the student's cell on the
[Semester Schedule grid](/admin-experience/view-semester-schedule).

## Which semester the entry lands on

The entry is filed against the **admin's currently selected semester** (the
semester picker in the top bar) — not automatically against the term the
student actually owes for. When no semester exists it's filed with no
semester, which groups under "Other charges" and is never counted toward the
"behind on payments" rule. Check the selector before recording a payment
meant for a specific term; a surplus in one term does roll forward to the
next, so a payment filed one term early still nets out, but the per-term
history reads oddly.
