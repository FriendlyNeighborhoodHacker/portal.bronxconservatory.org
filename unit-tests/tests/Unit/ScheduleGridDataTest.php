<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScheduleGridDataTest extends TestCase
{
    private UserContext $ctx;
    private int $teacher;
    private int $semesterId;
    private int $locationId;
    private int $dayOfWeek;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
        UserContext::set($this->ctx);
        $this->teacher = fx_teacher('Marisol', 'Vega');
        // 2030-09-07 is a Saturday.
        [$this->semesterId, $this->locationId, , $this->dayOfWeek] =
            fx_semester_with_dates($this->ctx, $this->teacher, '2030-09-07', 4);
    }

    private function reserve(string $startTime, int $duration, string $studentName = 'Sam'): int
    {
        $studentId = fx_student($studentName, 'Student');
        return ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $this->semesterId,
            'teacher_user_id' => $this->teacher,
            'location_id' => $this->locationId,
            'student_user_id' => $studentId,
            'day_of_week' => $this->dayOfWeek,
            'start_time' => $startTime,
            'duration_minutes' => $duration,
            'status' => 'pending_reach_out',
        ], ['post_charges' => false]);
    }

    private function columnKey(): string
    {
        return $this->locationId . ':' . $this->teacher;
    }

    public function testSpineCoversTheSemestersDaysAndTheDefaultWindow(): void
    {
        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $this->assertSame([$this->dayOfWeek], $grid['days']);
        $this->assertSame(ScheduleGridData::DEFAULT_BOUNDS, $grid['bounds'][$this->dayOfWeek]);
        $this->assertCount(1, $grid['columns']);
        $this->assertSame([], $grid['occupants']);
        $this->assertSame([], $grid['cellIndex']);
    }

    public function testBandsCarryPerDayColumnsAndBounds(): void
    {
        // A second location that meets Tuesday evenings, staffed by a
        // Tuesday-only teacher; the fixture's location stays Saturday-only.
        $tuesdayTeacher = fx_teacher('Nia', 'Harp');
        $otherLocation = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $this->semesterId, [$this->locationId, $otherLocation]);
        SemesterManagement::upsertLocationDate(
            $this->ctx, $this->semesterId, $otherLocation, '2030-09-10', '15:30:00', '20:00:00', 'active', 'Day 1'
        );
        SemesterManagement::addLocationTeacher($this->ctx, $this->semesterId, $otherLocation, $tuesdayTeacher, 2);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        // Saturday-first — the main day on top — then on through the week.
        $this->assertSame([6, 2], array_column($grid['bands'], 'day'));
        $this->assertSame(['Saturdays', 'Tuesdays'], array_column($grid['bands'], 'label'));
        [$saturday, $tuesday] = $grid['bands'];

        // Each band holds only its own day's teachers.
        $this->assertSame([$this->teacher], array_map('intval', array_column($saturday['columns'], 'teacher_user_id')));
        $this->assertSame([$tuesdayTeacher], array_map('intval', array_column($tuesday['columns'], 'teacher_user_id')));

        // And each band runs over its own day's real hours: the Tuesday
        // building opens 3:30 pm and closes 8:00, so the last slot a lesson
        // may start in is 7:30.
        $this->assertSame(ScheduleGridData::DEFAULT_BOUNDS, $saturday['bounds']);
        $this->assertSame([15 * 60 + 30, 19 * 60 + 30], $tuesday['bounds']);
    }

    public function testABookingOnADayItsTeacherLostStillGetsAColumn(): void
    {
        $reservationId = $this->reserve('10:00', 30);
        // The assignment behind the booking goes away.
        pdo()->exec('DELETE FROM semester_location_teachers WHERE semester_id=' . $this->semesterId);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $band = $grid['bands'][0];
        $this->assertSame($this->dayOfWeek, $band['day']);
        $this->assertSame([$this->teacher], array_map('intval', array_column($band['columns'], 'teacher_user_id')));
        $this->assertTrue((bool)$band['columns'][0]['is_extra']);
        $this->assertNotNull($reservationId);
    }

    public function testADeclaredDayGetsItsBandBeforeAnyDatesExist(): void
    {
        // Declare Tuesday evenings alongside the fixture's Saturday — no
        // Tuesday class dates imported yet.
        SemesterManagement::setLocationWeekdays($this->ctx, $this->semesterId, $this->locationId, [
            [6, '09:00', '17:00'],
            [2, '15:30', '20:00'],
        ]);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $this->assertSame([6, 2], array_column($grid['bands'], 'day'));
        $tuesday = $grid['bands'][1];
        // Declared hours drive the band's window: 3:30 pm through the 7:30 pm
        // slot (an 8:00 pm close means 7:30 is the last slot a lesson starts).
        $this->assertSame([15 * 60 + 30, 19 * 60 + 30], $tuesday['bounds']);
    }

    public function testEmptySemesterFallsBackToSaturday(): void
    {
        $bareSemester = fx_semester($this->ctx, 'spring', 2031, '2031-01-05', '2031-05-20');
        $grid = ScheduleGridData::semesterWeeklyGrid($bareSemester);

        $this->assertSame([ScheduleGridData::DEFAULT_DAY], $grid['days']);
    }

    public function testAnEarlyBookingWidensOnlyItsOwnDay(): void
    {
        // 8:00 is before the default 9:00 start.
        $this->reserve('08:00', 30);
        // Another day, so we can check it keeps the default window.
        $otherDay = ($this->dayOfWeek + 1) % 7;
        SemesterManagement::upsertLocationDate(
            $this->ctx, $this->semesterId, $this->locationId, '2030-09-08', '09:00:00', '17:00:00', 'active', 'Sunday'
        );

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $this->assertSame(8 * 60, $grid['bounds'][$this->dayOfWeek][0]);
        $this->assertSame(ScheduleGridData::DEFAULT_BOUNDS[1], $grid['bounds'][$this->dayOfWeek][1]);
        $this->assertSame(ScheduleGridData::DEFAULT_BOUNDS, $grid['bounds'][$otherDay]);
    }

    public function testALateHoldBlockWidensTheEndOfTheDay(): void
    {
        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $this->semesterId,
            'teacher_user_id' => $this->teacher,
            'location_id' => $this->locationId,
            'day_of_week' => $this->dayOfWeek,
            'start_time' => '18:00',
            'duration_minutes' => 30,
            'title' => 'Rehearsal',
        ]);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $this->assertSame(18 * 60, $grid['bounds'][$this->dayOfWeek][1]);
    }

    public function testLessonsAndHoldBlocksShareOneCellIndex(): void
    {
        $this->reserve('10:00', 30);
        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $this->semesterId,
            'teacher_user_id' => $this->teacher,
            'location_id' => $this->locationId,
            'day_of_week' => $this->dayOfWeek,
            'start_time' => '12:00',
            'duration_minutes' => 30,
            'title' => 'Lunch',
        ]);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);
        $kinds = array_column($grid['occupants'], 'kind');
        sort($kinds);
        $this->assertSame(['hold', 'lesson'], $kinds);

        $lessonCell = $grid['cellIndex'][$this->columnKey() . ':' . $this->dayOfWeek . ':' . (10 * 60)];
        $holdCell = $grid['cellIndex'][$this->columnKey() . ':' . $this->dayOfWeek . ':' . (12 * 60)];
        $this->assertSame('lesson', $lessonCell[0]['kind']);
        $this->assertSame('hold', $holdCell[0]['kind']);
    }

    public function testAnOffSlotStartIsKeyedToTheRowItLivesIn(): void
    {
        $this->reserve('10:15', 30);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);

        $key = $this->columnKey() . ':' . $this->dayOfWeek . ':' . (10 * 60);
        $this->assertArrayHasKey($key, $grid['cellIndex'], '10:15 renders in the 10:00 row');
        $this->assertSame('10:15:00', $grid['cellIndex'][$key][0]['start_time']);
    }

    public function testSlotMinutesSnapsDownToTheRow(): void
    {
        $this->assertSame(600, ScheduleGridData::slotMinutes('10:00:00'));
        $this->assertSame(600, ScheduleGridData::slotMinutes('10:15:00'));
        $this->assertSame(630, ScheduleGridData::slotMinutes('10:30'));
        $this->assertSame(630, ScheduleGridData::slotMinutes('10:59:00'));
    }

    public function testCoveredByEarlierOccupantRespectsDuration(): void
    {
        $this->reserve('10:00', 60);

        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);
        $cellIndex = $grid['cellIndex'];
        $key = $this->columnKey();

        // The hour lesson owns 10:30 as well, but not 11:00.
        $this->assertTrue(ScheduleGridData::coveredByEarlierOccupant($cellIndex, $key, $this->dayOfWeek, 10 * 60 + 30));
        $this->assertFalse(ScheduleGridData::coveredByEarlierOccupant($cellIndex, $key, $this->dayOfWeek, 11 * 60));
        // Its own row is not "covered" — it is occupied, which the caller
        // discovers first.
        $this->assertFalse(ScheduleGridData::coveredByEarlierOccupant($cellIndex, $key, $this->dayOfWeek, 10 * 60));
        // Nothing on a different day is affected.
        $this->assertFalse(ScheduleGridData::coveredByEarlierOccupant($cellIndex, $key, ($this->dayOfWeek + 1) % 7, 10 * 60 + 30));
    }

    public function testOccupantsAtFindsTheCellsContents(): void
    {
        $this->reserve('11:00', 30);
        $grid = ScheduleGridData::semesterWeeklyGrid($this->semesterId);
        $column = $grid['columns'][0];

        $row = ['day' => $this->dayOfWeek, 'minutes' => 11 * 60];
        $this->assertCount(1, ScheduleGridData::occupantsAt($grid['cellIndex'], $column, $row));

        $emptyRow = ['day' => $this->dayOfWeek, 'minutes' => 13 * 60];
        $this->assertSame([], ScheduleGridData::occupantsAt($grid['cellIndex'], $column, $emptyRow));
    }
}
