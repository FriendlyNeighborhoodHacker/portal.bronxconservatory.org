See ALL FILES in the docs/ directory.

Local dev quickstart:
- Load www/schema.sql into MySQL db `bronx_music_conservatory` (config in www/config.local.php)
- Run: php -S localhost:8080 -t www   (sign in: lilly / lilly)
- Tests: php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml
- Migrations: php www/bin/apply_migrations.php [--dry-run]
