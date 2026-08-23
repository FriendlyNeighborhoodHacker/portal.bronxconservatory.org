---
layout: ../../layouts/DocsLayout.astro
title: Admin Experience
description: The administrative pages — schedule grid, calendar, people management, and maintenance.
---

# Admin Experience

Administrators run the conservatory from the portal. Their menu is
`[Schedule] [Calendar] [Students] [Teachers] [Leads] [Admin] [Semester
selector] [Profile photo]` — most admin pages operate in the context of the
selected semester.

When there are no semesters yet (a fresh install), the admin is walked
through a bootstrap: confirm locations, upload teachers by CSV, then create
the first semester through the wizard. Once at least one semester exists, the
system resolves a default: the semester containing today, else the next
future one, else the most recent past one (test semesters are ignored unless
nothing else exists).

## The key flows

Each operational flow has its own detailed page:

| Flow | What it covers |
| --- | --- |
| [Create a Semester](/admin-experience/create-a-semester) | The wizard, the five CSV imports, pre-populating from a previous semester |
| [View the Semester Schedule](/admin-experience/view-semester-schedule) | The weekly grid, cell colors, confirming reservations (lessons + charges), drag-to-move |
| [View the Calendar & a Week](/admin-experience/view-week-schedule) | The semester date list and the weekly grid of real lessons |
| [Process Leads & Uncompleted Forms](/admin-experience/process-leads) | The queues, statuses, append-only notes, account invites |
| [Convert a Lead to a Family](/admin-experience/convert-a-lead) | Creating people, placing reservations, moving held payments |
| [Accept a Check & Other Ledger Entries](/admin-experience/accept-a-check) | Manual payments, scholarships, custom adjustments |
| [Move a Lesson's Time](/admin-experience/move-a-lesson) | Standing-slot moves vs. one-week reschedules |
| [Change a Lesson's Duration](/admin-experience/change-lesson-duration) | One-week changes, and standing changes with pro-rata accounting |
| [Assign a Substitute Teacher](/admin-experience/assign-a-substitute) | Covering one lesson, and what changes for everyone |
| [Cancellations & One-Off Changes](/admin-experience/cancellations-and-one-offs) | Cancelling, attendance, room changes, ad hoc lessons |

## The main pages

**Schedule** — the semester's weekly grid; the admin landing page. Covered in
[View the Semester Schedule](/admin-experience/view-semester-schedule).

**Calendar** — the semester's class dates as a chronological list (class
days green, holidays purple), and a weekly view of real lessons. Covered in
[View the Calendar & a Week](/admin-experience/view-week-schedule).

**Students** and **Teachers** — filterable lists with the same pattern: a
keyword filter doing prefix-token search over names, phone numbers, and
address lines (for students, parent names too), with extra filters — by
teacher and by instrument — under a `[+]`. The students list shows Name /
Parent(s) (name on one line, phone + email on the next, per parent) /
Actions; the teachers list shows Name / Contact / Actions. Add buttons sit
top-right.

**Edit Student** — photo, basic info, parents (with Add Parent),
instruments, and **Charges and Payments**: the current balance, a line-item
breakdown of this semester's charges and payments, and — if those don't
reconcile to the balance — the earlier entries that explain it. From here an
admin records [payments and adjustments](/admin-experience/accept-a-check).
Deleting flags `is_deleted` after a confirmation; nothing is actually
removed.

**Edit Teacher** — photo, basic info, this semester's students (linked),
soft delete.

**Edit Parent** — photo, basic info, children with an **Add Child** dialog
offering two paths: *Add New Child* (name, suffix, preferred name, class of)
or *Link Existing Child* (typeahead + Link Child). Soft delete.

**Leads** — sits in the top bar rather than the Admin submenu, because
working the queue is daily work. Covered in
[Process Leads](/admin-experience/process-leads).

## Admin menu

The `[Admin]` menu collects the back-office pages: Semesters (the creation
wizard, imports, and carry-forward), Locations, Announcements, Email
Templates, and Settings.

## Maintenance (developers only)

Visible only to users with **both** `is_admin` and `is_developer`: the email
log, the activity log, server logs (paths configured via `ADMIN_LOG_FILES`),
and database migrations (Admin › Migrations, enabled where `MIGRATIONS_DIR`
is configured).
