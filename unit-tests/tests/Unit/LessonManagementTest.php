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

    public function testMovingOntoAnotherLessonOfTheSameTeacherIsRefused(): void
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
            LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:00');
            $this->fail('Expected a conflict.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked for this teacher', $e->getMessage());
        }

        // 11:30 is free.
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:30');
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
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:30');
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

    public function testSubstituteWhoIsAlreadyBookedIsRefused(): void
    {
        // The modal names a substitute directly, so the check has to live in
        // the library rather than only on the drag path.
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');
        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$locationId, $teacher], [$locationId, $sub]]);
        // Sue already teaches somebody at exactly that hour.
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $sub, 'location_id' => $locationId,
            'student_user_id' => fx_student('Otto', 'Other'), 'day_of_week' => 6,
            'start_time' => '10:00', 'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        try {
            LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
            $this->fail('Expected a busy substitute to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked for this teacher', $e->getMessage());
        }
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);
    }

    public function testSubstituteIsAcceptedWhenFreeAndCanBeCleared(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');

        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
        $this->assertSame($sub, (int)LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);

        // Choosing "No substitute" in the dropdown clears it, and clearing is
        // never blocked by a conflict check.
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], null);
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);
    }

    public function testASubstituteMayKeepTheirOwnLessonElsewhereInTheDay(): void
    {
        // Being busy at 11:00 says nothing about 10:00 — the check is the
        // moment, not the day.
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');
        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$locationId, $teacher], [$locationId, $sub]]);
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $sub, 'location_id' => $locationId,
            'student_user_id' => fx_student('Otto', 'Other'), 'day_of_week' => 6,
            'start_time' => '11:00', 'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
        $this->assertSame($sub, (int)LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);
    }

    public function testASubstituteNeedNotTeachThisSemester(): void
    {
        // Cover often comes from someone with no regular slot. Being off the
        // semester's roster is not a reason to refuse them.
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $spare = fx_teacher('Spare', 'Cover'); // never assigned to a location

        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $spare);

        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame($spare, (int)$lesson['substitute_teacher_user_id']);
        $this->assertSame($spare, (int)$lesson['effective_teacher_user_id']);
        // And the lesson still belongs to them on their own day view.
        $this->assertCount(1, LessonManagement::lessonsForTeacherOnDate($spare, '2030-09-07'));
    }

    public function testAnOffRosterSubstituteIsStillCheckedForClashes(): void
    {
        // Relaxing "must teach this semester" must not relax "must be free".
        [$teacher, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $spare = fx_teacher('Spare', 'Cover');

        // The spare teacher picks up an earlier lesson at the same hour...
        $otherReservation = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
            'student_user_id' => fx_student('Otto', 'Other'), 'day_of_week' => 6,
            'start_time' => '11:00', 'duration_minutes' => 30, 'status' => 'confirmed',
        ]);
        $st = pdo()->prepare('SELECT id FROM lessons WHERE semester_lesson_reservation_id=? ORDER BY start_datetime');
        $st->execute([$otherReservation]);
        $otherLessonId = (int)$st->fetchAll()[0]['id'];
        LessonManagement::setSubstituteTeacher($this->ctx, $otherLessonId, $spare);

        // ...so they cannot also cover a lesson that overlaps it.
        LessonManagement::moveLesson($this->ctx, $lessonIds[0], '2030-09-07 11:00');
        try {
            LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $spare);
            $this->fail('Expected the clash to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked for this teacher', $e->getMessage());
        }
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['substitute_teacher_user_id']);
    }

    public function testSomeoneWithNoTeacherProfileIsStillRefused(): void
    {
        // "Any teacher" still means a teacher.
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(InvalidArgumentException::class);
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], fx_user('Paul', 'Parent'));
    }

    // ===== Where one week is held =====

    public function testLocationOverrideMovesOnlyThatWeek(): void
    {
        [, , $semesterId, $locationId, , $lessonIds] = $this->makeConfirmed();
        $second = fx_second_location_id();

        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], $second);

        $moved = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame($second, (int)$moved['location_id_override']);
        $this->assertSame($second, (int)$moved['effective_location_id']);
        // The reservation and every other week are untouched.
        $this->assertSame($locationId, (int)$moved['location_id']);
        $this->assertNull(LessonManagement::getLesson($lessonIds[1])['location_id_override']);
        $this->assertSame($locationId, (int)LessonManagement::getLesson($lessonIds[1])['effective_location_id']);
    }

    public function testChoosingTheUsualLocationClearsTheOverride(): void
    {
        [, , , $locationId, , $lessonIds] = $this->makeConfirmed();
        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], fx_second_location_id());

        // Both the empty option and the reservation's own location mean
        // "back to normal", and neither stores an override that says nothing.
        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], $locationId);
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['location_id_override']);

        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], fx_second_location_id());
        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], null);
        $this->assertNull(LessonManagement::getLesson($lessonIds[0])['location_id_override']);
    }

    public function testLocationOverrideRejectsALocationThatDoesNotExist(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(InvalidArgumentException::class);
        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], 999999);
    }

    public function testLocationOverrideRequiresAnAdmin(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(RuntimeException::class);
        LessonManagement::setLocationOverride(
            new UserContext(fx_user('Nel', 'Nobody'), false), $lessonIds[0], fx_second_location_id()
        );
    }

    public function testASubstituteAndALocationAreIndependent(): void
    {
        // The complaint that prompted this: picking a substitute based at the
        // other building must not decide where the family turns up.
        [, , , $locationId, , $lessonIds] = $this->makeConfirmed();
        $sub = fx_teacher('Sue', 'Substitute');

        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], $sub);
        $this->assertSame($locationId, (int)LessonManagement::getLesson($lessonIds[0])['effective_location_id'],
            'naming a substitute leaves the lesson where it was');

        // Moving it is a separate, deliberate choice.
        $second = fx_second_location_id();
        LessonManagement::setLocationOverride($this->ctx, $lessonIds[0], $second);
        $lesson = LessonManagement::getLesson($lessonIds[0]);
        $this->assertSame($sub, (int)$lesson['substitute_teacher_user_id']);
        $this->assertSame($second, (int)$lesson['effective_location_id']);

        // And taking the substitute off does not drag the room back with it.
        LessonManagement::setSubstituteTeacher($this->ctx, $lessonIds[0], null);
        $this->assertSame($second, (int)LessonManagement::getLesson($lessonIds[0])['effective_location_id']);
    }

    // ===== One-off lessons, booked straight onto the calendar =====

    private function adHocSetup(): array
    {
        $teacher = fx_teacher('Ada', 'Adhoc');
        [$semesterId, $locationId] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 3);
        return [$teacher, fx_student('Sol', 'Single'), $semesterId, $locationId];
    }

    public function testAdHocLessonStandsOnItsOwn(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();

        $lessonId = LessonManagement::createAdHocLesson($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 45,
        ]);

        $lesson = LessonManagement::getLesson($lessonId);
        $this->assertTrue(LessonManagement::isAdHoc($lesson));
        $this->assertNull($lesson['semester_lesson_reservation_id']);
        // Everything a lesson normally inherits is readable anyway.
        $this->assertSame($semesterId, (int)$lesson['semester_id']);
        $this->assertSame($teacher, (int)$lesson['teacher_user_id']);
        $this->assertSame($teacher, (int)$lesson['effective_teacher_user_id']);
        $this->assertSame($student, (int)$lesson['student_user_id']);
        $this->assertSame($locationId, (int)$lesson['effective_location_id']);
        $this->assertSame('Sol', $lesson['student_first_name']);
        $this->assertSame('Ada', $lesson['teacher_first_name']);
        $this->assertSame('2030-09-10 15:00:00', $lesson['start_datetime']);
        $this->assertSame(45, (int)$lesson['duration_minutes']);
        // It has no standing slot, so it can never be "moved off" one.
        $this->assertFalse(LessonManagement::isTimeMoved($lesson));
    }

    public function testAdHocLessonShowsUpEverywhereARealLessonWould(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        LessonManagement::createAdHocLesson($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 30,
        ]);

        $this->assertCount(1, LessonManagement::lessonsBetween('2030-09-10', '2030-09-10', $semesterId));
        $this->assertCount(1, LessonManagement::lessonsForTeacherOnDate($teacher, '2030-09-10'));
        $this->assertCount(1, LessonManagement::lessonsBetweenForTeacher($teacher, '2030-09-10', '2030-09-10'));
        $this->assertCount(1, LessonManagement::lessonsForTeacherInSemesters($teacher, [$semesterId]));
        $this->assertCount(1, LessonManagement::lessonsForStudentsInSemesters([$student], [$semesterId]));
        $this->assertCount(1, LessonManagement::lessonsBetweenForStudents([$student], '2030-09-10', '2030-09-10'));
        $this->assertCount(1, LessonManagement::upcomingLessonsForStudent($student, '2030-09-01'));
        $this->assertSame('2030-09-10', LessonManagement::nextTeachingDateForTeacher($teacher, '2030-09-09'));
    }

    public function testAdHocLessonHoldsTheTeachersTime(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        LessonManagement::createAdHocLesson($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 60,
        ]);

        // A one-off is a real commitment: it blocks the moment like any other.
        $this->assertNotNull(ScheduleConflicts::occurrenceConflict($teacher, '2030-09-10 15:30', 30));
        $this->assertNull(ScheduleConflicts::occurrenceConflict($teacher, '2030-09-10 16:00', 30));

        // ...including against a second one-off.
        try {
            LessonManagement::createAdHocLesson($this->ctx, [
                'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
                'student_user_id' => fx_student('Two', 'Second'), 'location_id' => $locationId,
                'start_datetime' => '2030-09-10 15:30', 'duration_minutes' => 30,
            ]);
            $this->fail('Expected the clash to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already booked for this teacher', $e->getMessage());
        }
    }

    public function testAdHocLessonIsRefusedOverAStandingLesson(): void
    {
        [$teacher, , , $locationId, , ] = $this->makeConfirmed();
        $semesterId = (int)LessonManagement::getLesson(
            (int)pdo()->query('SELECT id FROM lessons LIMIT 1')->fetchColumn()
        )['semester_id'];

        $this->expectException(InvalidArgumentException::class);
        LessonManagement::createAdHocLesson($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => fx_student('Sol', 'Single'), 'location_id' => $locationId,
            'start_datetime' => '2030-09-07 10:00', 'duration_minutes' => 30,
        ]);
    }

    public function testAdHocLessonRejectsBadInput(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        $good = [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 30,
        ];
        $cases = [
            'no student' => ['student_user_id' => 0],
            'no semester' => ['semester_id' => 0],
            'no location' => ['location_id' => 0],
            'not a teacher' => ['teacher_user_id' => fx_user('Norm', 'Nonteacher')],
            'silly length' => ['duration_minutes' => 0],
            'nonsense time' => ['start_datetime' => 'whenever'],
        ];
        foreach ($cases as $label => $override) {
            try {
                LessonManagement::createAdHocLesson($this->ctx, array_merge($good, $override));
                $this->fail("Expected $label to be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM lessons')->fetchColumn());
    }

    public function testAdHocLessonRequiresAnAdmin(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        $this->expectException(RuntimeException::class);
        LessonManagement::createAdHocLesson(new UserContext(fx_user('Nel', 'Nobody'), false), [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 30,
        ]);
    }

    public function testMovingAnAdHocLessonRetargetsItRatherThanSubstituting(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        $other = fx_teacher('Bea', 'Other');
        $lessonId = LessonManagement::createAdHocLesson($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 30,
        ]);

        LessonManagement::moveLesson($this->ctx, $lessonId, '2030-09-11 09:30', $other, $locationId);

        $lesson = LessonManagement::getLesson($lessonId);
        $this->assertSame('2030-09-11 09:30:00', $lesson['start_datetime']);
        $this->assertSame($other, (int)$lesson['teacher_user_id'], 'it simply becomes their lesson');
        $this->assertNull($lesson['substitute_teacher_user_id'], 'there is no standing teacher to substitute for');
    }

    public function testAdHocLessonCanBeCancelledOrDeleted(): void
    {
        [$teacher, $student, $semesterId, $locationId] = $this->adHocSetup();
        $fields = [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'student_user_id' => $student, 'location_id' => $locationId,
            'start_datetime' => '2030-09-10 15:00', 'duration_minutes' => 30,
        ];

        $first = LessonManagement::createAdHocLesson($this->ctx, $fields);
        LessonManagement::cancelLesson($this->ctx, $first);
        $this->assertTrue(LessonManagement::isCancelled(LessonManagement::getLesson($first)));
        // Cancelled, so its time is free again for a replacement.
        $second = LessonManagement::createAdHocLesson($this->ctx, $fields);

        LessonManagement::deleteAdHocLesson($this->ctx, $second);
        $this->assertNull(LessonManagement::getLesson($second));
    }

    public function testALessonFromAWeeklyBookingIsNeverDeletedOutright(): void
    {
        [, , , , , $lessonIds] = $this->makeConfirmed();
        $this->expectException(InvalidArgumentException::class);
        LessonManagement::deleteAdHocLesson($this->ctx, $lessonIds[0]);
    }
}
