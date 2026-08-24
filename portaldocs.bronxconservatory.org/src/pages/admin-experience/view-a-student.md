---
layout: ../../layouts/DocsLayout.astro
title: View a Student
description: The student page — parents, lesson reservations, charges, notes, and the edit-profile page behind it.
---

# View a Student

`/admin/student.php?id=N` — the page every student link lands on: the "Open
&lt;student&gt;" links in the schedule and calendar modals, the students list, a
teacher's or parent's page, and a converted lead. It is read-oriented, ordered
by what an admin actually opens it for:

1. **Parents** — each parent's name (linked to their page), role, and their
   **phone and email in large plain text**, deliberately unlinked so they
   copy cleanly. Add Parent opens the usual two-tab dialog (create new /
   link existing); Unlink stays per row.
2. **Lesson Reservation(s)** — this semester's reservations: day and time,
   teacher **with the lesson's instrument in parentheses**, location, and
   status. The instrument is derived (reservations don't store one): the
   overlap of what the teacher teaches and the student plays, falling back
   to the teacher's list, then the student's.
   Below the rows, a lesson-notes summary — "8 lesson notes this semester.
   Last lesson note: Oct 3, 2026. **More**" — links to
   `/admin/student_notes.php?id=N`, every note the student has across all
   semesters, newest lesson first, each with its date, text, and author.
3. **Charges and Payments** — the balance, line-item ledger, and the Record
   Payment / Apply Scholarship / Custom Entry modals, covered in
   [Accept a Check &amp; Other Ledger Entries](/admin-experience/accept-a-check).
4. **Student Details** — read-only: the person fields with the **address
   formatted as an address**, then one Demographics line (admin-only code,
   with its label), then one Instruments line with an **edit** link that
   opens a checkbox modal.

## The photo

The page shows a photo **only if one exists** — no initials placeholder. The
top-right button reads **Add Photo** (none yet) or **Edit Profile Photo**,
and opens one modal that uploads, replaces, or removes the photo (same
`/upload_photo.php` endpoint as everywhere else: JPEG/PNG/WebP, 8&nbsp;MB max).

## Edit Profile

The **Edit Profile** button (top right) opens `/admin/student_edit.php?id=N`,
now a focused form: the basic-information fields with **Demographic as just
another field** (its admin-only nature noted inline), and the soft-delete
button at the bottom. Saving returns to the student page.

Everything else that used to live on the old edit page — parents,
instruments, reservations, notes, ledger, photo — lives on the student page
itself.
