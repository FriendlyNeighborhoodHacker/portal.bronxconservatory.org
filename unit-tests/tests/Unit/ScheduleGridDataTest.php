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
