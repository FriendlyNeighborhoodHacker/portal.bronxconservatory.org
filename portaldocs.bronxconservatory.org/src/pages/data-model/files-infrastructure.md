---
layout: ../../layouts/DocsLayout.astro
title: Files & Infrastructure
description: DB-backed file storage, settings, migrations tracking, and the activity log.
---

# Files & Infrastructure

**Tables:** `public_files`, `private_files`, `settings`, `schema_migrations`,
`activity_log`

## `public_files` and `private_files`

All binary uploads live in the database, in two tables with an identical
shape: `data` (`LONGBLOB`), `content_type`, `original_filename`,
`byte_length`, `sha256`, `created_by_user_id`, `created_at`. A "file id" in
the rest of the schema is just an integer FK into one of these tables.

Files are **immutable once stored** — the `data` of a row is never updated; to
change a file, insert a new row and repoint the referencing column. That makes
caching trivially safe.

The two tables differ only in access policy:

- **`public_files`** — public by design: profile photos
  (`users.photo_public_file_id`), the login-page logo. Served by
  `public_file_download.php` or a lazily-written on-disk cache.
- **`private_files`** — lesson resources: recordings, sheet music. Served
  **only** through the authorization-checked `resource_download.php` (which
  verifies the requester's relationship to the lesson) and never written to
  the disk cache.

## `settings`

A key-value table (`key_name` unique, `value LONGTEXT`) for site-wide
configuration. Seeded keys:

| Key | Meaning |
| --- | --- |
| `site_title` | "BCM Portal" |
| `announcement` | A site-wide banner message |
| `timezone` | `America/New_York` |
| `login_image_file_id` | `public_files` id for the login-page image |
| `site_base_url` | `https://portal.bronxconservatory.org` |
| `contact_phone` | (718) 841-7415 — shown on every page footer |
| `registration_semester_id` | The semester the public registration wizard is open for; `''` means registration is closed |
| `inquiry_semester_options` | JSON array of free-text term labels offered on the inquiry form (not `semesters` rows) |
| `inquiry_notification_email` | Where the inquiry staff notification goes |

Pricing used to live here but has moved to per-semester columns on
[`semesters`](/data-model/semesters-locations).

## `schema_migrations`

The source of truth for which `db_migrations/*.sql` files have been applied:
`filename` (unique) and `applied_at`. Fresh installs load `schema.sql`, which
is always the complete current schema; migrations exist only to upgrade older
production installations, and are applied through Admin &gt; Migrations
(`lib/MigrationRunner.php`). The Migrations page is enabled only where
`MIGRATIONS_DIR` is set in `config.local.php`.

## `activity_log`

Every write action and every login is logged: `user_id` (`NULL` when there is
no acting user), a short `action_type` string (e.g. `lead.status_changed`),
and `json_metadata` with the details. Indexed on `created_at`, `user_id`, and
`action_type`. Browsable by developers in Admin &gt; Maintenance. Like the
email log, writing to it is a standing rule of the codebase — every mutator
class logs what it did.
