#!/bin/bash
# reset_db.sh — wipe everything created during setup so the first-run flow
# can be tested again: semesters (and their dates/teachers/reservations/
# lessons), locations, the ledger, and every person EXCEPT the developer
# accounts. Settings, announcements, logs, and the schema itself are kept.
#
# DB credentials are read from www/config.local.php.
set -euo pipefail
cd "$(dirname "$0")"

DEV_EMAILS="'brian.rosenthal@gmail.com','lillyjrosenthal123@gmail.com','m.michaelis@gmail.com'"

eval "$(php -r "require 'www/config.local.php';
  printf('DB_HOST=%s DB_NAME=%s DB_USER=%s DB_PASS=%s',
    escapeshellarg(DB_HOST), escapeshellarg(DB_NAME), escapeshellarg(DB_USER), escapeshellarg(DB_PASS));")"

echo "Resetting setup data in $DB_NAME (keeping settings + developer accounts)..."

MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" << SQL
SET FOREIGN_KEY_CHECKS = 0;

-- Semester machinery (lessons/notes/resources cascade from reservations,
-- but delete explicitly so the intent is visible).
DELETE FROM lesson_resources;
DELETE FROM lesson_notes;
DELETE FROM lessons;
DELETE FROM semester_lesson_reservations;
DELETE FROM semester_hold_blocks;
DELETE FROM semester_hold_block_reservations;
DELETE FROM semester_location_teachers;
DELETE FROM semester_location_dates;
DELETE FROM semester_location_weekdays;
DELETE FROM semester_locations;
DELETE FROM semesters;

-- Leads and incomplete forms. Children first, explicitly: with
-- FOREIGN_KEY_CHECKS off, ON DELETE CASCADE does not fire.
DELETE FROM lead_notes;
DELETE FROM lead_students;
DELETE FROM leads;
DELETE FROM incomplete_inquiry_notes;
DELETE FROM incomplete_inquiries;

-- Money
DELETE FROM ledger_entries;
DELETE FROM stripe_webhook_events;

-- Locations (recreated in setup step 1)
DELETE FROM locations;

-- People: roles and relationships first, then everyone except the developers.
DELETE FROM teacher_instruments;
DELETE FROM student_instruments;
DELETE FROM teacher_profiles;
DELETE FROM student_profiles;
DELETE FROM parenthood;
DELETE FROM private_files;
DELETE FROM users WHERE email IS NULL OR email NOT IN ($DEV_EMAILS);

-- The developers stay, as clean admin accounts with Maintenance access.
UPDATE users SET is_admin = 1, is_developer = 1, is_deleted = 0 WHERE email IN ($DEV_EMAILS);

SET FOREIGN_KEY_CHECKS = 1;
SQL

echo "Done. Remaining users:"
MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
  -e "SELECT id, first_name, last_name, email, is_admin FROM users;"
