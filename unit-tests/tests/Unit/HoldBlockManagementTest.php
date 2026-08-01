<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HoldBlockManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    /** @param array{0:int,1:int,2:int,3:int,4:array} $setup */
    private function makeHold(array $setup, string $startTime = '12:00', int $duration = 30, string $title = 'Lunch'): int
    {
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        return HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'duration_minutes' => $duration,
            'title' => $title,
        ]);
    }

    private function blockRows(int $reservationId): array
    {
        $st = pdo()->prepare(
            'SELECT * FROM semester_hold_blocks WHERE semester_hold_block_reservation_id=? ORDER BY start_datetime'
        );
        $st->execute([$reservationId]);
        return $st->fetchAll();
    }

    private function makeLessonReservation(array $setup, string $startTime, int $duration = 30, string $status = 'confirmed'): int
    {
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        return ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => fx_student(),
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'duration_minutes' => $duration,
            'status' => $status,
        ]);
    }

    // ── Creation ───────────────────────────────────────────────────────────

    public function testCreateGeneratesOneBlockPerActiveDate(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId, $locationId] = $setup;
        // Week 3 is a holiday: no block there.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-21', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');

        $holdId = $this->makeHold($setup);
        $blocks = $this->blockRows($holdId);

        $this->assertSame(
            ['2030-09-07 12:00:00', '2030-09-14 12:00:00', '2030-09-28 12:00:00'],
            array_column($blocks, 'start_datetime')
        );
        foreach ($blocks as $block) {
            $this->assertNull($block['title_override']);
            $this->assertSame(30, (int)$block['duration_minutes']);
        }

        // Idempotent: generating again creates nothing new.
        $this->assertSame(0, HoldBlockManagement::generateHoldBlocksForReservation($this->ctx, $holdId));
        $this->assertCount(3, $this->blockRows($holdId));
    }

    public function testCreateRequiresTitle(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        $this->expectException(InvalidArgumentException::class);
        $this->makeHold($setup, '12:00', 30, '   ');
    }

    public function testCreateRequiresTeacherAtLocation(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        $stranger = fx_teacher('Nora', 'NotHere');

        $this->expectException(InvalidArgumentException::class);
        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $setup[0],
            'teacher_user_id' => $stranger,
            'location_id' => $setup[1],
            'day_of_week' => $setup[3],
            'start_time' => '12:00',
            'title' => 'Lunch',
        ]);
    }

    public function testNonAdminCannotCreateHoldBlock(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        $teacherCtx = new UserContext($setup[2], false, false);

        $this->expectException(RuntimeException::class);
        HoldBlockManagement::createHoldBlockReservation($teacherCtx, [
            'semester_id' => $setup[0],
            'teacher_user_id' => $setup[2],
            'location_id' => $setup[1],
            'day_of_week' => $setup[3],
            'start_time' => '12:00',
            'title' => 'Lunch',
        ]);
    }

    // ── Overlap with lesson reservations ───────────────────────────────────

    public function testHoldBlockRejectedWhenItOverlapsALessonReservation(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        // A 60-minute lesson at 10:00 runs to 11:00.
        $this->makeLessonReservation($setup, '10:00', 60);

        $this->expectException(InvalidArgumentException::class);
        $this->makeHold($setup, '10:30');
    }

    public function testLessonReservationRejectedWhenItOverlapsAHoldBlock(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $this->makeHold($setup, '12:00', 60);

        $this->expectException(InvalidArgumentException::class);
        $this->makeLessonReservation($setup, '12:30');
    }

    public function testAdjacentSlotsDoNotOverlap(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $this->makeLessonReservation($setup, '10:00', 60);
        // 11:00 starts exactly when the lesson ends — not an overlap.
        $holdId = $this->makeHold($setup, '11:00');
        $this->assertCount(3, $this->blockRows($holdId));
    }

    /** A 60-minute reservation at 10:00 used to allow a second one at 10:30. */
    public function testOverlappingLessonReservationsRejected(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $this->makeLessonReservation($setup, '10:00', 60, 'pending_reach_out');

        $this->expectException(InvalidArgumentException::class);
        $this->makeLessonReservation($setup, '10:30', 30, 'pending_reach_out');
    }

    // ── Moving ─────────────────────────────────────────────────────────────

    public function testMoveRetimesFutureBlocksInPlaceAndLeavesPastAlone(): void
    {
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), $pastFirst, 5);
        $holdId = $this->makeHold($setup, '12:00');

        $before = $this->blockRows($holdId);
        $pastIds = [];
        foreach ($before as $block) {
            if (strtotime((string)$block['start_datetime']) <= time()) {
                $pastIds[(int)$block['id']] = (string)$block['start_datetime'];
            }
        }
        $this->assertGreaterThan(0, count($pastIds));

        HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['start_time' => '13:00']);

        $after = $this->blockRows($holdId);
        // Same rows, none deleted and recreated.
        $this->assertSame(
            array_map(fn($b) => (int)$b['id'], $before),
            array_map(fn($b) => (int)$b['id'], $after)
        );
        foreach ($after as $block) {
            $id = (int)$block['id'];
            if (isset($pastIds[$id])) {
                $this->assertSame($pastIds[$id], (string)$block['start_datetime'], 'past block moved');
            } else {
                $this->assertSame('13:00:00', substr((string)$block['start_datetime'], 11), 'future block did not move');
            }
        }
    }

    public function testHandCustomizedBlockIsNotMoved(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00');

        // Week 2 was moved by hand to 14:00.
        $blocks = $this->blockRows($holdId);
        pdo()->prepare('UPDATE semester_hold_blocks SET start_datetime=? WHERE id=?')
            ->execute(['2030-09-14 14:00:00', (int)$blocks[1]['id']]);

        HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['start_time' => '13:00']);

        $this->assertSame(
            ['2030-09-07 13:00:00', '2030-09-14 14:00:00', '2030-09-21 13:00:00'],
            array_column($this->blockRows($holdId), 'start_datetime')
        );
    }

    /**
     * A move that would collide on ANY future week is refused outright — the
     * system must never end up with two commitments in one slot, so it does
     * not half-apply the move.
     */
    public function testMoveIsRefusedWhenAnySingleWeekWouldCollide(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00');

        // A confirmed lesson occupies 13:00 on week 2 only.
        $lessonReservationId = $this->makeLessonReservation($setup, '15:00');
        pdo()->prepare('UPDATE lessons SET start_datetime=? WHERE semester_lesson_reservation_id=? AND DATE(start_datetime)=?')
            ->execute(['2030-09-14 13:00:00', $lessonReservationId, '2030-09-14']);

        try {
            HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['start_time' => '13:00']);
            $this->fail('Expected the colliding week to block the whole move.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Sep 14', $e->getMessage());
        }

        // Nothing moved, and the reservation kept its old time.
        $this->assertSame(
            ['2030-09-07 12:00:00', '2030-09-14 12:00:00', '2030-09-21 12:00:00'],
            array_column($this->blockRows($holdId), 'start_datetime')
        );
        $this->assertSame('12:00:00', HoldBlockManagement::findHoldBlockReservation($holdId)['start_time']);
    }

    /** A teacher cannot be in two places at once, so the check spans locations. */
    public function testSlotTakenAtAnotherLocationIsRejected(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 3);
        $otherLocationId = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId, $otherLocationId]);
        SemesterManagement::addLocationTeacher($this->ctx, $semesterId, $otherLocationId, $teacher);

        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'day_of_week' => 6,
            'start_time' => '12:00', 'duration_minutes' => 60, 'title' => 'Lunch',
        ]);

        $this->expectException(InvalidArgumentException::class);
        HoldBlockManagement::createHoldBlockReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $otherLocationId, 'day_of_week' => 6,
            'start_time' => '12:30', 'duration_minutes' => 30, 'title' => 'Errand',
        ]);
    }

    public function testDurationMustBeAWholeNumberOfHalfHours(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        // The options the modal offers are all valid half-hour multiples.
        $this->assertSame([30, 60, 90, 120], HoldBlockManagement::DURATION_OPTIONS);
        foreach (HoldBlockManagement::DURATION_OPTIONS as $minutes) {
            $this->assertSame(0, $minutes % 30);
        }
        // 90 minutes is fine.
        $this->assertIsInt($this->makeHold($setup, '09:00', 90));
        // 45 is not.
        try {
            $this->makeHold($setup, '13:00', 45);
            $this->fail('Expected 45 minutes to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('half hours', $e->getMessage());
        }
        // Neither is 0 or an over-long block.
        $this->expectException(InvalidArgumentException::class);
        $this->makeHold($setup, '13:00', 300);
    }

    public function testRetitlingPropagatesToFutureBlocksButNotOverriddenWeeks(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00', 30, 'Lunch');

        $blocks = $this->blockRows($holdId);
        pdo()->prepare('UPDATE semester_hold_blocks SET title_override=? WHERE id=?')
            ->execute(['Dentist', (int)$blocks[1]['id']]);

        HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['title' => 'Break']);

        $after = $this->blockRows($holdId);
        $this->assertNull($after[0]['title_override']);
        $this->assertSame('Dentist', $after[1]['title_override']);
        $this->assertNull($after[2]['title_override']);

        $weekBlocks = HoldBlockManagement::holdBlocksBetween('2030-09-07', '2030-09-21');
        $this->assertSame(['Break', 'Dentist', 'Break'], array_column($weekBlocks, 'effective_title'));
    }

    public function testChangingDayOfWeekRegeneratesOnTheNewDates(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3); // Saturdays
        [$semesterId, $locationId] = $setup;
        $holdId = $this->makeHold($setup, '12:00');

        // Add Sunday class dates and move the hold block to Sundays.
        foreach (['2030-09-08', '2030-09-15'] as $date) {
            SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, $date, '09:00:00', '17:00:00', 'active', 'Sunday');
        }
        HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['day_of_week' => 0]);

        $this->assertSame(
            ['2030-09-08 12:00:00', '2030-09-15 12:00:00'],
            array_column($this->blockRows($holdId), 'start_datetime')
        );
    }

    // ── Deleting ───────────────────────────────────────────────────────────

    public function testDeleteSoftDeletesAndKeepsPastBlocks(): void
    {
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), $pastFirst, 5);
        $holdId = $this->makeHold($setup, '12:00');
        $pastCount = count(array_filter(
            $this->blockRows($holdId),
            fn($b) => strtotime((string)$b['start_datetime']) <= time()
        ));
        $this->assertGreaterThan(0, $pastCount);

        HoldBlockManagement::deleteHoldBlockReservation($this->ctx, $holdId);

        $r = HoldBlockManagement::findHoldBlockReservation($holdId);
        $this->assertSame('deleted', $r['status']);
        $this->assertCount($pastCount, $this->blockRows($holdId));

        // Gone from the grid, and the slot is bookable again.
        $this->assertSame([], HoldBlockManagement::holdBlockReservationsForSemester($setup[0]));
        $this->assertIsInt($this->makeLessonReservation($setup, '12:00'));

        // Idempotent, and no longer editable.
        HoldBlockManagement::deleteHoldBlockReservation($this->ctx, $holdId);
        $this->expectException(RuntimeException::class);
        HoldBlockManagement::updateHoldBlockReservation($this->ctx, $holdId, ['title' => 'Nope']);
    }

    // ── Calendar resync ────────────────────────────────────────────────────

    public function testResyncAfterCalendarEdit(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId, $locationId] = $setup;
        $holdId = $this->makeHold($setup, '12:00');
        $this->assertCount(4, $this->blockRows($holdId));

        // Week 2 becomes a holiday; week 5 is added.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-10-05', '09:00:00', '17:00:00', 'active', 'Day 5');
        HoldBlockManagement::resyncHoldBlocksForLocation($this->ctx, $semesterId, $locationId);

        $this->assertSame(
            ['2030-09-07', '2030-09-21', '2030-09-28', '2030-10-05'],
            array_map(fn($b) => substr((string)$b['start_datetime'], 0, 10), $this->blockRows($holdId))
        );
    }

    public function testDeletedReservationIsNotResynced(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$semesterId, $locationId] = $setup;
        $holdId = $this->makeHold($setup, '12:00');
        HoldBlockManagement::deleteHoldBlockReservation($this->ctx, $holdId);

        HoldBlockManagement::resyncHoldBlocksForLocation($this->ctx, $semesterId, $locationId);
        $this->assertCount(0, $this->blockRows($holdId));
    }

    // ── Single occurrences (the weekly calendar's hold block modal) ────────

    public function testRescheduleOneWeekLeavesTheRestAlone(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00');
        $blocks = $this->blockRows($holdId);

        HoldBlockManagement::rescheduleBlockWithinDay($this->ctx, (int)$blocks[1]['id'], '14:00');

        $this->assertSame(
            ['2030-09-07 12:00:00', '2030-09-14 14:00:00', '2030-09-21 12:00:00'],
            array_column($this->blockRows($holdId), 'start_datetime')
        );
        // The standing reservation is untouched.
        $this->assertSame('12:00:00', HoldBlockManagement::findHoldBlockReservation($holdId)['start_time']);
    }

    public function testRescheduleOneWeekOntoALessonIsRejected(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00');
        $this->makeLessonReservation($setup, '14:00');

        $blocks = $this->blockRows($holdId);
        $this->expectException(InvalidArgumentException::class);
        HoldBlockManagement::rescheduleBlockWithinDay($this->ctx, (int)$blocks[1]['id'], '14:00');
    }

    public function testTitleOverrideAppliesToOneWeekAndClears(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00', 30, 'Lunch');
        $blockId = (int)$this->blockRows($holdId)[1]['id'];

        HoldBlockManagement::setBlockTitleOverride($this->ctx, $blockId, 'Dentist');
        $this->assertSame('Dentist', HoldBlockManagement::getBlock($blockId)['effective_title']);

        // Blank clears the override.
        HoldBlockManagement::setBlockTitleOverride($this->ctx, $blockId, '');
        $this->assertNull(HoldBlockManagement::getBlock($blockId)['title_override']);
        $this->assertSame('Lunch', HoldBlockManagement::getBlock($blockId)['effective_title']);

        // So does re-typing the standing title.
        HoldBlockManagement::setBlockTitleOverride($this->ctx, $blockId, 'Lunch');
        $this->assertNull(HoldBlockManagement::getBlock($blockId)['title_override']);
    }

    public function testDeletingOneWeekKeepsTheReservation(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        $holdId = $this->makeHold($setup, '12:00');
        $blockId = (int)$this->blockRows($holdId)[1]['id'];

        HoldBlockManagement::deleteBlock($this->ctx, $blockId);

        $this->assertSame(
            ['2030-09-07 12:00:00', '2030-09-21 12:00:00'],
            array_column($this->blockRows($holdId), 'start_datetime')
        );
        $this->assertSame('active', HoldBlockManagement::findHoldBlockReservation($holdId)['status']);
        $this->assertNull(HoldBlockManagement::getBlock($blockId));
    }

    public function testNonAdminCannotEditASingleBlock(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        $holdId = $this->makeHold($setup, '12:00');
        $blockId = (int)$this->blockRows($holdId)[0]['id'];
        $teacherCtx = new UserContext($setup[2], false, false);

        $this->expectException(RuntimeException::class);
        HoldBlockManagement::deleteBlock($teacherCtx, $blockId);
    }

    // ── Queries ────────────────────────────────────────────────────────────

    public function testTeacherWeekQueryReturnsOnlyThatTeachersBlocks(): void
    {
        $teacher = fx_teacher();
        $setup = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2);
        $this->makeHold($setup, '12:00', 30, 'Lunch');

        $mine = HoldBlockManagement::holdBlocksBetweenForTeacher($teacher, '2030-09-07', '2030-09-14');
        $this->assertCount(2, $mine);
        $this->assertSame('Lunch', $mine[0]['effective_title']);

        $other = HoldBlockManagement::holdBlocksBetweenForTeacher(fx_teacher('Otto', 'Other'), '2030-09-07', '2030-09-14');
        $this->assertSame([], $other);
    }
}
