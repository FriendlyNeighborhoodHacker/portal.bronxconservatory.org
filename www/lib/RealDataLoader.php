<?php
declare(strict_types=1);

require_once __DIR__ . '/SampleDataLoader.php';
require_once __DIR__ . '/UserManagement.php';

/**
 * Launch loader: resets the database to exactly what the real-data directory
 * describes. The directory has the layout of sample_data/ (general/, one
 * directory per semester) plus:
 *
 *   semesters.json       {"locations": [names], "semesters": [{season, year,
 *                         start, end, dir, pricing}, ...]}
 *   general/admins.csv   First Name, Last Name, Email, Cell Phone Number
 *
 * It holds real families' details, so it is never in git and never in the web
 * root; REAL_DATA_DIR in config.local.php says where it is on this machine,
 * and without that constant the loader does not exist (no fallback path).
 *
 * The reset is SampleDataLoader's, so the same things are deleted (semesters,
 * lessons, the ledger, leads, every person) and kept (settings,
 * announcements, email templates, logs). Accounts that survive untouched,
 * password included: developers, the admin running the load, and everyone
 * named in general/admins.csv. Admins in that file who have no account get one
 * with no password — NO email is sent; invite them from Admin > Users.
 *
 * Every file goes through the same importers as the setup wizard. No email is
 * sent by any step, the schedule import posts no charges, and public
 * registration is left closed if it pointed at a semester the reset removed.
 */
class RealDataLoader {

    /** Absolute path from REAL_DATA_DIR, or '' when unset or not a directory. */
    public static function dataDir(): string {
        $dir = defined('REAL_DATA_DIR') ? rtrim(trim((string)REAL_DATA_DIR), '/') : '';
        return $dir !== '' && is_dir($dir) ? $dir : '';
    }

    /** True when REAL_DATA_DIR points at a directory with a semesters.json. */
    public static function isAvailable(): bool {
        $dir = self::dataDir();
        return $dir !== '' && is_file($dir . '/semesters.json');
    }

    /** What the directory would load, for the confirmation card: counts only, never names. */
    public static function describe(string $root): array {
        $manifest = self::manifest($root);
        $count = static function (string $relPath) use ($root): ?int {
            $path = $root . '/' . $relPath;
            if (!is_file($path)) {
                return null;
            }
            return count(CsvImport::parseCsv((string)file_get_contents($path), ',')['rows']);
        };
        $semesters = [];
        foreach ($manifest['semesters'] as $spec) {
            $semesters[] = [
                'label' => ucfirst((string)$spec['season']) . ' ' . (int)$spec['year'],
                'start' => (string)$spec['start'],
                'end' => (string)$spec['end'],
                'slots' => $count($spec['dir'] . '/semester_location_reservations.csv'),
                'ledger_rows' => $count($spec['dir'] . '/ledger_entries.csv'),
            ];
        }
        return [
            'built_at' => date('Y-m-d H:i', (int)filemtime($root . '/semesters.json')),
            'locations' => $count('general/locations.csv'),
            'teachers' => $count('general/teachers.csv'),
            'people' => $count('general/people.csv'),
            'admins' => array_column(self::adminRows($root), 'name'),
            'semesters' => $semesters,
        ];
    }

