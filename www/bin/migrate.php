#!/usr/bin/env php
<?php
// Command-line front end for MigrationRunner, the same code behind
// Admin > Maintenance > Migrations. Lists every migration in MIGRATIONS_DIR
// with its applied / pending status, or applies the pending ones, against the
// database named in config.local.php. Like the admin page it refuses to run
// unless MIGRATIONS_DIR is configured and schema_migrations exists.
//
// Usage: php migrate.php                 list migrations and their status
//        php migrate.php --apply         apply every pending migration, in order
//        php migrate.php --apply=FILE    apply one pending migration by filename
//
// Exit status: 0 on success (including nothing to do), 1 on bad arguments,
// a missing configuration, or a migration that failed (later ones are not run).
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';

$apply = false;
$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--apply=')) {
        $apply = true;
        $only = substr($arg, strlen('--apply='));
    } else {
        fwrite(STDERR, "Unknown argument: $arg\nUsage: php migrate.php [--apply | --apply=FILE]\n");
        exit(1);
    }
}

if (!MigrationRunner::isConfigured()) {
    fwrite(STDERR, "MIGRATIONS_DIR is not set in config.local.php; nothing to run.\n");
    exit(1);
}
if (!MigrationRunner::trackingTableExists()) {
    fwrite(STDERR, "The schema_migrations table is missing. Load the current schema.sql first.\n");
    exit(1);
}

if (!$apply) {
    $pending = 0;
    foreach (MigrationRunner::status() as $row) {
        if ($row['applied']) {
            $state = 'applied ' . $row['applied_at'] . ($row['missing_file'] ? ' (file missing)' : '');
        } else {
            $state = 'PENDING';
            $pending++;
        }
        printf("%-64s %s\n", $row['filename'], $state);
    }
    echo $pending === 0 ? "Up to date.\n" : "$pending pending. Apply with --apply.\n";
    exit(0);
}

$pending = MigrationRunner::pendingFilenames();
if ($only !== null) {
    if (!in_array($only, $pending, true)) {
        fwrite(STDERR, "$only is not a pending migration (already applied, or not in MIGRATIONS_DIR).\n");
        exit(1);
    }
    $pending = [$only];
}
if ($pending === []) {
    echo "Nothing to apply.\n";
    exit(0);
}

$result = MigrationRunner::apply($pending, null);
foreach ($result['applied'] as $f) {
    echo "Applied $f\n";
}
if ($result['failed'] !== null) {
    fwrite(STDERR, "FAILED {$result['failed']['filename']}: {$result['failed']['error']}\n");
    exit(1);
}
exit(0);
