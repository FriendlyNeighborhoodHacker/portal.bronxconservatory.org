# Database Migrations

This directory contains database migration files for the BCM Family Portal.

## Migration File Naming Convention

Migration files are named `YYYY-MM-DD_description.sql`
(example: `2026-07-30_initial_schema.sql`).

## Running Migrations

    php www/bin/apply_migrations.php [--dry-run] [--skip-backup]

The runner applies pending migrations in order, skipping ones already applied
(detected by a sentinel table/column registered in `bin/apply_migrations.php`
— every new migration file must be added to that map). Before changing
anything it writes a full mysqldump backup to `db_backups/` at the project
root. Each migration should contain SQL that can be executed safely multiple
times (idempotent).

## Important Notes

- Always update `www/schema.sql` in the same change; it must represent the
  complete database structure without requiring any migrations to be run.
- Test migrations on a copy of production data before applying to production.
