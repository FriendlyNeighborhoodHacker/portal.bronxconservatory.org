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

    // ── Hold blocks ────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int,3:string} [semesterId, locationId, teacherId, locationName] */
    private function holdBlockSetup(): array
    {
        $teacher = fx_teacher('Marisol', 'Vega');
        // Saturdays, so day 6 matches the generated class dates.
        [$semesterId, $locationId] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 3);
        $locationName = (string)pdo()->query("SELECT name FROM locations WHERE id=$locationId")->fetchColumn();
        return [$semesterId, $locationId, $teacher, $locationName];
    }

    public function testHoldBlocksImportCreatesReservationsAndBlocks(): void
    {
        [$semesterId, $locationId, $teacher, $locationName] = $this->holdBlockSetup();

        $validated = HoldBlocksCsvImport::validateRows([
            ['teacher_name' => 'Marisol Vega', 'location_name' => $locationName,
             'day' => 'Saturday', 'start_time' => '12:00 pm', 'end_time' => '1:30 pm', 'title' => 'Lunch'],
        ], ['semester_id' => $semesterId]);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertStringContainsString('Hold Lunch for Marisol Vega on Saturdays', $validated[0]['changes']);

        $summary = HoldBlocksCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId]);
        $this->assertSame(1, $summary['created']);

        $holds = HoldBlockManagement::holdBlockReservationsForSemester($semesterId);
        $this->assertCount(1, $holds);
        $this->assertSame('Lunch', $holds[0]['title']);
        $this->assertSame('12:00:00', $holds[0]['start_time']);
        $this->assertSame(90, (int)$holds[0]['duration_minutes']);
        $this->assertSame($teacher, (int)$holds[0]['teacher_user_id']);
        $this->assertSame($locationId, (int)$holds[0]['location_id']);

        // One block per active class date, at the imported time.
        $blocks = HoldBlockManagement::holdBlocksBetween('2030-09-07', '2030-09-21', $semesterId);
        $this->assertSame(
            ['2030-09-07 12:00:00', '2030-09-14 12:00:00', '2030-09-21 12:00:00'],
            array_column($blocks, 'start_datetime')
        );

        // Re-importing the same slot is a no-op, not a conflict error.
        $again = HoldBlocksCsvImport::validateRows([
            ['teacher_name' => 'Marisol Vega', 'location_name' => $locationName,
             'day' => 'sat', 'start_time' => '12:00', 'end_time' => '13:30', 'title' => 'Lunch'],
        ], ['semester_id' => $semesterId]);
        $this->assertSame('Already held (no change)', $again[0]['changes']);
        $this->assertSame(0, HoldBlocksCsvImport::commit($this->ctx, $again, ['semester_id' => $semesterId])['created']);
        $this->assertCount(1, HoldBlockManagement::holdBlockReservationsForSemester($semesterId));
    }

    public function testHoldBlocksImportRejectsBadRows(): void
    {
        [$semesterId, , , $locationName] = $this->holdBlockSetup();
        fx_teacher('Unassigned', 'Teacher');

        $base = ['teacher_name' => 'Marisol Vega', 'location_name' => $locationName,
                 'day' => 'Saturday', 'start_time' => '12:00 pm', 'end_time' => '1:30 pm', 'title' => 'Lunch'];

        $validated = HoldBlocksCsvImport::validateRows([
            ['day' => 'Blursday'] + $base,
            ['start_time' => '2:00 pm', 'end_time' => '1:00 pm'] + $base,
            ['start_time' => '8:00 am', 'end_time' => '3:00 pm'] + $base,
            ['title' => '  '] + $base,
            ['teacher_name' => 'Unassigned Teacher'] + $base,
            ['teacher_name' => 'Nobody Here'] + $base,
            ['location_name' => 'Nowhere'] + $base,
            $base,
            $base, // duplicate of the row above
        ], ['semester_id' => $semesterId]);

        $expected = [
            'Unknown day "Blursday"',
            'End time must be after the start time.',
            'cannot be longer than 4 hours',
            'Title is required',
            'is not assigned to',
            'No match found for teacher',
            'No match found for location',
        ];
        foreach ($expected as $i => $fragment) {
            $this->assertSame('error', $validated[$i]['status'], "row $i should be an error");
            $this->assertStringContainsString($fragment, implode(' ', $validated[$i]['messages']));
        }
        $this->assertSame('valid', $validated[7]['status']);
        $this->assertSame('error', $validated[8]['status']);
        $this->assertStringContainsString('Duplicate row', $validated[8]['messages'][0]);

        // Only the one good row commits.
        $this->assertSame(1, HoldBlocksCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId])['created']);
    }

    public function testHoldBlocksImportRejectsSlotTakenByALesson(): void
    {
        [$semesterId, $locationId, $teacher, $locationName] = $this->holdBlockSetup();
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'student_user_id' => fx_student(),
            'day_of_week' => 6, 'start_time' => '12:30', 'duration_minutes' => 30,
        ]);

        $validated = HoldBlocksCsvImport::validateRows([
            ['teacher_name' => 'Marisol Vega', 'location_name' => $locationName,
             'day' => 'Saturday', 'start_time' => '12:00 pm', 'end_time' => '1:30 pm', 'title' => 'Lunch'],
        ], ['semester_id' => $semesterId]);

        // The clash is reported in the validation table, before anything is
        // written — not thrown mid-commit.
        $this->assertSame('error', $validated[0]['status']);
        $this->assertStringContainsString('Sam Student', implode(' ', $validated[0]['messages']));
        $this->assertSame(0, HoldBlocksCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId])['created']);
        $this->assertSame([], HoldBlockManagement::holdBlockReservationsForSemester($semesterId));
    }

    public function testHoldBlocksImportRejectsATeacherDoubleBookedWithinTheFile(): void
    {
        [$semesterId, $locationId, , $locationName] = $this->holdBlockSetup();
        $otherLocationId = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId, $otherLocationId]);
        $teacherId = (int)pdo()->query("SELECT id FROM users WHERE first_name='Marisol'")->fetchColumn();
        SemesterManagement::addLocationTeacher($this->ctx, $semesterId, $otherLocationId, $teacherId);
        $otherName = (string)pdo()->query("SELECT name FROM locations WHERE id=$otherLocationId")->fetchColumn();

        $validated = HoldBlocksCsvImport::validateRows([
            ['teacher_name' => 'Marisol Vega', 'location_name' => $locationName,
             'day' => 'Saturday', 'start_time' => '12:00 pm', 'end_time' => '1:30 pm', 'title' => 'Lunch'],
            ['teacher_name' => 'Marisol Vega', 'location_name' => $otherName,
             'day' => 'Saturday', 'start_time' => '12:00 pm', 'end_time' => '1:30 pm', 'title' => 'Lunch'],
        ], ['semester_id' => $semesterId]);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertSame('error', $validated[1]['status']);
        $this->assertStringContainsString('two places at once', implode(' ', $validated[1]['messages']));

        // Only the first row commits.
        $this->assertSame(1, HoldBlocksCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId])['created']);
        $this->assertCount(1, HoldBlockManagement::holdBlockReservationsForSemester($semesterId));
    }

    public function testDayOfWeekParsing(): void
    {
        $this->assertSame(6, HoldBlocksCsvImport::parseDayOfWeek('Saturday'));
        $this->assertSame(6, HoldBlocksCsvImport::parseDayOfWeek('  SAT '));
        $this->assertSame(0, HoldBlocksCsvImport::parseDayOfWeek('sunday'));
        $this->assertSame(0, HoldBlocksCsvImport::parseDayOfWeek('0'));
        $this->assertSame(4, HoldBlocksCsvImport::parseDayOfWeek('Thurs'));
        $this->assertNull(HoldBlocksCsvImport::parseDayOfWeek('7'));
        $this->assertNull(HoldBlocksCsvImport::parseDayOfWeek(''));
        $this->assertNull(HoldBlocksCsvImport::parseDayOfWeek('someday'));
    }

    public function testSampleHoldBlocksCsvParsesAndValidates(): void
    {
        $path = __DIR__ . '/../../../sample_data/fall_semester/hold_blocks.csv';
        $this->assertFileExists($path);
        $parsed = CsvImport::parseCsv((string)file_get_contents($path), ',');
        $this->assertSame(
            ['Teacher Name', 'Location Name', 'Day', 'Start Time', 'End Time', 'Title'],
            $parsed['headers']
        );
        $this->assertCount(6, $parsed['rows']);
        foreach ($parsed['rows'] as $row) {
            $this->assertSame('Lunch', $row[5]);
            $this->assertSame(6, HoldBlocksCsvImport::parseDayOfWeek($row[2]));
            $this->assertSame('12:00:00', LocationDatesCsvImport::parseTime($row[3]));
            $this->assertSame('13:30:00', LocationDatesCsvImport::parseTime($row[4]));
        }
    }

    // ── Schedule (semester lesson reservations) ────────────────────────────

    /**
     * A semester with two teachers at one location and Saturday class dates.
     * @return array{0:int,1:int,2:int,3:int,4:string}
     *   [semesterId, locationId, vegaId, okaforId, locationName]
     */
    private function scheduleSetup(): array
    {
        $vega = fx_teacher('Marisol', 'Vega');
        $okafor = fx_teacher('James', 'Okafor');
        [$semesterId, $locationId] = fx_semester_with_dates($this->ctx, $vega, '2030-09-07', 3);
        SemesterManagement::addLocationTeacher($this->ctx, $semesterId, $locationId, $okafor);
        $locationName = (string)pdo()->query("SELECT name FROM locations WHERE id=$locationId")->fetchColumn();
        return [$semesterId, $locationId, $vega, $okafor, $locationName];
    }

    private function scheduleRow(string $student, string $teacher, string $locationName, array $overrides = []): array
    {
        return $overrides + [
            'student_name' => $student,
            'teacher_name' => $teacher,
            'location_name' => $locationName,
            'day' => 'Saturday',
            'start_time' => '10:00 am',
            'duration_minutes' => '30',
            'status' => 'confirmed',
        ];
    }

    public function testScheduleImportCreatesReservationsAndLessonsWithoutCharging(): void
    {
        Settings::set('registration_cost', '50.00');
        Settings::set('semester_lesson_cost', '300.00');
        [$semesterId, $locationId, $vega, , $locationName] = $this->scheduleSetup();
        $lucia = fx_student('Lucia', 'Ramos');

        $validated = SemesterReservationsCsvImport::validateRows([
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName),
        ], ['semester_id' => $semesterId]);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertStringContainsString('Reserve Lucia Ramos with Marisol Vega', $validated[0]['changes']);
        $this->assertStringContainsString('Saturdays, 10:00 am–10:30 am (confirmed)', $validated[0]['changes']);

        $summary = SemesterReservationsCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId]);
        $this->assertSame(1, $summary['created']);

        $reservations = ReservationManagement::reservationsForSemester($semesterId);
        $this->assertCount(1, $reservations);
        $this->assertSame('confirmed', $reservations[0]['status']);
        $this->assertSame($lucia, (int)$reservations[0]['student_user_id']);
        $this->assertSame($vega, (int)$reservations[0]['teacher_user_id']);
        $this->assertSame($locationId, (int)$reservations[0]['location_id']);
        $this->assertSame('10:00:00', $reservations[0]['start_time']);

        // Confirmed, so the lessons exist — but this is a migration of a
        // schedule that already ran, so no money moved.
        $st = pdo()->prepare('SELECT COUNT(*) FROM lessons WHERE semester_lesson_reservation_id=?');
        $st->execute([(int)$reservations[0]['id']]);
        $this->assertSame(3, (int)$st->fetchColumn());
        $this->assertSame(0, Billing::balanceForStudentCents($lucia));
        $this->assertSame([], Billing::ledgerForStudent($lucia, $semesterId));

        // Re-importing the same row changes nothing.
        $again = SemesterReservationsCsvImport::validateRows([
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName, ['start_time' => '10:00']),
        ], ['semester_id' => $semesterId]);
        $this->assertSame('Already reserved (no change)', $again[0]['changes']);
        $this->assertSame(0, SemesterReservationsCsvImport::commit($this->ctx, $again, ['semester_id' => $semesterId])['created']);
        $this->assertCount(1, ReservationManagement::reservationsForSemester($semesterId));
    }

    public function testScheduleImportDefaultsAndStatusAliases(): void
    {
        [$semesterId, , , , $locationName] = $this->scheduleSetup();
        fx_student('Lucia', 'Ramos');
        fx_student('Marco', 'Ramos');

        $validated = SemesterReservationsCsvImport::validateRows([
            // No duration and no status: 30 minutes, pending reach out.
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName,
                ['duration_minutes' => '', 'status' => '']),
            $this->scheduleRow('Marco Ramos', 'James Okafor', $locationName,
                ['status' => 'Pending Confirmation', 'duration_minutes' => '45']),
        ], ['semester_id' => $semesterId]);

        $this->assertSame(['valid', 'valid'], array_column($validated, 'status'));
        $this->assertStringContainsString('10:00 am–10:30 am (pending reach out)', $validated[0]['changes']);
        $this->assertStringContainsString('10:00 am–10:45 am (pending confirmation)', $validated[1]['changes']);

        SemesterReservationsCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId]);
        $reservations = ReservationManagement::reservationsForSemester($semesterId);
        $this->assertSame(['pending_reach_out', 'pending_confirmation'], array_column($reservations, 'status'));
        $this->assertSame([30, 45], array_map('intval', array_column($reservations, 'duration_minutes')));
        // Nothing is confirmed, so nothing was materialized.
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM lessons')->fetchColumn());
    }

    public function testScheduleImportFlagsUnknownPeopleBadValuesAndWrongDay(): void
    {
        [$semesterId, , , , $locationName] = $this->scheduleSetup();
        fx_student('Lucia', 'Ramos');
        fx_teacher('Zoe', 'Zither'); // a teacher, but not at this location

        $validated = SemesterReservationsCsvImport::validateRows([
            $this->scheduleRow('Nobody Here', 'Marisol Vega', $locationName),
            $this->scheduleRow('Lucia Ramos', 'Zoe Zither', $locationName),
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', 'Narnia'),
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName, ['duration_minutes' => '600']),
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName, ['status' => 'maybe']),
            // Class dates are Saturdays only, so a Tuesday slot never happens.
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName, ['day' => 'Tuesday']),
        ], ['semester_id' => $semesterId]);

        $this->assertSame(array_fill(0, 6, 'error'), array_column($validated, 'status'));
        $this->assertStringContainsString('No match found for student', $validated[0]['messages'][0]);
        $this->assertStringContainsString('not assigned to', $validated[1]['messages'][0]);
        $this->assertStringContainsString('No match found for location', $validated[2]['messages'][0]);
        $this->assertStringContainsString('between 1 and 240 minutes', $validated[3]['messages'][0]);
        $this->assertStringContainsString('Unknown status', $validated[4]['messages'][0]);
        $this->assertStringContainsString('no active class dates on Tuesdays', $validated[5]['messages'][0]);

        $this->assertSame(0, SemesterReservationsCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId])['created']);
        $this->assertSame([], ReservationManagement::reservationsForSemester($semesterId));
    }

    public function testScheduleImportRejectsDoubleBookingWithinTheFile(): void
    {
        [$semesterId, , , , $locationName] = $this->scheduleSetup();
        fx_student('Lucia', 'Ramos');
        fx_student('Marco', 'Ramos');

        $validated = SemesterReservationsCsvImport::validateRows([
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName),
            // Same teacher, overlapping time, different student.
            $this->scheduleRow('Marco Ramos', 'Marisol Vega', $locationName,
                ['start_time' => '10:15 am']),
            // Same student, overlapping time, different teacher.
            $this->scheduleRow('Lucia Ramos', 'James Okafor', $locationName),
            // Exactly the same cell twice.
            $this->scheduleRow('Marco Ramos', 'James Okafor', $locationName, ['start_time' => '11:00 am']),
            $this->scheduleRow('Lucia Ramos', 'James Okafor', $locationName, ['start_time' => '11:00 am']),
        ], ['semester_id' => $semesterId]);

        $this->assertSame(['valid', 'error', 'error', 'valid', 'error'], array_column($validated, 'status'));
        $this->assertStringContainsString('already books this teacher', $validated[1]['messages'][0]);
        $this->assertStringContainsString('already books this student', $validated[2]['messages'][0]);
        $this->assertStringContainsString('Duplicate row', $validated[4]['messages'][0]);

        $this->assertSame(2, SemesterReservationsCsvImport::commit($this->ctx, $validated, ['semester_id' => $semesterId])['created']);
    }

    public function testScheduleImportRespectsHoldBlocksAndExistingReservations(): void
    {
        [$semesterId, $locationId, $vega, , $locationName] = $this->scheduleSetup();
        fx_student('Lucia', 'Ramos');
        fx_student('Marco', 'Ramos');
        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $vega, 'location_id' => $locationId,
            'day_of_week' => 6, 'start_time' => '12:00', 'duration_minutes' => 90, 'title' => 'Lunch',
        ]);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $vega, 'location_id' => $locationId,
            'student_user_id' => fx_student('Naomi', 'Osei'), 'day_of_week' => 6,
            'start_time' => '10:00', 'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        $validated = SemesterReservationsCsvImport::validateRows([
            $this->scheduleRow('Lucia Ramos', 'Marisol Vega', $locationName, ['start_time' => '12:30 pm']),
            $this->scheduleRow('Marco Ramos', 'Marisol Vega', $locationName),
        ], ['semester_id' => $semesterId]);

        $this->assertSame(['error', 'error'], array_column($validated, 'status'));
        $this->assertStringContainsString('hold block ("Lunch")', $validated[0]['messages'][0]);
        $this->assertStringContainsString("Naomi Osei's weekly slot", $validated[1]['messages'][0]);
    }

    public function testSampleScheduleCsvHeadersMatchTheImporter(): void
    {
        $path = __DIR__ . '/../../../sample_data/fall_semester/semester_location_reservations.csv';
        $this->assertFileExists($path);
        $parsed = CsvImport::parseCsv((string)file_get_contents($path), ',');
        $this->assertSame(array_values(SemesterReservationsCsvImport::targetFields()), $parsed['headers']);
        $this->assertCount(14, $parsed['rows']);
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
