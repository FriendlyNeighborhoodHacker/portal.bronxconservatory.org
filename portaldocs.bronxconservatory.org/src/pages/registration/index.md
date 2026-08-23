---
layout: ../../layouts/DocsLayout.astro
title: User Registration
description: The two public intake paths and the lead-staging model behind them.
---

# User Registration

New families reach the portal through two public entry points, both linked
from `welcome.php` (the URL the organization hands out):

- **[The Inquiry Form](/registration/inquiry-form)** (`/inquiry/`) — "Request
  Information." For families who are curious but not ready to commit. Asks
  about interest, never quotes a price.
- **[The Registration Form](/registration/registration-form)** (`/register/`)
  — the full registration wizard. Quotes an itemized price and can take
  payment on the spot.

## Everything is staged

The single most important thing to understand about both forms: **neither
creates a live account.** Submissions become rows in the
[`leads` and `lead_students`](/data-model/leads-intake) tables and nothing
else — no `users` row, no profiles, no reservations. Even a payment made
during registration is held on the lead.

Live data is created only when an admin **converts** the lead
(Admin &gt; Leads): that creates the parent user (or adopts an existing one
by email), the child users with student profiles and instruments, the
`parenthood` links, optional reservation placements, and moves any held
payment onto a student's ledger. See
[Convert a Lead to a Family](/admin-experience/convert-a-lead) for the
conversion walkthrough.

This staging model has practical benefits:

- Public form spam never pollutes the real roster.
- An admin talks to every family before they become part of the schedule —
  which matches how BCM actually operates ("earn trust before asking for
  money").
- Conversion is idempotent, so a half-finished conversion can simply be
  re-run.

## Registration windows

The registration wizard is only open when the `registration_semester_id`
setting names a semester; setting it to empty closes public registration. The
inquiry form is always open, and its "which term?" question offers free-text
labels from the `inquiry_semester_options` setting rather than real semester
rows — so the office can advertise "Fall 2026" before that semester exists in
the system.