    /**
     * Load $root. With $reset (the launch path) everything is wiped first;
     * without it the files are applied on top of what is there, and semesters
     * that already exist keep their dates and pricing. Returns the same shape
     * as SampleDataLoader::load(): ['steps' => [...], 'semesters' => [ids]].
     * Throws on the first file with an invalid row; earlier steps stay.
     */
    public static function load(?UserContext $ctx, string $root, bool $reset = true): array {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        $root = rtrim($root, '/');
        $manifest = self::manifest($root);
        $admins = self::adminRows($root);

        $steps = [];
        if ($reset) {
            $step = SampleDataLoader::reset($ctx, array_column($admins, 'email'));
            $step['label'] = 'Reset';
            $steps[] = $step;
        }
        $steps[] = SampleDataLoader::importFile($ctx, $root, 'general/locations.csv', LocationCsvImport::class);
        $steps[] = SampleDataLoader::importFile($ctx, $root, 'general/teachers.csv', TeacherCsvImport::class);
        $steps[] = SampleDataLoader::importFile($ctx, $root, 'general/people.csv', PeopleCsvImport::class);
        $steps[] = self::ensureAdmins($ctx, $admins);

        $locationIds = [];
        foreach (LocationManagement::all(true) as $location) {
            if (in_array((string)$location['name'], $manifest['locations'], true)) {
                $locationIds[] = (int)$location['id'];
            }
        }
        if (count($locationIds) !== count($manifest['locations'])) {
            throw new RuntimeException('semesters.json names locations that general/locations.csv did not create.');
        }

        $semesterIds = [];
        foreach ($manifest['semesters'] as $spec) {
            $label = ucfirst((string)$spec['season']) . ' ' . (int)$spec['year'] . ' semester';
            $existing = SampleDataLoader::findSemester((string)$spec['season'], (int)$spec['year']);
            if ($existing) {
                $semesterId = (int)$existing['id'];
                $steps[] = ['label' => $label, 'created' => 0, 'updated' => 0, 'skipped' => 1];
            } else {
                $semesterId = SemesterManagement::createSemester(
                    $ctx, (string)$spec['season'], (int)$spec['year'], (string)$spec['start'], (string)$spec['end'],
                    (array)$spec['pricing'],
                    SemesterManagement::midpointDate((string)$spec['start'], (string)$spec['end'])
                );
                $steps[] = ['label' => $label, 'created' => 1, 'updated' => 0, 'skipped' => 0];
            }
            SemesterManagement::setActiveLocations($ctx, $semesterId, $locationIds);
            foreach (SampleDataLoader::importSemesterFiles($ctx, $root, $semesterId, (string)$spec['dir']) as $step) {
                $steps[] = $step;
            }
            $semesterIds[] = $semesterId;
        }

        // The reset removes the semester public registration pointed at.
        // Opening registration is a deliberate step (Admin > Settings).
        $openFor = (int)Settings::get('registration_semester_id', '');
        if ($openFor > 0 && !SemesterManagement::find($openFor)) {
            Settings::set('registration_semester_id', '');
        }

        ActivityLog::log($ctx, 'real_data.loaded', [
            'reset' => $reset,
            'semester_ids' => $semesterIds,
            'steps' => count($steps),
        ]);
        return ['steps' => $steps, 'semesters' => $semesterIds];
    }

    /** One line for the flash message. */
    public static function summarize(array $result): string {
        return preg_replace('/^Sample data loaded\./', 'Real data loaded.', SampleDataLoader::summarize($result));
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** semesters.json, validated enough that a bad file fails before anything is deleted. */
    private static function manifest(string $root): array {
        $path = $root . '/semesters.json';
        $manifest = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($manifest) || empty($manifest['locations']) || empty($manifest['semesters'])) {
            throw new RuntimeException('semesters.json is missing or invalid in the real-data directory.');
        }
        foreach ((array)$manifest['semesters'] as $spec) {
            foreach (['season', 'year', 'start', 'end', 'dir', 'pricing'] as $key) {
                if (empty($spec[$key])) {
                    throw new RuntimeException("semesters.json: a semester is missing \"$key\".");
                }
            }
            if (!is_dir($root . '/' . $spec['dir'])) {
                throw new RuntimeException('semesters.json names a directory that does not exist: ' . $spec['dir']);
            }
        }
        return [
            'locations' => array_values(array_map('strval', (array)$manifest['locations'])),
            'semesters' => array_values((array)$manifest['semesters']),
        ];
    }

    /** general/admins.csv as [['first','last','name','email','cell_phone'], ...]; [] when the file is absent. */
    private static function adminRows(string $root): array {
        $path = $root . '/general/admins.csv';
        if (!is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (CsvImport::parseCsv((string)file_get_contents($path), ',')['rows'] as $i => $row) {
            [$first, $last, $email, $phone] = array_pad(array_map('trim', array_values($row)), 4, '');
            if ($first === '' && $last === '' && $email === '') {
                continue;
            }
            if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('general/admins.csv row ' . ($i + 2) . ' needs a first name, last name and valid email.');
            }
            $rows[] = ['first' => $first, 'last' => $last, 'name' => "$first $last", 'email' => strtolower($email), 'cell_phone' => $phone];
        }
        return $rows;
    }

    /**
     * Make sure each listed admin has an account with the admin flag. An
     * existing account keeps its name, password and contact details; a new
     * one is created without a password and without sending anything.
     */
    private static function ensureAdmins(UserContext $ctx, array $admins): array {
        $created = $kept = 0;
        foreach ($admins as $admin) {
            $existing = UserManagement::findByEmailAnyState($admin['email']);
            if ($existing) {
                if (empty($existing['is_admin']) || !empty($existing['is_deleted'])) {
                    pdo()->prepare('UPDATE users SET is_deleted = 0 WHERE id = ?')->execute([(int)$existing['id']]);
                    UserManagement::setAdminFlag($ctx, (int)$existing['id'], true);
                }
                $kept++;
                continue;
            }
            $id = UserManagement::findOrCreateByEmail($ctx, $admin['first'], $admin['last'], $admin['email']);
            UserManagement::setAdminFlag($ctx, $id, true);
            if ($admin['cell_phone'] !== '') {
                UserManagement::updateProfile($ctx, $id, ['cell_phone' => $admin['cell_phone']]);
            }
            $created++;
        }
        return ['label' => 'general/admins.csv', 'created' => $created, 'updated' => 0, 'skipped' => $kept];
    }
}
