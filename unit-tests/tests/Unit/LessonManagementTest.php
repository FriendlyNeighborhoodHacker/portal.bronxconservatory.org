<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LessonManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    /** A confirmed reservation with lessons; returns useful ids. */
    private function makeConfirmed(string $firstDate = '2030-09-07', int $weeks = 3, string $time = '10:00'): array
    {
        $teacher = fx_teacher();
        $student = fx_student();
        $setup = fx_semester_with_dates($this->ctx, $teacher, $firstDate, $weeks);
        [$semesterId, $locationId, , $dayOfWeek] = $setup;
        $reservationId = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacher,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => $time,
            'status' => 'confirmed',
        ]);
        $st = pdo()->prepare('SELECT id FROM lessons WHERE semester_lesson_reservation_id=? ORDER BY start_datetime');
        $st->execute([$reservationId]);
        $lessonIds = array_map('intval', array_column($st->fetchAll(), 'id'));
        return [$teacher, $student, $semesterId, $locationId, $reservationId, $lessonIds];
    }

    public function testTeacherDayQueriesAndEffectiveTeacher(): void
    {
        [$teacher, , , , , $lessonIds] = $this->makeConfirmed();
        $lessons = LessonManagement::lessonsForTeacherOnDate($teacher, '2030-09-07');
        $this->assertCount(1, $lessons);
        $this->assertSame($lessonIds[0], (int)$lessons[0]['id']);

        // A substitute takes over lesson 1: the day belongs to the sub now.
        $sub = fx_teacher('Sue', 'Substitute');
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
        $this->assertCount(0, LessonManagement::lessonsForTeacherOnDate($teacher, '2030-09-07'));
        $subLessons = LessonManagement::lessonsForTeacherOnDate($sub, '2030-09-07');
        $this->assertCount(1, $subLessons);
        $this->assertTrue(LessonManagement::isEffectiveTeacher($sub, $subLessons[0]));
        $this->assertFalse(LessonManagement::isEffectiveTeacher($teacher, $subLessons[0]));

        $this->assertSame('2030-09-14', LessonManagement::nextTeachingDateForTeacher($teacher, '2030-09-07'));
        $this->assertSame('2030-09-14', LessonManagement::previousTeachingDateForTeacher($teacher, '2030-09-21'));
        $this->assertNull(LessonManagement::nextTeachingDateForTeacher($teacher, '2030-09-21'));
    }

    public function testSubstituteMustBeATeacher(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(InvalidArgumentException::class);
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], fx_student('Not', 'ATeacher'));
    }

    public function testRescheduleWithinDayConflicts(): void
    {
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        // A second student at 11:00 with the same teacher.
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacher,
            'location_id' => $locationId,
            'student_user_id' => fx_student('Beth', 'Second'),
            'day_of_week' => 6,
            'start_time' => '11:00',
            'status' => 'confirmed',
        ]);

        // Moving lesson 1 to 11:00 collides with the other student's lesson.
        try {
            LessonManagement::rescheduleWithinDay($this->ctx, $lessonIds[0], '11:00');
            $this->fail('Expected a conflict.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked for this teacher', $e->getMessage());
        }

        // 11:30 is free.
        LessonManagement::rescheduleWithinDay($this->ctx, $lessonIds[0], '11:30');
        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame('2030-09-07 11:30:00', $lesson['start_datetime']);
    }

    public function testAttendanceAuthorization(): void
    {
        [$teacher, , , , , $lessonIds] = $this->makeConfirmed();
        $lessonId = $lessonIds[0];

        // The lesson's teacher marks it missed.
        LessonManagement::markAttendance(new UserContext($teacher, false), $lessonId, false);
        $this->assertSame(0, (int)LessonManagement::getLesson($lessonId)['attended']);

        // An admin clears the mark.
        LessonManagement::markAttendance($this->ctx, $lessonId, null);
        $this->assertNull(LessonManagement::getLesson($lessonId)['attended']);

        // An unrelated teacher may not.
        $other = fx_teacher('Olga', 'Other');
        $this->expectException(RuntimeException::class);
        LessonManagement::markAttendance(new UserContext($other, false), $lessonId, true);
    }

    public function testCanUserViewLesson(): void
    {
        [$teacher, $student, , , , $lessonIds] = $this->makeConfirmed();
        $lessonId = $lessonIds[0];
        $parent = fx_parent_of($student);
        $stranger = fx_user('Randy', 'Random');

        $this->assertTrue(LessonManagement::canUserViewLesson($teacher, $lessonId));
        $this->assertTrue(LessonManagement::canUserViewLesson($student, $lessonId));
        $this->assertTrue(LessonManagement::canUserViewLesson($parent, $lessonId));
        $this->assertFalse(LessonManagement::canUserViewLesson($stranger, $lessonId));

        // The substitute gains access; a deleted user loses it.
        $sub = fx_teacher('Sue', 'Substitute');
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonId, $sub);
        $this->assertTrue(LessonManagement::canUserViewLesson($sub, $lessonId));
        pdo()->exec("UPDATE users SET is_deleted=1 WHERE id=$parent");
        $this->assertFalse(LessonManagement::canUserViewLesson($parent, $lessonId));
    }

    public function testTimeMovedTracksTheReservationsStandingSlot(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed('2030-09-07', 3, '10:00');

        // Straight off the reservation: nothing has moved.
        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame('10:00:00', $lesson['reservation_start_time']);
        $this->assertSame(30, (int)$lesson['reservation_duration_minutes']);
        $this->assertFalse(LessonManagement::isTimeMoved($lesson));

        // One week pushed later — only that week is flagged.
        LessonManagement::rescheduleWithinDay($this->ctx, $lessonIds[0], '11:30');
        $this->assertTrue(LessonManagement::isTimeMoved(LessonManagement::getLesson($lessonIds[0])));
        $this->assertFalse(LessonManagement::isTimeMoved(LessonManagement::getLesson($lessonIds[1])));

        // A different length counts as moved too.
        pdo()->exec('UPDATE lessons SET duration_minutes=45 WHERE id=' . $lessonIds[1]);
        $this->assertTrue(LessonManagement::isTimeMoved(LessonManagement::getLesson($lessonIds[1])));

        // Rows from queries that don't carry the reservation's slot never claim a move.
        $this->assertFalse(LessonManagement::isTimeMoved(['start_datetime' => '2030-09-07 11:30:00']));
    }

    public function testSubstituteNoteNamesWhoeverIsCovering(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->assertSame('', LessonManagement::substituteNote(LessonManagement::getLesson($lessonIds[0])));

        $sub = fx_teacher('Sue', 'Substitute');
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
        $this->assertSame(
            'Substitute teacher: Sue Substitute',
            LessonManagement::substituteNote(LessonManagement::getLesson($lessonIds[0]))
        );
    }

    public function testStudentQueries(): void
    {
        [, $student] = $this->makeConfirmed();
        $upcoming = LessonManagement::upcomingLessonsForStudent($student, '2030-09-08');
        $this->assertCount(2, $upcoming);
        $this->assertSame('2030-09-14 10:00:00', $upcoming[0]['start_datetime']);

        $between = LessonManagement::lessonsBetweenForStudents([$student], '2030-09-01', '2030-09-30');
        $this->assertCount(3, $between);
    }

    // ===== Cancelling a lesson =====

    public function testCancelMarksTheLessonWithoutRemovingIt(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();

        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);

        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertNotNull($lesson, 'cancelling is not deleting');
        $this->assertTrue(LessonManagement::isCancelled($lesson));
        $this->assertNotNull($lesson['cancelled_at']);
        $this->assertSame($this->ctx->id, (int)$lesson['cancelled_by_user_id']);
    }

    public function testCancelIsIdempotent(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();

        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);
        $first = LessonManagement::getLesson($lessonIds[0])['cancelled_at'];
        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);

        $this->assertSame($first, LessonManagement::getLesson($lessonIds[0])['cancelled_at']);
    }

    public function testCancelRequiresAnAdmin(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(RuntimeException::class);
        LessonManagement::cancelLesson(new UserContext(fx_user('Nel', 'Nobody'), false), $lessonIds[0]);
    }

    public function testACancelledLessonStopsHoldingItsSlot(): void
    {
        [$teacher, , , , , $lessonIds] = $this->makeConfirmed();
        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $moment = (string)$lesson['start_datetime'];

        // While it stands, the moment is taken.
        $this->assertNotNull(ScheduleConflicts::occurrenceConflict($teacher, $moment, 30));

        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);

        // Cancelled, the time is free again — that is the point of cancelling.
        $this->assertNull(ScheduleConflicts::occurrenceConflict($teacher, $moment, 30));
    }

    public function testTheFamilyAndTeacherStillSeeACancelledLessonButTheAdminCalendarDoesNot(): void
    {
        [$teacher, $student, , , , $lessonIds] = $this->makeConfirmed();
        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);

        // Admin calendar: gone.
        $adminWeek = LessonManagement::lessonsBetween('2030-09-07', '2030-09-07', null, false);
        $this->assertSame([], $adminWeek);

        // Everyone whose plans it was: still there.
        $this->assertCount(1, LessonManagement::lessonsBetween('2030-09-07', '2030-09-07'));
        $this->assertCount(1, LessonManagement::lessonsBetweenForStudents([$student], '2030-09-07', '2030-09-07'));
        $this->assertCount(1, LessonManagement::lessonsForTeacherOnDate($teacher, '2030-09-07'));
        $this->assertCount(3, LessonManagement::upcomingLessonsForStudent($student, '2030-09-01'));

        // But not to anything asking whether the teacher is free.
        $this->assertCount(0, LessonManagement::lessonsForTeacherOnDate($teacher, '2030-09-07', false));
    }

    public function testAnotherLessonCanBeMovedIntoACancelledSlot(): void
    {
        // Cancelling frees that MOMENT, not the standing weekly slot — the
        // reservation still owns 10:00 every week. What the freed moment buys
        // is room to move a real lesson into it.
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $other = fx_student('Otto', 'Other');
        $otherReservation = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
            'student_user_id' => $other, 'day_of_week' => 6, 'start_time' => '11:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);
        $st = pdo()->prepare('SELECT id FROM lessons WHERE semester_lesson_reservation_id=? ORDER BY start_datetime');
        $st->execute([$otherReservation]);
        $otherLessonId = (int)$st->fetchAll()[0]['id'];

        // Blocked while the 10:00 lesson stands...
        try {
            LessonManagement::moveLesson($this->ctx, $otherLessonId, '2030-09-07 10:00');
            $this->fail('Expected the occupied moment to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // ...and allowed once it is cancelled.
        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);
        LessonManagement::moveLesson($this->ctx, $otherLessonId, '2030-09-07 10:00');
        $this->assertSame('2030-09-07 10:00:00', LessonManagement::getLesson($otherLessonId)['start_datetime']);
    }

    public function testACancelledLessonCannotBeMoved(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        LessonManagement::cancelLesson($this->ctx, $lessonIds[0]);

        $this->expectException(InvalidArgumentException::class);
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00');
    }

    // ===== Moving a lesson (calendar drag-and-drop) =====

    public function testMoveChangesTheTimeOfThatOccurrenceOnly(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();

        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:30');

        $moved = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame('2030-09-07 13:30:00', $moved['start_datetime']);
        $this->assertTrue(LessonManagement::isTimeMoved($moved));
        // The following weeks stay on the standing slot.
        $this->assertSame('2030-09-14 10:00:00', LessonManagement::getLesson($lessonIds[1])['start_datetime']);
    }

    public function testMoveCanCrossToAnotherDay(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();

        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-08 11:00');

        $this->assertSame('2030-09-08 11:00:00', LessonManagement::getLesson($lessonIds[0])['start_datetime']);
    }

    public function testMoveOntoAnotherTeacherSetsThemAsTheSubstitute(): void
    {
        [$teacher, , , , , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');

        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00', $sub);

        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame($sub, (int)$lesson['substitute_teacher_user_id']);
        $this->assertSame($sub, (int)$lesson['effective_teacher_user_id']);
        // The standing reservation is untouched: this is one week, not a change
        // of teacher.
        $this->assertSame($teacher, (int)$lesson['teacher_user_id']);
    }

    public function testMovingBackOntoTheOwnTeacherClearsTheSubstitute(): void
    {
        [$teacher, , , , , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);

        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00', $teacher);

        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);
    }

    public function testMoveRefusesASubstituteWhoIsAlreadyBooked(): void
    {
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();

        // A second teacher with their own confirmed lesson at 11:00.
        $sub = fx_teacher('Sue', 'Substitute');
        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$locationId, $teacher], [$locationId, $sub]]);
        $otherStudent = fx_student('Otto', 'Other');
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $sub, 'location_id' => $locationId,
            'student_user_id' => $otherStudent, 'day_of_week' => 6, 'start_time' => '11:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        try {
            LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:00', $sub);
            $this->fail('Expected the clash to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Nothing committed: the lesson is where it was, with no substitute.
        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame('2030-09-07 10:00:00', $lesson['start_datetime']);
        $this->assertNull($lesson['substitute_teacher_user_id']);
    }

    public function testMoveRefusesSomeoneWhoIsNotATeacher(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $notATeacher = fx_user('Norm', 'Nonteacher');

        $this->expectException(InvalidArgumentException::class);
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00', $notATeacher);
    }

    public function testMoveRefusesAMomentTheTeacherAlreadyHas(): void
    {
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $otherStudent = fx_student('Otto', 'Other');
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
            'student_user_id' => $otherStudent, 'day_of_week' => 6, 'start_time' => '11:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        try {
            LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:00');
            $this->fail('Expected the clash to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame('2030-09-07 10:00:00', LessonManagement::getLesson($lessonIds[0])['start_datetime']);
    }

    public function testMoveOntoAnotherLocationRecordsAnOverride(): void
    {
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $second = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId, $second]);
        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$locationId, $teacher], [$second, $teacher]]);

        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00', $teacher, $second);

        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame($second, (int)$lesson['location_id_override']);
        $this->assertSame($second, (int)$lesson['effective_location_id']);

        // Moving back to its own location drops the override rather than
        // storing one that says nothing.
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 13:00', $teacher, $locationId);
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['location_id_override']);
    }

    public function testMoveRequiresAnAdmin(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(RuntimeException::class);
        LessonManagement::moveLesson(new UserContext(fx_user('Nel', 'Nobody'), false), $lessonIds[0], '2030-09-07 13:00');
    }
}
