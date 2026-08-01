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
    }

    private function importSemesterFiles(int $semesterId, string $dir): void
    {
        $context = ['semester_id' => $semesterId];
        $this->import($dir . '/location_dates.csv', LocationDatesCsvImport::class, $context);
        $this->import($dir . '/location_teachers.csv', LocationTeachersCsvImport::class, $context);
        $this->import($dir . '/hold_blocks.csv', HoldBlocksCsvImport::class, $context);
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
