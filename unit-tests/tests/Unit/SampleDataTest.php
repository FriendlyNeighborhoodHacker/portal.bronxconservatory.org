<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Walks the sample_data/ CSVs through the real importers in the order
 * sample_data/README.md prescribes: general/ once, then a semester's three
 * files for each of fall and spring. Every row must validate — these files
 * are what a new deployment is set up with, so a stale column name or a date
 * that drifted outside its semester should fail here, not during a demo.
 */
final class SampleDataTest extends TestCase
{
    private UserContext $ctx;
    private string $root;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
        $this->root = __DIR__ . '/../../../sample_data';
    }

    public function testGeneralAndBothSemestersImportCleanly(): void
    {
        $this->import('general/locations.csv', LocationCsvImport::class);
        $this->import('general/teachers.csv', TeacherCsvImport::class);
        $this->import('general/people.csv', PeopleCsvImport::class);

        // By name, not "every active location": test_reset_all() keeps the
        // locations table, so earlier tests' locations may still be around.
        $locationIds = array_map('intval', array_column(pdo()->query(
            "SELECT id FROM locations
             WHERE is_active=1
               AND name IN ('Access Bronx Charter School', 'Bronx Community College')"
        )->fetchAll(), 'id'));
        $this->assertCount(2, $locationIds);

        $fall = SemesterManagement::createSemester($this->ctx, 'fall', 2026, '2026-09-08', '2026-12-20');
        SemesterManagement::setActiveLocations($this->ctx, $fall, $locationIds);
        $this->importSemesterFiles($fall, 'fall_semester');

        // The point of the split: spring reuses general/ untouched.
        $spring = SemesterManagement::createSemester($this->ctx, 'spring', 2027, '2027-01-25', '2027-05-23');
        SemesterManagement::setActiveLocations($this->ctx, $spring, $locationIds);
        $this->importSemesterFiles($spring, 'spring_semester');

        $this->assertCount(30, SemesterManagement::locationDates($fall));   // 15 Saturdays x 2 locations
        $this->assertCount(34, SemesterManagement::locationDates($spring)); // 17 Saturdays x 2 locations
        $this->assertCount(7, SemesterManagement::locationTeachers($fall));
        $this->assertCount(8, SemesterManagement::locationTeachers($spring));
        // One lunch per teacher — Okafor and Lin span both locations but can
        // only be in one place — plus Vega's spring faculty meeting.
        $this->assertCount(6, HoldBlockManagement::holdBlockReservationsForSemester($fall));
        $this->assertCount(7, HoldBlockManagement::holdBlockReservationsForSemester($spring));

        // Fall's schedule arrives by CSV: every student gets exactly one slot,
        // and loading it bills nobody.
        $fallReservations = ReservationManagement::reservationsForSemester($fall);
        $this->assertCount(14, $fallReservations);
        $this->assertCount(14, array_unique(array_column($fallReservations, 'student_user_id')));
        $this->assertSame(
            ['confirmed' => 10, 'pending_confirmation' => 2, 'pending_reach_out' => 2], // ksorted
            $this->countByStatus($fallReservations)
        );
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());

        // Spring's schedule arrives the other way: carried forward from fall,
        // all pending reach out, nothing materialized.
        $this->assertSame(
            ['created' => 14, 'skipped' => 0],
            ReservationManagement::carryForwardFromSemester($this->ctx, $spring, $fall)
        );
        $springReservations = ReservationManagement::reservationsForSemester($spring);
        $this->assertSame(['pending_reach_out' => 14], $this->countByStatus($springReservations));
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $ids = implode(',', array_map('intval', array_column($springReservations, 'id')));
        $this->assertSame(0, (int)pdo()->query(
            "SELECT COUNT(*) FROM lessons WHERE semester_lesson_reservation_id IN ($ids)"
        )->fetchColumn());
    }

    /** @return array<string,int> status => count, in status-name order */
    private function countByStatus(array $reservations): array
    {
        $counts = array_count_values(array_column($reservations, 'status'));
        ksort($counts);
        return $counts;
    }

    private function importSemesterFiles(int $semesterId, string $dir): void
    {
        $context = ['semester_id' => $semesterId];
        $this->import($dir . '/location_dates.csv', LocationDatesCsvImport::class, $context);
        $this->import($dir . '/location_teachers.csv', LocationTeachersCsvImport::class, $context);
        $this->import($dir . '/hold_blocks.csv', HoldBlocksCsvImport::class, $context);
        if (is_file($this->root . '/' . $dir . '/semester_location_reservations.csv')) {
            $this->import($dir . '/semester_location_reservations.csv', SemesterReservationsCsvImport::class, $context);
        }
    }

    /** Parse, auto-map the headers, assert every row validates, then commit. */
    private function import(string $relPath, string $importer, array $context = []): void
    {
        $path = $this->root . '/' . $relPath;
        $this->assertFileExists($path);
        $parsed = CsvImport::parseCsv((string)file_get_contents($path), ',');
        $mapping = CsvImport::suggestColumnMapping($parsed['headers'], $importer::targetFields());
        $rows = CsvImport::applyMapping($parsed['rows'], $mapping);

        $validated = $importer::validateRows($rows, $context);
        foreach ($validated as $entry) {
            $this->assertSame(
                'valid',
                $entry['status'],
                $relPath . ' row ' . $entry['row'] . ': ' . implode(' ', $entry['messages'])
            );
        }
        $importer::commit($this->ctx, $validated, $context);
    }
}
