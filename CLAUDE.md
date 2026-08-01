See ALL FILES in the docs/ directory.

Local dev quickstart:
- Load schema.sql (top level) into MySQL db `bronx_music_conservatory` (config in www/config.local.php)
- Run: php -S localhost:8080 -t www   (sign in: brian.rosenthal@gmail.com / lilly)
- Tests: php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml
- Migrations: Admin > Migrations in the portal (tracked in schema_migrations; files in db_migrations/ at the
  top level, outside the web root — the page is enabled only where MIGRATIONS_DIR is set in config.local.php)
- Server logs: Admin > Maintenance > Server Logs (paths configurable via ADMIN_LOG_FILES in config.local.php)
