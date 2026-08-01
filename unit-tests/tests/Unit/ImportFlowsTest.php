<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ImportFlowsTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    // ── Teachers ───────────────────────────────────────────────────────────

    public function testTeacherImportCreatesUpdatesAndFlagsErrors(): void
    {
        $existing = fx_user('Elena', 'Existing', ['email' => 'elena@example.org']);

        $rows = [
            ['first_name' => 'Nina', 'last_name' => 'New', 'email' => 'nina@example.org', 'cell_phone' => '718-555-0001'],
            ['first_name' => 'Elena', 'last_name' => 'Existing', 'email' => 'elena@example.org'],
            ['first_name' => 'No', 'last_name' => 'Contact'],                            // error: no email/phone
            ['first_name' => 'Dup', 'last_name' => 'Email', 'email' => 'nina@example.org'], // error: dup within file
        ];
        $validated = TeacherCsvImport::validateRows($rows);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertSame('Create teacher', $validated[0]['changes']);
        $this->assertSame('valid', $validated[1]['status']);
        $this->assertStringContainsString('Add teacher profile to existing user', $validated[1]['changes']);
        $this->assertSame('error', $validated[2]['status']);
        $this->assertSame('error', $validated[3]['status']);

        $summary = TeacherCsvImport::commit($this->ctx, $validated);
        $this->assertSame(['created' => 1, 'updated' => 1, 'skipped' => 2], $summary);

        // Both are teachers now; the existing user kept their id.
        $teacherIds = array_map('intval', array_column(pdo()->query('SELECT user_id FROM teacher_profiles')->fetchAll(), 'user_id'));
        $this->assertContains($existing, $teacherIds);
        $this->assertCount(2, $teacherIds);

        $nina = UserManagement::findAuthByEmail('nina@example.org');
        $this->assertSame('718-555-0001', $nina['cell_phone']);
    }

    public function testTeacherImportMatchesByPhoneWhenNoEmail(): void
    {
        fx_user('Phil', 'Phone', ['email' => 'phil@example.org']);
        pdo()->exec("UPDATE users SET cell_phone='(718) 555-0002' WHERE email='phil@example.org'");

        $validated = TeacherCsvImport::validateRows([
            ['first_name' => 'Phil', 'last_name' => 'Phone', 'cell_phone' => '17185550002'],
        ]);
        $this->assertSame('valid', $validated[0]['status']);
        $this->assertStringContainsString('existing user (Phil Phone)', $validated[0]['changes']);
    }

    // ── Location dates ─────────────────────────────────────────────────────

    public function testLocationDatesImportUpsertsAndResyncsLessons(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId, , $dayOfWeek, $dates] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2);
        $reservationId = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
            'student_user_id' => fx_student(), 'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
            'status' => 'confirmed',
        ]);
        $locationName = (string)pdo()->query("SELECT name FROM locations WHERE id=$locationId")->fetchColumn();

        $validated = LocationDatesCsvImport::validateRows([
            // New Saturday (added) + existing date flipped inactive (updated) + bad location (error).
            ['location_name' => $locationName, 'date' => '9/21/2030', 'start_time' => '9:00 am', 'end_time' => '5:00 pm', 'status' => '', 'notes' => 'Day 3'],
            ['location_name' => strtoupper($locationName), 'date' => '2030-09-14', 'start_time' => '9:00', 'end_time' => '17:00', 'status' => 'inactive', 'notes' => 'Holiday Week'],
            ['location_name' => 'Narnia', 'date' => '2030-09-28', 'start_time' => '9:00', 'end_time' => '17:00', 'status' => 'active', 'notes' => ''],
        ], ['semester_id' => $semesterId]);

        $this->assertSame(['valid', 'valid', 'error'], array_column($validated, 'status'));
        $this->assertSame('Add date', $validated[0]['changes']);
        $this->assertSame('Update existing date', $validated[1]['changes']);
        $this->assertStringContainsString('No match found', $validated[2]['messages'][0]);

        $summary = LocationDatesCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId]);
        $this->assertSame(['created' => 1, 'updated' => 1, 'skipped' => 1], $summary);

        // The confirmed reservation resynced: 09-07 (kept), 09-21 (new);
        // 09-14 (now a holiday) is gone.
        $st = pdo()->prepare('SELECT DATE(start_datetime) AS d FROM lessons WHERE semester_lesson_reservation_id=? ORDER BY start_datetime');
        $st->execute([$reservationId]);
        $this->assertSame(['2030-09-07', '2030-09-21'], array_column($st->fetchAll(), 'd'));
    }

    // ── Location teachers ──────────────────────────────────────────────────

    public function testLocationTeachersImportMatchesByFullName(): void
    {
        $teacher = fx_teacher('Maya', 'Cello');
        fx_teacher('Maya', 'Cello'); // an ambiguous twin
        $unique = fx_teacher('Omar', 'Violin');
        $ctx = $this->ctx;
        $semesterId = fx_semester($ctx);
        $locationId = fx_location_id();
        SemesterManagement::setActiveLocations($ctx, $semesterId, [$locationId]);
        $locationName = (string)pdo()->query("SELECT name FROM locations WHERE id=$locationId")->fetchColumn();

        $validated = LocationTeachersCsvImport::validateRows([
            ['teacher_name' => 'omar violin', 'location_name' => $locationName],
            ['teacher_name' => 'Maya Cello', 'location_name' => $locationName],   // ambiguous
            ['teacher_name' => 'Zoe Zither', 'location_name' => $locationName],   // no match
        ], ['semester_id' => $semesterId]);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertSame('error', $validated[1]['status']);
        $this->assertStringContainsString('Multiple teachers', $validated[1]['messages'][0]);
        $this->assertSame('error', $validated[2]['status']);
        $this->assertStringContainsString('upload teachers first', $validated[2]['messages'][0]);

        $summary = LocationTeachersCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId]);
        $this->assertSame(1, $summary['created']);
        $this->assertTrue(SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $unique));
        $this->assertFalse(SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $teacher));

        // Re-importing the same pair is a no-op.
        $again = LocationTeachersCsvImport::validateRows([
            ['teacher_name' => 'Omar Violin', 'location_name' => $locationName],
        ], ['semester_id' => $semesterId]);
        $this->assertSame('Already assigned (no change)', $again[0]['changes']);
    }

    public function testTimeAndDateParsing(): void
    {
        $this->assertSame('09:00:00', LocationDatesCsvImport::parseTime('9:00 am'));
        $this->assertSame('16:30:00', LocationDatesCsvImport::parseTime('4:30 PM'));
        $this->assertSame('14:30:00', LocationDatesCsvImport::parseTime('14:30'));
        $this->assertSame('00:15:00', LocationDatesCsvImport::parseTime('12:15 am'));
        $this->assertNull(LocationDatesCsvImport::parseTime('25:00'));
        $this->assertSame('2030-09-21', LocationDatesCsvImport::parseDate('9/21/2030'));
        $this->assertSame('2030-09-21', LocationDatesCsvImport::parseDate('2030-09-21'));
        $this->assertNull(LocationDatesCsvImport::parseDate('2030-02-31'));
    }
}
