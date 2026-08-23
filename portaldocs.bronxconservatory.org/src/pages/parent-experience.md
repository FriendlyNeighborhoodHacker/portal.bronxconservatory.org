---
layout: ../layouts/DocsLayout.astro
title: Parent Experience
description: The four-card parent homepage, lesson visibility, and how families pay.
---

# Parent Experience

Parents are the portal's most important audience, and their experience is
built for a phone on the bus: **four cards**, each legible in seconds. The
parent menu is `[Calendar] [Billing] [Profile photo]`.

## The homepage

1. **Announcements** — recent active announcements (those whose `valid_until`
   hasn't passed), in chronological order.
2. **My Children** — one card per child: photo, name, instrument, teacher.
   *When the next lesson is, is the loudest thing on the card* — it is what a
   parent opens the portal to check. A child who owes money shows
   "Balance due: $X" linking to Balance & Payments; the amount is red **only**
   when the family has fallen behind the payment schedule — a balance that is
   simply not due yet is not an alarm.
3. **Upcoming Lessons** — the family's next four lessons across all children:
   date, time, child, location, each with Notes and Materials links that open
   that lesson in a modal.
4. **Balance & Payments** — either "You are paid in full. Thank you!" or
   "You have a balance of $X. Click here to pay the balance."

Clicking into a child shows their schedule, recent teacher notes, and
materials. Every lesson — past and upcoming — opens its notes and materials in
a modal the parent can add to; a note like "she has a cold, can we make this
up?" lands in the same thread as the teacher's account of the lesson.

## The payment schedule

Money is tracked per term: every ledger entry carries its semester, and a
term's surplus credit rolls forward into the next. What families are asked to
pay is:

- **at least half the term's charges by two weeks before the term starts**, and
- **the rest by the lesson before the term's half-way point** (of 14 lessons,
  by the 6th).

A balance that has missed either deadline is *past due* and shows in red.
Everywhere a balance appears — the child card, the admin schedule grid — red
means "behind the schedule," not merely "unpaid."

## Paying

On Balance & Payments a parent can pay **any amount up to what they owe**, by
card via Stripe Elements — card details never reach BCM's server. The payment
is applied to the **oldest debt first, child by child**
(`Billing::allocatePaymentAcrossStudents`), and recording is idempotent
between the Stripe webhook and the browser's return trip. Families who prefer
to pay by check or cash hand it to the office, and an admin records it against
the balance directly.
