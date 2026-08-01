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

        $entries = ScheduleTimeline::forStudents([$student], $semesterId);

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

        $entries = ScheduleTimeline::forTeacher($teacher, $semesterId);

        // Both students on each class day, one holiday notice for the break.
        $this->assertSame([
            'lesson 2030-09-07 10:00',
            'lesson 2030-09-07 11:00',
            'holiday 2030-09-14 Holiday Week',
            'lesson 2030-09-21 10:00',
            'lesson 2030-09-21 11:00',
        ], $this->summarize($entries));

        // A different teacher's timeline is empty.
        $this->assertSame([], ScheduleTimeline::forTeacher(fx_teacher('Other', 'Teacher'), $semesterId));
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

        $entries = ScheduleTimeline::forStudents([$siblingA, $siblingB], $semesterId);

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
        ], $this->summarize(ScheduleTimeline::forStudents([$student], $semesterId)));
    }

    public function testEmptyForSomeoneWithNothingScheduled(): void
    {
        $semesterId = fx_semester($this->ctx);
        $this->assertSame([], ScheduleTimeline::forStudents([fx_student()], $semesterId));
        $this->assertSame([], ScheduleTimeline::forStudents([], $semesterId));
        $this->assertSame([], ScheduleTimeline::forTeacher(fx_teacher(), $semesterId));
    }
}
