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

    // ── Locations ──────────────────────────────────────────────────────────

    public function testLocationImportCreatesUpdatesAndFlagsErrors(): void
    {
        // "Bronx Community College" is seeded by schema.sql.
        $rows = [
            ['name' => 'bronx community college', 'address' => '2155 University Ave, Bronx, NY 10453'],
            ['name' => 'St. Ann\'s Church', 'address' => '295 St Ann\'s Ave, Bronx, NY 10454'],
            ['name' => 'Old Annex', 'status' => 'inactive'],
            ['name' => '', 'address' => 'No name'],                     // error
            ['name' => 'St. Ann\'s Church'],                            // error: duplicate in file
            ['name' => 'Somewhere', 'status' => 'maybe'],               // error: bad status
        ];
        $validated = LocationCsvImport::validateRows($rows);
        $this->assertSame(['valid', 'valid', 'valid', 'error', 'error', 'error'],
            array_column($validated, 'status'));
        $this->assertStringContainsString('Update existing location (Bronx Community College)', $validated[0]['changes']);
        $this->assertSame('Create location', $validated[1]['changes']);

        $summary = LocationCsvImport::commit($this->ctx, $validated);
        $this->assertSame(['created' => 2, 'updated' => 1, 'skipped' => 3], $summary);

        $all = LocationManagement::all();
        $byName = array_column($all, null, 'name');
        $this->assertSame('2155 University Ave, Bronx, NY 10453', $byName['Bronx Community College']['address']);
        $this->assertSame(0, (int)$byName['Old Annex']['is_active']);
        $this->assertSame(1, (int)$byName["St. Ann's Church"]['is_active']);

        // Re-import: everything matches, nothing duplicates.
        $again = LocationCsvImport::validateRows([['name' => "St. Ann's Church"]]);
        $this->assertStringContainsString('Update existing location', $again[0]['changes']);
        LocationCsvImport::commit($this->ctx, $again);
        $this->assertCount(count($all), LocationManagement::all());
    }

    // ── Students & parents (one file) ─────────────────────────────────────

    public function testPeopleImportBuildsFamiliesFromOneFile(): void
    {
        // Denise already exists in the system as a parent of another child.
        $denise = fx_user('Denise', 'Brown', ['email' => 'denise@example.org']);
        $existingKid = fx_student('Solo', 'Kid');
        pdo()->exec("INSERT INTO parenthood (parent_user_id, child_user_id) VALUES ($denise, $existingKid)");

        $rows = [
            // A parent row (referenced below by name and by email).
            ['first_name' => 'Rosa', 'last_name' => 'Ramos', 'email' => 'rosa@example.org',
             'address_street_1' => '1188 Elder Ave'],
            // Students referencing the in-file parent two different ways.
            ['first_name' => 'Lucia', 'last_name' => 'Ramos', 'class_of' => '2031',
             'instruments' => 'Piano', 'parents' => 'Rosa Ramos'],
            ['first_name' => 'Marco', 'last_name' => 'Ramos', 'class_of' => '2033',
             'instruments' => 'Violin', 'parents' => 'rosa@example.org'],
            // A student referencing an EXISTING person by email.
            ['first_name' => 'Devon', 'last_name' => 'Brown', 'grade' => '9',
             'instruments' => 'Violin; Viola', 'parents' => 'denise@example.org'],
            // Errors: unknown parent, unknown instrument.
            ['first_name' => 'Lost', 'last_name' => 'Kid', 'parents' => 'Ghost Adult'],
            ['first_name' => 'Bad', 'last_name' => 'Axe', 'instruments' => 'Theremin'],
        ];
        $validated = PeopleCsvImport::validateRows($rows);

        $this->assertSame(['valid', 'valid', 'valid', 'valid', 'error', 'error'],
            array_column($validated, 'status'));
        $this->assertStringContainsString('Create parent', $validated[0]['changes']);
        $this->assertStringContainsString('Create student; link parent Rosa Ramos', $validated[1]['changes']);
        $this->assertStringContainsString('link parent Rosa Ramos', $validated[2]['changes']);
        $this->assertStringContainsString('link parent Denise Brown', $validated[3]['changes']);
        $this->assertStringContainsString('not found', $validated[4]['messages'][0]);
        $this->assertStringContainsString('Unknown instrument', $validated[5]['messages'][0]);

        $summary = PeopleCsvImport::commit($this->ctx, $validated);
        $this->assertSame(['created' => 4, 'updated' => 0, 'skipped' => 2], $summary);

        // One Rosa, linked to both siblings; Devon linked to existing Denise.
        $rosaIds = array_column(pdo()->query("SELECT id FROM users WHERE email='rosa@example.org'")->fetchAll(), 'id');
        $this->assertCount(1, $rosaIds);
        $rosa = (int)$rosaIds[0];
        $lucia = (int)pdo()->query("SELECT id FROM users WHERE first_name='Lucia'")->fetchColumn();
        $marco = (int)pdo()->query("SELECT id FROM users WHERE first_name='Marco'")->fetchColumn();
        $devon = (int)pdo()->query("SELECT id FROM users WHERE first_name='Devon'")->fetchColumn();
        $this->assertTrue(StudentTeacherManagement::isParentOf($rosa, $lucia));
        $this->assertTrue(StudentTeacherManagement::isParentOf($rosa, $marco));
        $this->assertTrue(StudentTeacherManagement::isParentOf($denise, $devon));

        // Roles fell out of the references: Rosa is only a parent (no student
        // profile despite being in the same file); Lucia is only a student.
        $this->assertSame(['parent'], Application::rolesForUser($rosa));
        $this->assertSame(['student'], Application::rolesForUser($lucia));
        $this->assertSame(['Violin', 'Viola'], InstrumentCatalog::namesForStudent($devon));
        $this->assertSame('1188 Elder Ave', UserManagement::findById($rosa)['address_street_1']);
    }

    public function testPeopleImportReferencedRowWithStudentFieldsIsBoth(): void
    {
        // An adult student who is also their child\'s parent.
        $validated = PeopleCsvImport::validateRows([
            ['first_name' => 'Ana', 'last_name' => 'Adult', 'email' => 'ana@example.org', 'instruments' => 'Voice'],
            ['first_name' => 'Nina', 'last_name' => 'Adult', 'class_of' => '2032', 'parents' => 'Ana Adult'],
        ]);
        $this->assertStringContainsString('Create parent + student', $validated[0]['changes']);

        PeopleCsvImport::commit($this->ctx, $validated);
        $ana = (int)pdo()->query("SELECT id FROM users WHERE email='ana@example.org'")->fetchColumn();
        Application::clearRolesCacheForTesting();
        $this->assertSame(['parent', 'student'], Application::rolesForUser($ana));
    }

    public function testPeopleImportIsIdempotentAndGuardsAmbiguity(): void
    {
        // Existing student matched by exact name (no email/phone).
        $lucia = fx_student('Lucia', 'Ramos');

        $rows = [
            ['first_name' => 'Rosa', 'last_name' => 'Ramos', 'email' => 'rosa@example.org'],
            ['first_name' => 'Lucia', 'last_name' => 'Ramos', 'class_of' => '2031', 'parents' => 'Rosa Ramos'],
        ];
        $validated = PeopleCsvImport::validateRows($rows);
        $this->assertStringContainsString('Update existing person (Lucia Ramos) as student', $validated[1]['changes']);
        PeopleCsvImport::commit($this->ctx, $validated);

        // Re-importing the same file updates instead of duplicating.
        $again = PeopleCsvImport::validateRows($rows);
        PeopleCsvImport::commit($this->ctx, $again);
        $this->assertSame(1, (int)pdo()->query("SELECT COUNT(*) FROM users WHERE email='rosa@example.org'")->fetchColumn());
        $this->assertSame(1, (int)pdo()->query("SELECT COUNT(*) FROM parenthood")->fetchColumn());
        $this->assertSame(2031, (int)pdo()->query("SELECT class_of FROM student_profiles WHERE user_id=$lucia")->fetchColumn());

        // Two in-file rows with the same name: referencing that name errors.
        $ambiguous = PeopleCsvImport::validateRows([
            ['first_name' => 'Twin', 'last_name' => 'Cruz'],
            ['first_name' => 'Twin', 'last_name' => 'Cruz'],
            ['first_name' => 'Kid', 'last_name' => 'Cruz', 'parents' => 'Twin Cruz'],
        ]);
        $this->assertSame('error', $ambiguous[2]['status']);
        $this->assertStringContainsString('use their emails instead', $ambiguous[2]['messages'][0]);
    }

    public function testPeopleImportErroredParentRowBlocksItsChildren(): void
    {
        $validated = PeopleCsvImport::validateRows([
            // Parent row is broken (bad email)...
            ['first_name' => 'Rosa', 'last_name' => 'Ramos', 'email' => 'not-an-email'],
            // ...so the child that references it cannot be linked safely.
            ['first_name' => 'Lucia', 'last_name' => 'Ramos', 'parents' => 'Rosa Ramos'],
        ]);
        $this->assertSame('error', $validated[0]['status']);
        $this->assertSame('error', $validated[1]['status']);
        $this->assertStringContainsString('has errors', $validated[1]['messages'][0]);

        // A person cannot be their own parent.
        $self = PeopleCsvImport::validateRows([
            ['first_name' => 'Loop', 'last_name' => 'Self', 'email' => 'loop@example.org', 'parents' => 'loop@example.org'],
        ]);
        $this->assertSame('error', $self[0]['status']);
        $this->assertStringContainsString('own parent', $self[0]['messages'][0]);
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
