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
}
