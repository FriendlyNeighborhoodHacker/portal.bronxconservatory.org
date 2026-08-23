---
layout: ../../layouts/DocsLayout.astro
title: Lesson Notes & Resources
description: The append-only conversation attached to each lesson, and its files and links.
---

# Lesson Notes & Resources

**Tables:** `lesson_notes`, `lesson_resources`

## `lesson_notes`

What was said about a lesson. Notes belong to a **lesson, not a person**:
each note is its own row, kept for good, and shown with who wrote it and when
— a conversation about the lesson rather than one editable box.

Anyone who may see a lesson may add a note to it: its teacher, an admin, the
student, or a parent. That is deliberate — "she has a cold, can we make this
up?" from a parent and the teacher's account of the lesson sit in the same
short thread. In the UI, notes are saved with a button, never auto-saved, so
the writer knows it was written down.

Columns: `lesson_id` → `lessons` (`ON DELETE CASCADE`),
`created_by_user_id` → `users`, `body`, timestamps. Indexed on
(`lesson_id`, `created_at`) for reading a lesson's thread in order.

## `lesson_resources`

Materials attached to a lesson: an uploaded file (a recording, sheet music) or
an external link, each with a `title`.

- **`resource_type`** ENUM(`file`,`link`) discriminates the two shapes:
  `private_file_id` → `private_files` is required when the type is `file`;
  `url` is required when the type is `link`. The requiredness is enforced in
  `ResourceManagement`, not by the database.
- Uploaded files live in [`private_files`](/data-model/files-infrastructure)
  and are served **only** through `resource_download.php`, which authorizes
  the requester by checking their relationship to the lesson (student, parent,
  teacher, or admin) via the lesson's reservation.

Adding materials works on the same terms as notes: anyone who may see the
lesson — teacher, student, parent, admin — may attach to it; removing a
material is limited to whoever added it, or an admin. Resources surface in
three places: on the teacher's lesson card (where "Add a resource" lives), in
the family's lesson modal (which offers the same editor), and collected on
the student's My Materials page in lesson-date order.
