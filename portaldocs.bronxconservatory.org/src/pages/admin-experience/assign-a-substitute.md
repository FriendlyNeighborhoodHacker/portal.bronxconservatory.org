---
layout: ../../layouts/DocsLayout.astro
title: Assign a Substitute Teacher
description: Covering one lesson with another teacher, and what changes for everyone involved.
---

# Assign a Substitute Teacher

A substitute covers **one occurrence** — the reservation and every other week
keep the regular teacher. It's set from the weekly calendar's lesson modal
(the "Substitute teacher" select), or implicitly by
[dragging the lesson onto another teacher's column](/admin-experience/move-a-lesson).

## Who can be picked

**Anyone on staff** — the select lists every teacher in the system, not just
this semester's roster, because cover often comes from someone who isn't
teaching a regular slot. It's grouped into "Teaching this semester" (labeled
with their locations) and "Other teachers." The first option, "No substitute
— the usual teacher," clears the assignment.

The one hard requirement: **the substitute has to be free at that hour.** The
pick is conflict-checked against the substitute's own lessons and hold blocks
at that moment, and an already-booked cover is refused. (There is no check
that the substitute is assigned to that location or semester — that's
deliberate; clearing a substitute needs no checks at all.)

Location is intentionally a **separate** dropdown in the same modal: picking
a cover teacher who is based at the other building must not silently move the
family across the borough. If the venue really changes too, set the location
override explicitly.

## What changes

The write is one field — `lessons.substitute_teacher_user_id` — but the
"effective teacher" of a lesson is computed as *substitute, else the
reservation's teacher* everywhere in the system, so:

- The lesson **leaves the regular teacher's day** and appears in the
  substitute's day view and Teaching Days — even if the substitute has no
  regular column that semester, the calendar grows one for them.
- The **substitute can mark attendance** and add notes; they count as the
  lesson's teacher for access checks.
- The substitute's **time is now blocked** at that hour for every future
  conflict check.
- On the admin weekly calendar the cell is tinted pastel orange with a
  "Substitute teacher: Grace Lin" note; parents and students see the same
  note with the substitute's name on their schedule.

Undo it by selecting "No substitute" (or dragging the lesson back onto its
own teacher's column).
