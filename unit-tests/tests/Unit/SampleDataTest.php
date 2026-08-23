<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// reservation_cell_presentation(): the grid's colour rule, so this test can
// assert what an admin actually sees after the sample files are loaded.
require_once __DIR__ . '/../../../www/admin/schedule_grid.php';

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

        // The two locations by name — the day declarations differ per site.
        $access = (int)pdo()->query("SELECT id FROM locations WHERE name='Access Bronx Charter School'")->fetchColumn();
        $bcc = (int)pdo()->query("SELECT id FROM locations WHERE name='Bronx Community College'")->fetchColumn();

        $fall = SemesterManagement::createSemester($this->ctx, 'fall', 2026, '2026-09-08', '2026-12-20');
        SemesterManagement::setActiveLocations($this->ctx, $fall, $locationIds);
        $this->importSemesterFiles($fall, 'fall_semester');

        // The point of the split: spring reuses general/ untouched.
        $spring = SemesterManagement::createSemester($this->ctx, 'spring', 2027, '2027-01-25', '2027-05-23');
        SemesterManagement::setActiveLocations($this->ctx, $spring, $locationIds);
        $this->importSemesterFiles($spring, 'spring_semester');

        // The class-days CSV declared Access-Saturday and BCC-Saturday+Tuesday.
        $this->assertSame(
            [[6], [2, 6]],
            [
                array_map('intval', array_column(SemesterManagement::locationWeekdays($fall, $access), 'day_of_week')),
                array_map('intval', array_column(SemesterManagement::locationWeekdays($fall, $bcc), 'day_of_week')),
            ]
        );

        // Saturdays at both locations plus the BCC Tuesday-evening track.
        $this->assertCount(47, SemesterManagement::locationDates($fall));   // 16 Saturdays x 2 locations (incl. one inactive holiday week) + 15 Tuesdays
        $this->assertCount(51, SemesterManagement::locationDates($spring)); // 17 Saturdays x 2 locations + 17 Tuesdays (2 inactive each)
        // One column per (location, teacher) pair however many days they work…
        $this->assertCount(7, SemesterManagement::locationTeachers($fall));
        $this->assertCount(8, SemesterManagement::locationTeachers($spring));
        // …but only Baptiste and Lin work the Tuesday evenings.
        $this->assertSame(
            ['Andre Baptiste', 'Grace Lin'],
            array_map(
                fn(array $c) => trim($c['teacher_first_name'] . ' ' . $c['teacher_last_name']),
                SemesterManagement::locationTeachers($fall, 2)
            )
        );
        $this->assertCount(7, SemesterManagement::locationTeachers($fall, 6));
        // One lunch per teacher — Okafor and Lin span both locations but can
        // only be in one place — plus the two Tuesday dinners, plus Vega's
        // spring faculty meeting.
        $this->assertCount(8, HoldBlockManagement::holdBlockReservationsForSemester($fall));
        $this->assertCount(9, HoldBlockManagement::holdBlockReservationsForSemester($spring));

        // Fall's schedule arrives by CSV: every student gets exactly one slot.
        $fallReservations = ReservationManagement::reservationsForSemester($fall);
        $this->assertCount(17, $fallReservations);
        $this->assertCount(17, array_unique(array_column($fallReservations, 'student_user_id')));
        $this->assertSame(
            ['confirmed' => 12, 'pending_confirmation' => 3, 'pending_reach_out' => 2], // ksorted
            $this->countByStatus($fallReservations)
        );

        // Money arrives only from the ledger file — the schedule import bills
        // nobody — and lands on exactly the twelve confirmed students.
        $this->assertSame(42, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $balances = Billing::semesterBalancesByStudent(
            $fall, array_map('intval', array_column($fallReservations, 'student_user_id'))
        );
        $charged = array_filter($balances, fn(array $b) => $b['semester_debit_cents'] > 0);
        $this->assertCount(12, $charged);
        // Ten 30-minute students at 35 + 15 + 420; Angel at 60 min
        // (35 + 15 + 840); Devon at 60 min plus the $20 installment fee.
        $debits = array_map(fn(array $b) => $b['semester_debit_cents'], $charged);
        sort($debits);
        $this->assertSame(
            [47000, 47000, 47000, 47000, 47000, 47000, 47000, 47000, 47000, 47000, 89000, 91000],
            $debits
        );

        // What the grid actually colours: paid, half-paid, owing, and the
        // pending students who were never charged.
        $byStudent = [];
        foreach ($fallReservations as $reservation) {
            $studentId = (int)$reservation['student_user_id'];
            $byStudent[trim($reservation['student_first_name'] . ' ' . $reservation['student_last_name'])] =
                reservation_cell_presentation($reservation, $balances[$studentId] ?? null)['class'];
        }
        $this->assertSame('res-paid', $byStudent['Lucia Ramos']);
        $this->assertSame('res-paid', $byStudent['Marco Ramos']);
        $this->assertSame('res-balance-half', $byStudent['Devon Brown']);
        $this->assertSame('res-balance-full', $byStudent['Naomi Osei']);
        $this->assertSame('res-pending', $byStudent['Fatima Al-Sayed']);
        $this->assertSame('res-reach-out', $byStudent['Amara Diallo']);
        $this->assertSame('res-paid', $byStudent['Sofia Reyes']);
        $this->assertSame('res-paid', $byStudent['Diego Reyes']);
        $this->assertSame('res-pending', $byStudent['Jordan Charles']);
        $this->assertSame(
            ['res-balance-full' => 7, 'res-balance-half' => 1, 'res-paid' => 4,
             'res-pending' => 3, 'res-reach-out' => 2],
            $this->countValues($byStudent)
        );

        // Spring's schedule arrives the other way: carried forward from fall,
        // all pending reach out, nothing materialized.
        $this->assertSame(
            ['created' => 17, 'skipped' => 0],
            ReservationManagement::carryForwardFromSemester($this->ctx, $spring, $fall)
        );
        $springReservations = ReservationManagement::reservationsForSemester($spring);
        $this->assertSame(['pending_reach_out' => 17], $this->countByStatus($springReservations));
        // Fall's ledger is the only ledger: carrying forward charges nobody.
        $this->assertSame(42, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertSame(0, (int)pdo()->query(
            "SELECT COUNT(*) FROM ledger_entries WHERE semester_id = $spring"
        )->fetchColumn());
        $ids = implode(',', array_map('intval', array_column($springReservations, 'id')));
        $this->assertSame(0, (int)pdo()->query(
            "SELECT COUNT(*) FROM lessons WHERE semester_lesson_reservation_id IN ($ids)"
        )->fetchColumn());
    }

    /** @return array<string,int> status => count, in status-name order */
    private function countByStatus(array $reservations): array
    {
        return $this->countValues(array_column($reservations, 'status'));
    }

    /** @return array<string,int> value => count, in value order */
    private function countValues(array $values): array
    {
        $counts = array_count_values($values);
        ksort($counts);
        return $counts;
    }

    private function importSemesterFiles(int $semesterId, string $dir): void
    {
        $context = ['semester_id' => $semesterId];
        $this->import($dir . '/location_weekdays.csv', LocationWeekdaysCsvImport::class, $context);
        $this->import($dir . '/location_dates.csv', LocationDatesCsvImport::class, $context);
        $this->import($dir . '/location_teachers.csv', LocationTeachersCsvImport::class, $context);
        $this->import($dir . '/hold_blocks.csv', HoldBlocksCsvImport::class, $context);
        if (is_file($this->root . '/' . $dir . '/semester_location_reservations.csv')) {
            $this->import($dir . '/semester_location_reservations.csv', SemesterReservationsCsvImport::class, $context);
        }
        if (is_file($this->root . '/' . $dir . '/ledger_entries.csv')) {
            $this->import($dir . '/ledger_entries.csv', LedgerEntriesCsvImport::class, $context);
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
