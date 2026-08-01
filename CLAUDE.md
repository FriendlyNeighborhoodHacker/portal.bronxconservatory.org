See ALL FILES in the docs/ directory.

Local dev quickstart:
- Load www/schema.sql into MySQL db `bronx_music_conservatory` (config in www/config.local.php)
- Run: php -S localhost:8080 -t www   (sign in: brian.rosenthal@gmail.com / lilly)
- Tests: php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml
- Migrations: Admin > Migrations in the portal (tracked in schema_migrations; files in www/db_migrations/)
