# Database Migrations

This directory contains database migration files for the BCM Portal. It lives
at the top level of the repository, outside the published web root (`www/`),
so migration SQL is never web-servable.

Because the path differs per machine, the portal finds this directory through
the `MIGRATIONS_DIR` constant in `www/config.local.php` (absolute path). Where
that constant is not set, Admin > Migrations is switched off.

## Migration File Naming Convention

Migration files are named `YYYY-MM-DD_description.sql`
(example: `2026-08-15_add_room_to_locations.sql`).

## Running Migrations

Admin > Migrations in the portal (`/admin/migrations.php`) lists every file
here with its applied/not-applied status and applies the pending ones.
Applied migrations are tracked in the `schema_migrations` table (created by
`schema.sql`), so nothing is ever re-applied.

Each migration should contain SQL that can be executed safely multiple times
(idempotent).

## Important Notes

- Always update `schema.sql` (top level) in the same change; it must represent the
  complete database structure without requiring any migrations to be run.
  Fresh installs load `schema.sql` only.
- Test migrations on a copy of production data before applying to production.
