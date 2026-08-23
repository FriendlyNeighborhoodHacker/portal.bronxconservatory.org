---
layout: ../layouts/DocsLayout.astro
title: Overview
description: What portal.bronxconservatory.org is and how this documentation is organized.
---

# Overview

This site documents **portal.bronxconservatory.org**, the operations portal of the
Bronx Conservatory of Music (BCM). BCM is a non-profit that provides private music
instruction of the highest quality to Bronx children and adults, in their own
neighborhoods, at the lowest possible tuition — making conservatory training
accessible to all.

The portal centralizes the conservatory's day-to-day operations for everyone
involved: parents, students, teachers, and administrators. It is a PHP/MySQL
application; all SQL lives in manager classes under `www/lib/`, pages follow a
`foo.php` (render) / `foo_eval.php` (POST handler) convention, and every write
action is recorded in an activity log.

## The big ideas

A few concepts organize the whole system:

- **The semester is the organizing unit.** Everything — schedules, lessons,
  pricing, and money — belongs to a semester (a season + year, e.g. Fall 2026).
  Each semester carries its own fees and its own calendar of teaching dates per
  location.
- **Reservations generate lessons.** A *reservation* is a weekly recurring slot
  (teacher + location + day + time) held for a student for a whole semester.
  When an admin confirms it, the system generates one *lesson* row per actual
  calendar date and posts the semester's charges to the student's ledger.
  Teachers' non-teaching time (lunch, breaks) works the same way via *hold
  blocks*.
- **Money is a ledger.** Balances are never a single number in a column — they
  are the sum of debit and credit entries, each tied to a student and a
  semester, so every balance is explainable line by line. Payments flow through
  Stripe.
- **Prospects are staged as leads.** The public registration and inquiry forms
  never create live accounts. They create *leads*, which an admin reviews and
  converts into real family accounts.
- **Roles are derived, not stored.** A person is a teacher because they have a
  teacher profile, a parent because they are linked to a child — not because of
  a role column. One person can be several things at once.

## How this documentation is organized

- **[Data Model](/data-model)** — the database schema, table by table, for
  readers comfortable with SQL. Split into eight categories with a page each.
- **[User Model](/user-model)** — how the five kinds of users (parent, teacher,
  student, admin, developer) are represented and resolved.
- **[User Registration](/registration)** — the two public intake forms: the
  [Inquiry Form](/registration/inquiry-form) and the
  [Registration Form](/registration/registration-form).
- **[Parent Experience](/parent-experience)**,
  **[Teacher Experience](/teacher-experience)**, and
  **[Student Experience](/student-experience)** — what each role sees and does.
- **[Admin Experience](/admin-experience)** — the administrative pages, plus
  a detailed page for each key flow: creating a semester, managing the
  schedule and calendar, processing and converting leads, and handling money.

## Design principles

The portal's design follows three principles from BCM leadership:

1. **Warm, not institutional.** Real photos, warm language, the BCM
   gold-and-navy brand, and a visible phone number — (718) 841-7415 — on every
   page.
2. **Simple enough for a phone between lessons.** Every screen must make sense
   in under 10 seconds: four cards for parents, three for students, one main
   view for teachers.
3. **Earn trust before asking for money.** Many BCM families are cautious about
   online payments. The "Pay Now" button is the last thing in the flow, not the
   first.
