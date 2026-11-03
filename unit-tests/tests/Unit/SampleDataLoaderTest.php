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

    public function testFindsTheRepositorySampleData(): void
    {
        // Test config defines no SAMPLE_DATA_DIR, so the checkout layout applies.
        $this->assertSame(realpath(__DIR__ . '/../../../sample_data'), realpath(SampleDataLoader::sampleDataDir()));
    }

    public function testRefusesNonAdmins(): void
    {
        $this->expectException(RuntimeException::class);
        SampleDataLoader::load(new UserContext(fx_user('Plain', 'Parent'), false));
    }

    public function testLoadsEverythingInOrderAndResetsOnRerun(): void
    {
        $ctx = fx_admin_ctx();
        // Leftovers from a demo: a stray family and a developer account. The
        // load clears the former and keeps the latter (and the admin running it).
        $stray = fx_student('Stray', 'Student');
        $developer = fx_user('Dev', 'Eloper', ['is_admin' => true]);
        pdo()->exec("UPDATE users SET is_developer = 1 WHERE id = $developer");

        $result = SampleDataLoader::load($ctx);

        $labels = array_column($result['steps'], 'label');
        $this->assertSame([
            'Reset sample tables',
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
            'fall_semester/leads.json',
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

        // The stray family is gone; the developer and the acting admin survive.
        $this->assertNull(UserManagement::findById($stray));
        $this->assertNotNull(UserManagement::findById($developer));
        $this->assertNotNull(UserManagement::findById($ctx->id));

        // Leads: five registrations quoted against fall, three info requests,
        // three uncompleted forms — every status short of converted.
        $this->assertSame(8, LeadManagement::countLeads([]));
        $this->assertSame(
            ['contacted' => 2, 'declined' => 1, 'new' => 3, 'scheduled' => 2],
            array_filter($this->countValues(LeadManagement::statusCounts([])))
        );
        $this->assertSame(5, LeadManagement::countLeads(['source' => 'registration']));
        $this->assertSame(3, LeadManagement::countLeads(['source' => 'inquiry']));
        $this->assertSame(2, (int)pdo()->query('SELECT COUNT(*) FROM leads WHERE amount_paid_cents > 0 AND paid_at IS NOT NULL')->fetchColumn());
        $this->assertSame(6, (int)pdo()->query('SELECT COUNT(*) FROM lead_notes')->fetchColumn());
        $this->assertSame(3, (int)pdo()->query('SELECT COUNT(*) FROM incomplete_inquiries')->fetchColumn());
        $this->assertSame(1, (int)pdo()->query("SELECT COUNT(*) FROM incomplete_inquiries WHERE source = 'registration' AND last_step_completed = 4")->fetchColumn());
        $this->assertSame((string)$fall, Settings::get('registration_semester_id', ''));
        $summary = SampleDataLoader::summarize($result);
        $this->assertStringStartsWith('Sample data loaded. Reset sample tables: 1 deleted; general/locations.csv: 2 ', $summary); // locations are seeded by schema.sql, so updated not created
        $this->assertStringContainsString('general/teachers.csv: 6 created', $summary);
        $this->assertStringContainsString('Pre-populate Spring 2027 from Fall 2026: 17 created', $summary);

        // A second click starts over: the first load's rows are cleared, so
        // the end state is the same fresh demo, not a doubled one.
        $again = SampleDataLoader::load($ctx);
        $fall2 = (int)$again['semesters']['fall_semester_id'];
        $spring2 = (int)$again['semesters']['spring_semester_id'];
        $this->assertNotSame($fall, $fall2);
        $this->assertCount(2, SemesterManagement::listSemesters());
        $this->assertNull(SemesterManagement::find($fall));
        $this->assertCount(17, ReservationManagement::reservationsForSemester($fall2));
        $this->assertCount(17, ReservationManagement::reservationsForSemester($spring2));
        $this->assertSame(42, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertCount(8, HoldBlockManagement::holdBlockReservationsForSemester($fall2));
        $this->assertSame(8, LeadManagement::countLeads([]));
        $this->assertSame(3, (int)pdo()->query('SELECT COUNT(*) FROM incomplete_inquiries')->fetchColumn());
        // 33 students and parents + 6 teachers, exactly once.
        $this->assertSame(39, (int)pdo()->query('SELECT COUNT(*) FROM users WHERE is_developer = 0 AND id <> ' . $ctx->id)->fetchColumn());
        $this->assertNotNull(UserManagement::findById($developer));
    }

    /** @return array<string,int> value => count, in value order */
    private function countValues(array $values): array
    {
        ksort($values);
        return $values;
    }
}
