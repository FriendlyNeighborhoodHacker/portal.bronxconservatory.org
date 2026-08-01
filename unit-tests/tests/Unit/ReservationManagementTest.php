<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReservationManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    /** @return array{0:int,1:array} [reservationId, setup] */
    private function makeReservation(array $setup, string $status = 'pending_reach_out', ?int $studentId = null): array
    {
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        $id = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => $studentId ?? fx_student(),
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'duration_minutes' => 30,
            'status' => $status,
        ]);
        return [$id, $setup];
    }

    private function lessonRows(int $reservationId): array
    {
        $st = pdo()->prepare(
            'SELECT * FROM lessons WHERE semester_lesson_reservation_id=? ORDER BY start_datetime'
        );
        $st->execute([$reservationId]);
        return $st->fetchAll();
    }

    public function testCreateRequiresTeacherAtLocation(): void
    {
        $teacher = fx_teacher();
        $setup = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 4);
        $stranger = fx_teacher('Nora', 'NotHere');

        $this->expectException(InvalidArgumentException::class);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $setup[0],
            'teacher_user_id' => $stranger,
            'location_id' => $setup[1],
            'student_user_id' => fx_student(),
            'day_of_week' => 6,
            'start_time' => '10:00',
        ]);
    }

    public function testDuplicateCellRejected(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        $this->makeReservation($setup);
        $this->expectException(InvalidArgumentException::class);
        $this->makeReservation($setup);
    }

    public function testConfirmGeneratesLessonsFromActiveDates(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId, $locationId] = $setup;
        // Deactivate week 3: it must not generate a lesson.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-21', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');

        [$reservationId] = $this->makeReservation($setup);
        $this->assertCount(0, $this->lessonRows($reservationId));

        ReservationManagement::setStatus($this->ctx, $reservationId, 'confirmed');
        $lessons = $this->lessonRows($reservationId);
        $this->assertCount(3, $lessons);
        $this->assertSame('2030-09-07 10:00:00', $lessons[0]['start_datetime']);
        $this->assertSame('2030-09-14 10:00:00', $lessons[1]['start_datetime']);
        $this->assertSame('2030-09-28 10:00:00', $lessons[2]['start_datetime']);
        // lesson_number is the ordinal in the ACTIVE date calendar.
        $this->assertSame([1, 2, 3], array_map(fn($l) => (int)$l['lesson_number'], $lessons));

        // Idempotent: generating again creates nothing new.
        $this->assertSame(0, ReservationManagement::generateLessonsForReservation($this->ctx, $reservationId));
        $this->assertCount(3, $this->lessonRows($reservationId));
    }

    public function testUnconfirmDeletesOnlyFutureLessons(): void
    {
        // Two past dates (2020) and the semester still "running" into the
        // future would be odd; instead build a semester whose dates straddle
        // now: two in the past, two in the future.
        $teacher = fx_teacher();
        $pastFirst = date('Y-m-d', strtotime('-3 weeks', strtotime('last saturday')));
        $setup = fx_semester_with_dates($this->ctx, $teacher, $pastFirst, 6, 'fall', 2030);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');

        $all = $this->lessonRows($reservationId);
        $this->assertCount(6, $all);
        $past = array_filter($all, fn($l) => strtotime($l['start_datetime']) <= time());
        $this->assertGreaterThan(0, count($past));

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');
        $remaining = $this->lessonRows($reservationId);
        $this->assertCount(count($past), $remaining);
        foreach ($remaining as $lesson) {
            $this->assertLessThanOrEqual(time(), strtotime($lesson['start_datetime']));
        }

        // Re-confirming regenerates the future rows; kept past rows keep
        // their numbers and the calendar ordinals still agree.
        ReservationManagement::setStatus($this->ctx, $reservationId, 'confirmed');
        $regenerated = $this->lessonRows($reservationId);
        $this->assertCount(6, $regenerated);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(fn($l) => (int)$l['lesson_number'], $regenerated));
    }

    public function testDeleteReservationKeepsPastLessonsAndIsTerminal(): void
    {
        $teacher = fx_teacher();
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        $setup = fx_semester_with_dates($this->ctx, $teacher, $pastFirst, 5, 'fall', 2030);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');
        $pastCount = count(array_filter($this->lessonRows($reservationId), fn($l) => strtotime($l['start_datetime']) <= time()));

        ReservationManagement::deleteReservation($this->ctx, $reservationId);
        $r = ReservationManagement::findReservation($reservationId);
        $this->assertSame('deleted', $r['status']);
        $this->assertCount($pastCount, $this->lessonRows($reservationId));

        $this->expectException(RuntimeException::class);
        ReservationManagement::setStatus($this->ctx, $reservationId, 'confirmed');
    }

    public function testResyncAfterCalendarEdit(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId, $locationId] = $setup;
        [$reservationId] = $this->makeReservation($setup, 'confirmed');
        $this->assertCount(4, $this->lessonRows($reservationId));

        // Week 2 becomes a holiday; week 5 is added.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-10-05', '09:00:00', '17:00:00', 'active', 'Day 5');
        ReservationManagement::resyncLessonsForLocation($this->ctx, $semesterId, $locationId);

        $lessons = $this->lessonRows($reservationId);
        $this->assertSame(
            ['2030-09-07', '2030-09-21', '2030-09-28', '2030-10-05'],
            array_map(fn($l) => substr($l['start_datetime'], 0, 10), $lessons)
        );
        $this->assertSame([1, 2, 3, 4], array_map(fn($l) => (int)$l['lesson_number'], $lessons));
    }

    // ── Moving a reservation ───────────────────────────────────────────────

    public function testMoveWithinTheDayPreservesPerLessonData(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');
        $before = $this->lessonRows($reservationId);
        $this->assertCount(3, $before);

        // A note on week 1 and a "missed" mark on week 2 — the things the old
        // delete-and-regenerate path silently destroyed.
        NotesManagement::saveLessonNote($this->ctx, (int)$before[0]['id'], 'Worked on scales.');
        LessonManagement::markAttendance($this->ctx, (int)$before[1]['id'], false);

        ReservationManagement::updateReservation($this->ctx, $reservationId, [
            'start_time' => '11:00', 'duration_minutes' => 45,
        ]);

        $after = $this->lessonRows($reservationId);
        // Same rows, retimed and resized in place.
        $this->assertSame(
            array_map(fn($l) => (int)$l['id'], $before),
            array_map(fn($l) => (int)$l['id'], $after)
        );
        $this->assertSame(
            ['2030-09-07 11:00:00', '2030-09-14 11:00:00', '2030-09-21 11:00:00'],
            array_column($after, 'start_datetime')
        );
        $this->assertSame([45, 45, 45], array_map(fn($l) => (int)$l['duration_minutes'], $after));

        $note = NotesManagement::lessonNoteFor((int)$before[0]['id'], $this->ctx->id);
        $this->assertSame('Worked on scales.', $note['body']);
        $this->assertSame(0, (int)$after[1]['attended']);
    }

    public function testMoveLeavesHandCustomizedLessonsAlone(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');
        $lessons = $this->lessonRows($reservationId);

        // Week 2 was rescheduled by hand to 14:00.
        LessonManagement::rescheduleWithinDay($this->ctx, (int)$lessons[1]['id'], '14:00');

        ReservationManagement::updateReservation($this->ctx, $reservationId, ['start_time' => '11:00']);

        $this->assertSame(
            ['2030-09-07 11:00:00', '2030-09-14 14:00:00', '2030-09-21 11:00:00'],
            array_column($this->lessonRows($reservationId), 'start_datetime')
        );
    }

    /**
     * A move that would collide on ANY future week is refused outright, and
     * nothing is written — the schedule must never hold two commitments for
     * one teacher at one moment.
     */
    public function testMoveIsRefusedWhenAnySingleWeekWouldCollide(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');

        // Another student's confirmed lesson is hand-moved to 11:00 on week 2.
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        $otherId = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacherId,
            'location_id' => $locationId, 'student_user_id' => fx_student('Other', 'Student'),
            'day_of_week' => $dayOfWeek, 'start_time' => '15:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);
        pdo()->prepare('UPDATE lessons SET start_datetime=? WHERE semester_lesson_reservation_id=? AND DATE(start_datetime)=?')
            ->execute(['2030-09-14 11:00:00', $otherId, '2030-09-14']);

        try {
            ReservationManagement::updateReservation($this->ctx, $reservationId, ['start_time' => '11:00']);
            $this->fail('Expected the colliding week to block the whole move.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Other Student', $e->getMessage());
            $this->assertStringContainsString('Sep 14', $e->getMessage());
        }

        // Nothing moved, and the reservation kept its old time.
        $this->assertSame(
            ['2030-09-07 10:00:00', '2030-09-14 10:00:00', '2030-09-21 10:00:00'],
            array_column($this->lessonRows($reservationId), 'start_datetime')
        );
        $this->assertSame('10:00:00', ReservationManagement::findReservation($reservationId)['start_time']);
    }

    /** A teacher cannot be in two places at once, so the check spans locations. */
    public function testSlotTakenAtAnotherLocationIsRejected(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 3);
        $otherLocationId = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId, $otherLocationId]);
        SemesterManagement::addLocationTeacher($this->ctx, $semesterId, $otherLocationId, $teacher);

        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'student_user_id' => fx_student(),
            'day_of_week' => 6, 'start_time' => '10:00', 'duration_minutes' => 60,
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $otherLocationId, 'student_user_id' => fx_student('Two', 'Student'),
            'day_of_week' => 6, 'start_time' => '10:30', 'duration_minutes' => 30,
        ]);
    }

    /**
     * A slot can look free weekly but be taken on one date by a hand-moved
     * lesson; creating there must be refused, not silently double-booked.
     */
    public function testCreateIsRefusedWhenAFutureWeekIsTakenByAMovedLesson(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        [$existingId] = $this->makeReservation($setup, 'confirmed');

        // Week 2 of the existing reservation is moved by hand to 11:00.
        LessonManagement::rescheduleWithinDay(
            $this->ctx,
            (int)$this->lessonRows($existingId)[1]['id'],
            '11:00'
        );

        // 11:00 is free as a weekly slot, but not on Sep 14.
        $this->expectException(InvalidArgumentException::class);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacherId,
            'location_id' => $locationId, 'student_user_id' => fx_student('Other', 'Student'),
            'day_of_week' => $dayOfWeek, 'start_time' => '11:00', 'duration_minutes' => 30,
            'status' => 'confirmed',
        ]);
    }

    public function testMoveDoesNotDisturbPastLessons(): void
    {
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), $pastFirst, 5);
        [$reservationId] = $this->makeReservation($setup, 'confirmed');

        $before = [];
        foreach ($this->lessonRows($reservationId) as $lesson) {
            if (strtotime((string)$lesson['start_datetime']) <= time()) {
                $before[(int)$lesson['id']] = (string)$lesson['start_datetime'];
            }
        }
        $this->assertGreaterThan(0, count($before));

        ReservationManagement::updateReservation($this->ctx, $reservationId, ['start_time' => '11:00']);

        foreach ($this->lessonRows($reservationId) as $lesson) {
            $id = (int)$lesson['id'];
            if (isset($before[$id])) {
                $this->assertSame($before[$id], (string)$lesson['start_datetime']);
            } else {
                $this->assertSame('11:00:00', substr((string)$lesson['start_datetime'], 11));
            }
        }
    }

    public function testChangingDayOfWeekRegeneratesOnTheNewDates(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3); // Saturdays
        [$semesterId, $locationId] = $setup;
        [$reservationId] = $this->makeReservation($setup, 'confirmed');

        foreach (['2030-09-08', '2030-09-15'] as $date) {
            SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, $date, '09:00:00', '17:00:00', 'active', 'Sunday');
        }
        ReservationManagement::updateReservation($this->ctx, $reservationId, ['day_of_week' => 0]);

        $this->assertSame(
            ['2030-09-08 10:00:00', '2030-09-15 10:00:00'],
            array_column($this->lessonRows($reservationId), 'start_datetime')
        );
    }

    public function testMoveOntoAnotherReservationsSlotIsRejected(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 3);
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        [$reservationId] = $this->makeReservation($setup);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacherId,
            'location_id' => $locationId, 'student_user_id' => fx_student('Other', 'Student'),
            'day_of_week' => $dayOfWeek, 'start_time' => '11:00', 'duration_minutes' => 30,
        ]);

        $this->expectException(InvalidArgumentException::class);
        ReservationManagement::updateReservation($this->ctx, $reservationId, ['start_time' => '11:00']);
    }

    public function testGridDataShape(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 2);
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        $student = fx_student();
        [$reservationId] = $this->makeReservation($setup, 'pending_reach_out', $student);

        $grid = ReservationManagement::gridDataForSemester($semesterId);
        $this->assertCount(1, $grid['columns']);
        $key = $locationId . ':' . $teacherId . ':' . $dayOfWeek . ':10:00:00';
        $this->assertArrayHasKey($key, $grid['reservations']);
        $this->assertSame($reservationId, (int)$grid['reservations'][$key]['id']);
        $this->assertArrayHasKey($student, $grid['balances']);
        $this->assertSame(0, $grid['balances'][$student]['total_balance_cents']);
    }
}
