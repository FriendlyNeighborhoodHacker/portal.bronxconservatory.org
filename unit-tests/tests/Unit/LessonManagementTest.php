<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LessonManagementTest extends TestCase
{
    private UserContext $adminCtx;
    private int $teacherId;
    private int $studentId;
    private int $parentId;

    protected function setUp(): void
    {
        test_reset_all();

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at) VALUES ('Admin', 'A', 'admin@example.com', 'h', 1, NOW())");
        $this->adminCtx = new UserContext((int)pdo()->lastInsertId(), true);
        UserContext::set($this->adminCtx);

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Tina', 'Teacher', 'h')");
        $this->teacherId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $this->teacherId . ')');

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Sofia', 'Student', '')");
        $this->studentId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $this->studentId . ')');

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Maria', 'Parent', 'h')");
        $this->parentId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO parenthood (parent_user_id, child_user_id) VALUES (' . $this->parentId . ',' . $this->studentId . ')');
    }

    private function createRecurringWeekly(?string $endDate): int
    {
        return LessonManagement::createRecurring($this->adminCtx, [
            'lesson_type' => 'individual',
            'instrument_id' => 1,
            'teacher_user_id' => $this->teacherId,
            'student_user_id' => $this->studentId,
            'day_of_week' => 6, // Saturday
            'start_time' => '09:00:00',
            'duration_minutes' => 30,
            'start_date' => '2026-08-01', // a Saturday
            'end_date' => $endDate,
        ]);
    }

    public function testGenerateOccurrencesCountAndIdempotency(): void
    {
        $recurringId = $this->createRecurringWeekly(null);

        // Aug 1 2026 is a Saturday; through Aug 31 → Aug 1, 8, 15, 22, 29.
        $created = LessonManagement::generateOccurrencesThrough($this->adminCtx, $recurringId, '2026-08-31');
        $this->assertSame(5, $created);

        // Re-running creates nothing new; extending adds only the tail.
        $this->assertSame(0, LessonManagement::generateOccurrencesThrough($this->adminCtx, $recurringId, '2026-08-31'));
        $this->assertSame(2, LessonManagement::generateOccurrencesThrough($this->adminCtx, $recurringId, '2026-09-13'));

        $lessons = LessonManagement::upcomingLessonsForStudent($this->studentId, '2026-08-01');
        $this->assertCount(7, $lessons);
        $this->assertSame('2026-08-01 09:00:00', $lessons[0]['start_datetime']);
    }

    public function testGenerateRespectsTemplateEndDate(): void
    {
        $recurringId = $this->createRecurringWeekly('2026-08-15');
        $created = LessonManagement::generateOccurrencesThrough($this->adminCtx, $recurringId, '2026-12-31');
        $this->assertSame(3, $created); // Aug 1, 8, 15
    }

    public function testInactiveTemplateGeneratesNothing(): void
    {
        $recurringId = $this->createRecurringWeekly(null);
        LessonManagement::setRecurringActive($this->adminCtx, $recurringId, false);
        $this->assertSame(0, LessonManagement::generateOccurrencesThrough($this->adminCtx, $recurringId, '2026-08-31'));
    }

    public function testSubstituteTeacherOverride(): void
    {
        $lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $this->teacherId,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-01 09:00:00',
        ]);

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Sam', 'Sub', 'h')");
        $subId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $subId . ')');

        LessonManagement::setSubstituteTeacher($this->adminCtx, $lessonId, $subId);
        $lesson = LessonManagement::getLesson($lessonId);
        $this->assertSame($subId, (int)$lesson['substitute_teacher_user_id']);

        // The substitute is an effective teacher; the sub shows up on their day.
        $this->assertTrue(LessonManagement::isEffectiveTeacher($subId, $lesson));
        $this->assertCount(1, LessonManagement::lessonsForTeacherOnDate($subId, '2026-08-01'));

        // The substitute can mark attendance.
        LessonManagement::markAttendance(new UserContext($subId, false), $lessonId, null, true);
        $this->assertSame(1, (int)LessonManagement::getLesson($lessonId)['attended']);
    }

    public function testGroupAttendancePerStudent(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Diego', 'Student2', '')");
        $student2 = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $student2 . ')');

        $lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'group',
            'name' => 'Saturday Ensemble',
            'teacher_user_id' => $this->teacherId,
            'start_datetime' => '2026-08-01 10:00:00',
            'student_user_ids' => [$this->studentId, $student2],
        ]);

        $teacherCtx = new UserContext($this->teacherId, false);
        LessonManagement::markAttendance($teacherCtx, $lessonId, $this->studentId, true);
        LessonManagement::markAttendance($teacherCtx, $lessonId, $student2, false);

        $lesson = LessonManagement::getLesson($lessonId);
        $byStudent = [];
        foreach ($lesson['group_students'] as $gs) {
            $byStudent[(int)$gs['student_user_id']] = $gs['attended'];
        }
        $this->assertSame(1, (int)$byStudent[$this->studentId]);
        $this->assertSame(0, (int)$byStudent[$student2]);

        // A student not on the roster cannot be marked.
        $this->expectException(InvalidArgumentException::class);
        LessonManagement::markAttendance($teacherCtx, $lessonId, 99999, true);
    }

    public function testAttendanceRequiresTheLessonsTeacher(): void
    {
        $lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $this->teacherId,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-01 09:00:00',
        ]);
        $this->expectException(RuntimeException::class);
        LessonManagement::markAttendance(new UserContext($this->parentId, false), $lessonId, null, true);
    }

    public function testCanUserViewLesson(): void
    {
        $lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $this->teacherId,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-01 09:00:00',
        ]);

        $this->assertTrue(LessonManagement::canUserViewLesson($this->teacherId, $lessonId));
        $this->assertTrue(LessonManagement::canUserViewLesson($this->studentId, $lessonId));
        $this->assertTrue(LessonManagement::canUserViewLesson($this->parentId, $lessonId));
        $this->assertTrue(LessonManagement::canUserViewLesson($this->adminCtx->id, $lessonId));

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Stranger', 'S', 'h')");
        $strangerId = (int)pdo()->lastInsertId();
        $this->assertFalse(LessonManagement::canUserViewLesson($strangerId, $lessonId));
    }
}
