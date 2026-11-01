<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The one-click dev loader must reproduce exactly what SampleDataTest walks
 * through by hand — and be safe to click twice.
 */
final class SampleDataLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    public function testOnlyDevHostsQualify(): void
    {
        $this->assertTrue(SampleDataLoader::isDevHost('dev-bronxconservatory.lillyrosenthal.org'));
        $this->assertTrue(SampleDataLoader::isDevHost('DEV.example.org:8080'));
        $this->assertFalse(SampleDataLoader::isDevHost('portal.bronxconservatory.org'));
        $this->assertFalse(SampleDataLoader::isDevHost('localhost:8080'));
        $this->assertFalse(SampleDataLoader::isDevHost(''));
    }

    public function testRefusesNonAdmins(): void
    {
        $this->expectException(RuntimeException::class);
        SampleDataLoader::load(new UserContext(fx_user('Plain', 'Parent'), false));
    }

    public function testLoadsEverythingInOrderAndIsIdempotent(): void
    {
        $ctx = fx_admin_ctx();
        $result = SampleDataLoader::load($ctx);

        $labels = array_column($result['steps'], 'label');
        $this->assertSame([
            'general/locations.csv',
            'general/teachers.csv',
            'general/people.csv',
            'Fall 2026 semester',
            'fall_semester/location_weekdays.csv',
            'fall_semester/location_dates.csv',
            'fall_semester/location_teachers.csv',
            'fall_semester/hold_blocks.csv',
            'fall_semester/semester_location_reservations.csv',
            'fall_semester/ledger_entries.csv',
            'Spring 2027 semester',
            'spring_semester/location_weekdays.csv',
            'spring_semester/location_dates.csv',
            'spring_semester/location_teachers.csv',
            'spring_semester/hold_blocks.csv',
            'Pre-populate Spring 2027 from Fall 2026',
        ], $labels);

        $fall = (int)$result['semesters']['fall_semester_id'];
        $spring = (int)$result['semesters']['spring_semester_id'];
        $this->assertSame('fall', SemesterManagement::find($fall)['season']);
        $this->assertSame('spring', SemesterManagement::find($spring)['season']);
        $this->assertSame(3500, SemesterManagement::registrationFeeCents(SemesterManagement::find($fall)));

        // The same end state SampleDataTest asserts in detail.
        $this->assertCount(17, ReservationManagement::reservationsForSemester($fall));
        $this->assertCount(17, ReservationManagement::reservationsForSemester($spring));
        $this->assertSame(42, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertCount(8, HoldBlockManagement::holdBlockReservationsForSemester($fall));
        $this->assertCount(9, HoldBlockManagement::holdBlockReservationsForSemester($spring));
        $this->assertStringStartsWith('Sample data loaded. general/locations.csv: 2 created', SampleDataLoader::summarize($result));

        // A second click changes nothing: semesters reused, every row skipped.
        $again = SampleDataLoader::load($ctx);
        $this->assertSame(['fall_semester_id' => $fall, 'spring_semester_id' => $spring], $again['semesters']);
        $this->assertSame(0, array_sum(array_column($again['steps'], 'created')));
        $this->assertCount(2, SemesterManagement::listSemesters());
        $this->assertCount(17, ReservationManagement::reservationsForSemester($fall));
        $this->assertCount(17, ReservationManagement::reservationsForSemester($spring));
        $this->assertSame(42, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertCount(8, HoldBlockManagement::holdBlockReservationsForSemester($fall));
    }
}
