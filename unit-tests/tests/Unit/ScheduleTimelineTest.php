<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScheduleTimelineTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    /** The API takes semester rows; tests mostly have just an id. */
    private static function sem(int ...$semesterIds): array
    {
        return array_map(fn(int $id): array => SemesterManagement::find($id), $semesterIds);
    }

    /** Compact view of a timeline: "kind date [detail]". */
    private function summarize(array $entries): array
    {
        return array_map(function (array $e): string {
            return $e['kind'] === 'holiday'
                ? 'holiday ' . $e['date'] . ' ' . $e['title']
                : 'lesson ' . $e['date'] . ' ' . substr((string)$e['lesson']['start_datetime'], 11, 5);
        }, $entries);
    }

    public function testStudentTimelineInterleavesLessonsAndHolidays(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId, , $dayOfWeek] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 4);
        // Week 3 becomes a holiday, so no lesson is generated for it.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-21', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');

        $student = fx_student();
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'student_user_id' => $student,
            'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        $entries = ScheduleTimeline::forStudents([$student], self::sem($semesterId));

        // Chronological, with the holiday sitting in the gap it explains.
        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-14 10:00',
            'holiday 2030-09-21 Holiday Week',
            'lesson 2030-09-28 10:00',
        ], $this->summarize($entries));

        // Lessons carry what the page needs: time, location, teacher.
        $lesson = $entries[0]['lesson'];
        $this->assertSame(30, (int)$lesson['duration_minutes']);
        $this->assertNotSame('', (string)$lesson['location_name']);
        $this->assertSame($teacher, (int)$lesson['teacher_user_id']);
        $this->assertNotSame('', (string)$entries[2]['location_name']);
    }

    public function testTeacherTimelineSeesTheirOwnLessonsAndHolidays(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId, , $dayOfWeek] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 3);
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');

        foreach ([['10:00', 'Ann'], ['11:00', 'Ben']] as [$time, $name]) {
            ReservationManagement::createReservation($this->ctx, [
                'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
                'location_id' => $locationId, 'student_user_id' => fx_student($name, 'Student'),
                'day_of_week' => $dayOfWeek, 'start_time' => $time,
                'duration_minutes' => 30, 'status' => 'confirmed',
            ]);
        }

        $entries = ScheduleTimeline::forTeacher($teacher, self::sem($semesterId));

        // Both students on each class day, one holiday notice for the break.
        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-07 11:00',
            'holiday 2030-09-14 Holiday Week',
            'lesson 2030-09-21 10:00',
            'lesson 2030-09-21 11:00',
        ], $this->summarize($entries));

        // A different teacher's timeline is empty.
        $this->assertSame([], ScheduleTimeline::forTeacher(fx_teacher('Other', 'Teacher'), self::sem($semesterId)));
    }

    public function testParentTimelineMergesSiblingsAndShowsOneHolidayPerLocation(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId, , $dayOfWeek] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2);
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');

        $siblingA = fx_student('Lucia', 'Ramos');
        $siblingB = fx_student('Marco', 'Ramos');
        foreach ([[$siblingA, '10:00'], [$siblingB, '10:30']] as [$student, $time]) {
            ReservationManagement::createReservation($this->ctx, [
                'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
                'location_id' => $locationId, 'student_user_id' => $student,
                'day_of_week' => $dayOfWeek, 'start_time' => $time,
                'duration_minutes' => 30, 'status' => 'confirmed',
            ]);
        }

        $entries = ScheduleTimeline::forStudents([$siblingA, $siblingB], self::sem($semesterId));

        // Both siblings' lessons, but the shared holiday is listed once.
        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-07 10:30',
            'holiday 2030-09-14 Holiday Week',
        ], $this->summarize($entries));
    }

    public function testHolidaysOnlyCoverTheWeekdayAndLocationTheyReserved(): void
    {
        $teacher = fx_teacher();
        [$semesterId, $locationId, , $dayOfWeek] = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2);

        // A holiday on a DIFFERENT weekday at the same location...
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locationId, '2030-09-10', '09:00:00', '17:00:00', 'inactive', 'Wednesday closure');
        // ...and one at a DIFFERENT location on the student's weekday.
        $otherLocationId = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId, $otherLocationId]);
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $otherLocationId, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Other site closed');

        $student = fx_student();
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'student_user_id' => $student,
            'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        // Neither holiday belongs to this student; only their lessons show.
        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-14 10:00',
        ], $this->summarize(ScheduleTimeline::forStudents([$student], self::sem($semesterId))));
    }

    public function testEmptyForSomeoneWithNothingScheduled(): void
    {
        $semesterId = fx_semester($this->ctx);
        $this->assertSame([], ScheduleTimeline::forStudents([fx_student()], self::sem($semesterId)));
        $this->assertSame([], ScheduleTimeline::forStudents([], self::sem($semesterId)));
        $this->assertSame([], ScheduleTimeline::forTeacher(fx_teacher(), self::sem($semesterId)));
        // No semesters at all is not an error, just an empty list.
        $this->assertSame([], ScheduleTimeline::forTeacher(fx_teacher('No', 'Semesters'), []));
    }

    // ── Across semesters ───────────────────────────────────────────────────

    /**
     * A family sees next semester as soon as it is planned: both semesters in
     * one chronological list, each entry tagged with the one it belongs to.
     */
    public function testTimelineSpansSeveralSemestersInOrder(): void
    {
        $teacher = fx_teacher();
        $student = fx_student();
        $locationId = fx_location_id();

        $fall = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2, 'fall', 2030);
        $spring = fx_semester_with_dates($this->ctx, $teacher, '2031-01-25', 2, 'spring', 2031);
        // A holiday in each, so both kinds of entry span semesters.
        SemesterManagement::upsertLocationDate($this->ctx, $fall[0], $locationId, '2030-09-21', '09:00:00', '17:00:00', 'inactive', 'Fall Break');
        SemesterManagement::upsertLocationDate($this->ctx, $spring[0], $locationId, '2031-02-08', '09:00:00', '17:00:00', 'inactive', 'Midwinter Recess');

        foreach ([$fall, $spring] as $setup) {
            ReservationManagement::createReservation($this->ctx, [
                'semester_id' => $setup[0], 'teacher_user_id' => $teacher,
                'location_id' => $locationId, 'student_user_id' => $student,
                'day_of_week' => $setup[3], 'start_time' => '10:00',
                'duration_minutes' => 30, 'status' => 'confirmed',
            ]);
        }

        $semesters = self::sem($fall[0], $spring[0]);
        $entries = ScheduleTimeline::forStudents([$student], $semesters);

        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-14 10:00',
            'holiday 2030-09-21 Fall Break',
            'lesson 2031-01-25 10:00',
            'lesson 2031-02-01 10:00',
            'holiday 2031-02-08 Midwinter Recess',
        ], $this->summarize($entries));

        // Every entry knows its semester, fall before spring.
        $this->assertSame(
            ['Fall 2030', 'Fall 2030', 'Fall 2030', 'Spring 2031', 'Spring 2031', 'Spring 2031'],
            array_column($entries, 'semester_label')
        );
        $this->assertSame(['Fall 2030', 'Spring 2031'], ScheduleTimeline::semesterLabels($entries));

        // A teacher spanning both semesters sees the same span.
        $this->assertSame(
            ['Fall 2030', 'Spring 2031'],
            ScheduleTimeline::semesterLabels(ScheduleTimeline::forTeacher($teacher, $semesters))
        );
    }

    /** The note only names semesters the person actually has something in. */
    public function testSemesterLabelsCoverOnlySemestersWithEntries(): void
    {
        $teacher = fx_teacher();
        $student = fx_student();
        $locationId = fx_location_id();

        $fall = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2, 'fall', 2030);
        $spring = fx_semester_with_dates($this->ctx, $teacher, '2031-01-25', 2, 'spring', 2031);

        // Enrolled in fall only.
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $fall[0], 'teacher_user_id' => $teacher,
            'location_id' => $locationId, 'student_user_id' => $student,
            'day_of_week' => $fall[3], 'start_time' => '10:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ]);

        $entries = ScheduleTimeline::forStudents([$student], self::sem($fall[0], $spring[0]));
        $this->assertSame(['Fall 2030'], ScheduleTimeline::semesterLabels($entries));
    }

    public function testCurrentAndFutureSemestersExcludesFinishedOnes(): void
    {
        $past = SemesterManagement::createSemester($this->ctx, 'spring', 2030, '2030-01-20', '2030-05-20');
        $running = SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $future = SemesterManagement::createSemester($this->ctx, 'spring', 2031, '2031-01-25', '2031-05-23');

        // "Today" mid-Fall: the finished spring drops out, the rest stay in
        // start-date order.
        $ids = array_map('intval', array_column(
            SemesterManagement::currentAndFutureSemesters('2030-10-01'), 'id'
        ));
        $this->assertSame([$running, $future], $ids);

        // On the last day of a semester it is still included.
        $this->assertContains($running, array_map('intval', array_column(
            SemesterManagement::currentAndFutureSemesters('2030-12-20'), 'id'
        )));
        // Before anything starts, everything is upcoming.
        $this->assertCount(3, SemesterManagement::currentAndFutureSemesters('2029-01-01'));
        $this->assertSame([], SemesterManagement::currentAndFutureSemesters('2032-01-01'));
        $this->assertNotContains($past, array_map('intval', array_column(
            SemesterManagement::currentAndFutureSemesters('2030-10-01'), 'id'
        )));
    }
}
