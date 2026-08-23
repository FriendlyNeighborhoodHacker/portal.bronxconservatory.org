---
layout: ../layouts/DocsLayout.astro
title: User Model
description: How parents, teachers, students, admins, and developers are represented and resolved.
---

# User Model

The portal has five kinds of users — **parent**, **teacher**, **student**,
**admin**, and **developer** — but only one `users` table and no role column.

## Roles are derived, not stored

`Application::rolesForUser()` is the single implementation. A user's roles
come from row existence:

| Role | Derived from |
| --- | --- |
| admin | `users.is_admin` flag |
| teacher | a `teacher_profiles` row exists for the user |
| parent | a `parenthood` row exists with the user as `parent_user_id` |
| student | a `student_profiles` row exists for the user |

Two consequences worth spelling out:

- **Roles are additive.** A teacher who is also a parent holds both roles. An
  adult who takes lessons themselves gets a `student_profiles` row on their
  own `users` row — there is no separate "adult student" concept.
- **A soft-deleted user holds no roles at all.** `is_deleted` short-circuits
  role resolution, so a deleted user reaches no dashboard, appears in no
  lists, and cannot sign in — but their rows and history remain.

## Dashboard routing

`rolesForUser()` returns roles in priority order, and the site root routes to
the first match:

**admin → teacher → parent → student → `/profile/`**

The `/profile/` fallback covers a freshly invited account that an admin has
not linked to anything yet. A user who holds several roles lands on the
highest-priority dashboard and reaches the others through the navigation. The
same role list drives which menu the top bar shows (`ApplicationUI`).

## Developer is a modifier, not a sixth role

`users.is_developer` is a separate flag that does not participate in routing.
Combined with `is_admin`, it unlocks the **Admin &gt; Maintenance** section:
migrations, the activity log, the email log, and server logs. Think of it as a
permission modifier on the admin role for the people who operate the software
itself.

## Signing in

- **`email`** is the login identifier and is *nullable-unique*: child students
  often have no email and simply cannot sign in — their schedule is visible to
  their parents instead.
- **`password_hash` of `''`** means "cannot sign in yet." A password arrives
  via an invite or the forgot-password flow; until then the account exists (it
  can be scheduled, billed, and noted) without being a login.

## Family structure

There is no `families` table. A family is expressed entirely through
`parenthood` edges (parent user ↔ child user, with an optional
mother/father/guardian role), which handles shared custody, multiple
guardians, and households that don't fit a single-family model. Billing
follows the same shape: charges attach to the *student*, and a parent's
balance is the sum over their linked children.
