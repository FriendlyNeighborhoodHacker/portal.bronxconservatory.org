<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Application.php';
require_once __DIR__ . '/CsvImport.php';
require_once __DIR__ . '/LocationManagement.php';
require_once __DIR__ . '/LocationCsvImport.php';
require_once __DIR__ . '/TeacherCsvImport.php';
require_once __DIR__ . '/PeopleCsvImport.php';
require_once __DIR__ . '/LocationWeekdaysCsvImport.php';
require_once __DIR__ . '/LocationDatesCsvImport.php';
require_once __DIR__ . '/LocationTeachersCsvImport.php';
require_once __DIR__ . '/HoldBlocksCsvImport.php';
require_once __DIR__ . '/SemesterReservationsCsvImport.php';
require_once __DIR__ . '/LedgerEntriesCsvImport.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/ReservationManagement.php';

/**
 * One-click load of sample_data/ on a dev instance: walks every CSV through
 * the real importers in the order sample_data/README.md prescribes (general/
 * once, then Fall 2026's files, then Spring 2027's files and the
 * pre-populate from fall). This is exactly what an admin would do by hand
 * through the setup wizard; nothing here bypasses validation.
 *
 * Re-running is safe: semesters that already exist are reused and every
 * importer skips rows it has already applied.
 *
 * The directory is found through SAMPLE_DATA_DIR in config.local.php, falling
 * back to the repository layout (sample_data/ beside www/). On a deployed
 * server www/ is copied out of the checkout, so the constant is how the
 * loader finds the checkout's copy.
 */
class SampleDataLoader {

    public const FALL = ['season' => 'fall', 'year' => 2026, 'start' => '2026-09-08', 'end' => '2026-12-20', 'dir' => 'fall_semester'];
    public const SPRING = ['season' => 'spring', 'year' => 2027, 'start' => '2027-01-25', 'end' => '2027-05-23', 'dir' => 'spring_semester'];

    /** The two locations general/locations.csv creates; both semesters use both. */
    private const LOCATION_NAMES = ['Access Bronx Charter School', 'Bronx Community College'];

    /** Matches the amounts fall_semester/ledger_entries.csv charges. */
    private const PRICING = [
        'registration_fee' => '35',
        'lesson_fee_30_minutes' => '420',
        'lesson_fee_60_minutes' => '840',
        'guitar_ensemble_fee' => '150',
        'lesson_fee_30_minutes_nonresident' => '470',
        'lesson_fee_60_minutes_nonresident' => '940',
        'guitar_ensemble_fee_nonresident' => '175',
        'recital_fee' => '15',
        'installment_plan_fee' => '20',
        'lessons_per_semester' => 15,
    ];

    /** Absolute path to the sample_data directory, or '' when none is found. */
    public static function sampleDataDir(): string {
        $dir = defined('SAMPLE_DATA_DIR') && SAMPLE_DATA_DIR !== ''
            ? (string)SAMPLE_DATA_DIR
            : dirname(__DIR__, 2) . '/sample_data';
        return is_dir($dir) ? $dir : '';
    }

    /** Both conditions for offering the loader: DEV_SITE is on and there is a directory to load from. */
    public static function isAvailable(): bool {
        return Application::isDevSite() && self::sampleDataDir() !== '';
    }

    /**
     * Load everything. Returns one summary line per step, in order:
     * [['label' => 'general/locations.csv', 'created' => n, 'updated' => n, 'skipped' => n], ...]
     * plus ['fall_semester_id' => id, 'spring_semester_id' => id] under 'semesters'.
     *
     * Throws on the first file with an invalid row (the message names the file,
     * row and problem); steps already committed stay committed, as they would
     * if an admin had stopped part-way through the wizard.
     */
    public static function load(?UserContext $ctx): array {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        $root = self::sampleDataDir();
        if ($root === '') {
            throw new RuntimeException('Sample data directory not found. Set SAMPLE_DATA_DIR in config.local.php.');
        }

        $steps = [];
        $steps[] = self::importFile($ctx, $root, 'general/locations.csv', LocationCsvImport::class);
        $steps[] = self::importFile($ctx, $root, 'general/teachers.csv', TeacherCsvImport::class);
        $steps[] = self::importFile($ctx, $root, 'general/people.csv', PeopleCsvImport::class);

        $locationIds = self::sampleLocationIds();

        [$fallId, $fallStep] = self::ensureSemester($ctx, self::FALL, $locationIds);
        $steps[] = $fallStep;
        foreach (self::importSemesterFiles($ctx, $root, $fallId, self::FALL['dir']) as $step) {
            $steps[] = $step;
        }

        [$springId, $springStep] = self::ensureSemester($ctx, self::SPRING, $locationIds);
        $steps[] = $springStep;
        foreach (self::importSemesterFiles($ctx, $root, $springId, self::SPRING['dir']) as $step) {
            $steps[] = $step;
        }

        $carried = ReservationManagement::carryForwardFromSemester($ctx, $springId, $fallId);
        $steps[] = [
            'label' => 'Pre-populate Spring 2027 from Fall 2026',
            'created' => (int)$carried['created'],
            'updated' => 0,
            'skipped' => (int)$carried['skipped'],
        ];

        ActivityLog::log($ctx, 'sample_data.loaded', [
            'fall_semester_id' => $fallId,
            'spring_semester_id' => $springId,
            'steps' => count($steps),
        ]);

        return [
            'steps' => $steps,
            'semesters' => ['fall_semester_id' => $fallId, 'spring_semester_id' => $springId],
        ];
    }

