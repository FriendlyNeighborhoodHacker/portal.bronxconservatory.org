<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Lists and applies database migrations from db_migrations/, tracking what's
 * been applied in the `schema_migrations` table (created by schema.sql, so
 * fresh installs are tracked from day one).
 *
 * The `schema_migrations` table is the single source of truth: the runner
 * (Admin > Migrations) only ever applies migrations it sees as NOT yet
 * recorded, so it never re-applies an already-applied migration.
 *
 * Applying arbitrary SQL from a web request is only safe because the files
 * come from the server-side db_migrations directory (trusted, checked-in) and
 * callers may only name files that actually exist there — never arbitrary
 * paths or SQL.
 */
final class MigrationRunner {

    /** @var ?string Test override for the migrations directory. */
    private static ?string $dirOverrideForTesting = null;

    /** Absolute path to the migrations directory (configurable via MIGRATIONS_DIR). */
    public static function dir(): string {
        if (self::$dirOverrideForTesting !== null) {
            return self::$dirOverrideForTesting;
        }
        return defined('MIGRATIONS_DIR')
            ? (string)MIGRATIONS_DIR
            : dirname(__DIR__) . '/db_migrations';
    }

    /** Point the runner at a different directory in tests (null to reset). */
    public static function setDirForTesting(?string $dir): void {
        self::$dirOverrideForTesting = $dir;
    }

    /** Does the tracking table exist? (Always true on schemas from 2026-07 on.) */
    public static function trackingTableExists(): bool {
        $st = pdo()->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        );
        return (int)$st->fetchColumn() === 1;
    }

    /**
     * Every migration file in the directory, as bare filenames, in apply order
     * (natural/version sort so 2026-08-01_a precedes 2026-08-02_b).
     *
     * @return string[]
     */
    public static function allFilenames(): array {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/^[0-9].*\.sql$/', $entry) === 1 && is_file($dir . '/' . $entry)) {
                $files[] = $entry;
            }
        }
        natsort($files);
        return array_values($files);
    }

    /**
     * The set of applied migration filenames from the tracking table.
     *
     * @return array<string,string> filename => applied_at
     */
    public static function appliedMap(): array {
        if (!self::trackingTableExists()) {
            return [];
        }
        $rows = pdo()->query('SELECT filename, applied_at FROM schema_migrations')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['filename']] = (string)$row['applied_at'];
        }
        return $map;
    }

    /**
     * Status of every migration for display. Includes recorded-but-missing
     * files (applied rows whose .sql is gone) so nothing silently disappears
     * from the audit trail.
     *
     * @return array<int,array{filename:string,applied:bool,applied_at:?string,missing_file:bool}>
     */
    public static function status(): array {
        $applied = self::appliedMap();
        $files   = self::allFilenames();

        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'filename'     => $f,
                'applied'      => isset($applied[$f]),
                'applied_at'   => $applied[$f] ?? null,
                'missing_file' => false,
            ];
        }
        foreach ($applied as $f => $at) {
            if (!in_array($f, $files, true)) {
                $out[] = [
                    'filename'     => $f,
                    'applied'      => true,
                    'applied_at'   => $at,
                    'missing_file' => true,
                ];
            }
        }
        return $out;
    }

    /** Filenames present on disk that are not yet recorded as applied. */
    public static function pendingFilenames(): array {
        $applied = self::appliedMap();
        return array_values(array_filter(
            self::allFilenames(),
            static fn(string $f): bool => !isset($applied[$f])
        ));
    }

    /**
     * Apply the given migration filenames (a subset of the pending set), in
     * canonical order, recording each success in schema_migrations. Stops at
     * the first failure so a broken migration doesn't cascade into later ones.
     *
     * @param string[]     $requested Filenames selected by the admin.
     * @param ?UserContext $ctx       For the activity log.
     * @return array{applied:string[],skipped:string[],failed:?array{filename:string,error:string}}
     * @throws RuntimeException if tracking isn't initialized.
     */
    public static function apply(array $requested, ?UserContext $ctx): array {
        if (!self::trackingTableExists()) {
            throw new RuntimeException(
                'Migration tracking is not initialized. Load the current schema.sql first.'
            );
        }

        $onDisk  = self::allFilenames();
        $applied = self::appliedMap();

        // Validate + normalize: keep only real, not-yet-applied files, then run
        // them in canonical order regardless of the order they were submitted.
        $toRun = [];
        $skipped = [];
        foreach ($onDisk as $f) {
            if (!in_array($f, $requested, true)) {
                continue;
            }
            if (isset($applied[$f])) {
                $skipped[] = $f;
                continue;
            }
            $toRun[] = $f;
        }
        // Anything requested that isn't a real on-disk migration is ignored
        // silently (can't happen from the UI; guards against tampered input).

        $done = [];
        foreach ($toRun as $f) {
            try {
                self::runFile($f);
            } catch (\Throwable $e) {
                return [
                    'applied' => $done,
                    'skipped' => $skipped,
                    'failed'  => ['filename' => $f, 'error' => $e->getMessage()],
                ];
            }
            self::record($f);
            $done[] = $f;
            ActivityLog::log($ctx, 'migration.applied', ['filename' => $f]);
        }

        return ['applied' => $done, 'skipped' => $skipped, 'failed' => null];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Execute one migration file's SQL. Uses a dedicated connection with
     * multi-statements enabled and iterates every result set so an error in
     * ANY statement (not just the first) surfaces as an exception.
     */
    private static function runFile(string $filename): void {
        $path = self::dir() . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException("Migration file not found: {$filename}");
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration file: {$filename}");
        }
        if (trim($sql) === '') {
            return; // empty file — nothing to run
        }

        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
        $stmt = $pdo->query($sql);
        // Walk every result set so a failure in a later statement is raised.
        do {
            // no rows to consume for DDL/DML migrations
        } while ($stmt->nextRowset());
    }

    /** Record a filename as applied (idempotent). */
    private static function record(string $filename): void {
        $st = pdo()->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (:f)');
        $st->bindValue(':f', $filename, PDO::PARAM_STR);
        $st->execute();
    }
}