    /** Human-readable one-liner for a flash message. */
    public static function summarize(array $result): string {
        $parts = [];
        foreach ($result['steps'] as $step) {
            $bits = [];
            if ($step['created']) $bits[] = $step['created'] . ' created';
            if ($step['updated']) $bits[] = $step['updated'] . ' updated';
            if ($step['skipped']) $bits[] = $step['skipped'] . ' skipped';
            $parts[] = $step['label'] . ': ' . ($bits ? implode(', ', $bits) : 'nothing to do');
        }
        return 'Sample data loaded. ' . implode('; ', $parts) . '.';
    }

    // ── Steps ─────────────────────────────────────────────────────────────

    /** The semester-scoped files, in wizard order. The two schedule files only exist for fall. */
    private static function importSemesterFiles(UserContext $ctx, string $root, int $semesterId, string $dir): array {
        $context = ['semester_id' => $semesterId];
        $steps = [];
        $steps[] = self::importFile($ctx, $root, $dir . '/location_weekdays.csv', LocationWeekdaysCsvImport::class, $context);
        $steps[] = self::importFile($ctx, $root, $dir . '/location_dates.csv', LocationDatesCsvImport::class, $context);
        $steps[] = self::importFile($ctx, $root, $dir . '/location_teachers.csv', LocationTeachersCsvImport::class, $context);
        $steps[] = self::importFile($ctx, $root, $dir . '/hold_blocks.csv', HoldBlocksCsvImport::class, $context);
        if (is_file($root . '/' . $dir . '/semester_location_reservations.csv')) {
            $steps[] = self::importFile($ctx, $root, $dir . '/semester_location_reservations.csv', SemesterReservationsCsvImport::class, $context);
        }
        if (is_file($root . '/' . $dir . '/ledger_entries.csv')) {
            $steps[] = self::importFile($ctx, $root, $dir . '/ledger_entries.csv', LedgerEntriesCsvImport::class, $context);
        }
        return $steps;
    }

    /** Parse, auto-map by header name, validate (every row must pass), commit. */
    private static function importFile(UserContext $ctx, string $root, string $relPath, string $importer, array $context = []): array {
        $path = $root . '/' . $relPath;
        if (!is_file($path)) {
            throw new RuntimeException('Sample file missing: ' . $relPath);
        }
        $parsed = CsvImport::parseCsv((string)file_get_contents($path), ',');
        $mapping = CsvImport::suggestColumnMapping($parsed['headers'], $importer::targetFields());
        $rows = CsvImport::applyMapping($parsed['rows'], $mapping);
        $validated = $importer::validateRows($rows, $context);

        $problems = [];
        foreach ($validated as $entry) {
            if ($entry['status'] !== 'valid') {
                $problems[] = 'row ' . $entry['row'] . ': ' . implode(' ', $entry['messages']);
            }
        }
        if ($problems) {
            $shown = array_slice($problems, 0, 3);
            $more = count($problems) - count($shown);
            throw new InvalidArgumentException(
                $relPath . ' has ' . count($problems) . ' invalid row' . (count($problems) === 1 ? '' : 's')
                . ' (' . implode('; ', $shown) . ($more > 0 ? '; and ' . $more . ' more' : '') . ').'
            );
        }

        $summary = $importer::commit($ctx, $validated, $context);
        return [
            'label' => $relPath,
            'created' => (int)($summary['created'] ?? 0),
            'updated' => (int)($summary['updated'] ?? 0),
            'skipped' => (int)($summary['skipped'] ?? 0),
        ];
    }

    /**
     * Create the semester (with the sample pricing) and attach both sample
     * locations — or, on a re-run, reuse the one that already exists.
     * Returns [semesterId, stepSummary].
     */
    private static function ensureSemester(UserContext $ctx, array $spec, array $locationIds): array {
        $label = ucfirst($spec['season']) . ' ' . $spec['year'] . ' semester';
        $existing = self::findSemester($spec['season'], (int)$spec['year']);
        if ($existing) {
            $id = (int)$existing['id'];
            SemesterManagement::setActiveLocations($ctx, $id, $locationIds);
            return [$id, ['label' => $label, 'created' => 0, 'updated' => 0, 'skipped' => 1]];
        }
        $id = SemesterManagement::createSemester(
            $ctx, $spec['season'], (int)$spec['year'], $spec['start'], $spec['end'], self::PRICING,
            SemesterManagement::midpointDate($spec['start'], $spec['end'])
        );
        SemesterManagement::setActiveLocations($ctx, $id, $locationIds);
        return [$id, ['label' => $label, 'created' => 1, 'updated' => 0, 'skipped' => 0]];
    }

    private static function findSemester(string $season, int $year): ?array {
        foreach (SemesterManagement::listSemesters() as $semester) {
            if ((string)$semester['season'] === $season && (int)$semester['year'] === $year) {
                return $semester;
            }
        }
        return null;
    }

    /** Ids of the two sample locations, by name (other locations may exist). */
    private static function sampleLocationIds(): array {
        $ids = [];
        foreach (LocationManagement::all(true) as $location) {
            if (in_array((string)$location['name'], self::LOCATION_NAMES, true)) {
                $ids[] = (int)$location['id'];
            }
        }
        if (count($ids) !== count(self::LOCATION_NAMES)) {
            throw new RuntimeException('Expected the sample locations ' . implode(' and ', self::LOCATION_NAMES) . ' to exist after importing general/locations.csv.');
        }
        return $ids;
    }
}
