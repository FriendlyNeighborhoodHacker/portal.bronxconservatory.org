<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotesManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    private function makeLessons(int $weeks = 2): array
    {
        $teacher = fx_teacher();
        $student = fx_student();
        $setup = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', $weeks);
        [$semesterId, $locationId, , $dayOfWeek] = $setup;
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacher,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'status' => 'confirmed',
        ]);
        $st = pdo()->query('SELECT id FROM lessons ORDER BY start_datetime');
        return [$teacher, $student, array_map('intval', array_column($st->fetchAll(), 'id'))];
    }

    public function testAutoSaveUpsertsPerAuthor(): void
    {
        [$teacher, , $lessonIds] = $this->makeLessons();
        $teacherCtx = new UserContext($teacher, false);

        $saved = NotesManagement::saveLessonNote($teacherCtx, $lessonIds[0], 'Worked on scales.');
        $this->assertSame('Worked on scales.', $saved['body']);
        $saved = NotesManagement::saveLessonNote($teacherCtx, $lessonIds[0], 'Worked on scales and arpeggios.');
        $this->assertSame('Worked on scales and arpeggios.', $saved['body']);

        // An admin's note is a second row, not an overwrite.
        NotesManagement::saveLessonNote($this->ctx, $lessonIds[0], 'Please collect the signed form.');
        $notes = NotesManagement::lessonNotesForLesson($lessonIds[0]);
        $this->assertCount(2, $notes);
    }

    public function testOnlyTheLessonsTeacherOrAdminMayWrite(): void
    {
        [, , $lessonIds] = $this->makeLessons();
        $other = fx_teacher('Olga', 'Other');
        $this->expectException(RuntimeException::class);
        NotesManagement::saveLessonNote(new UserContext($other, false), $lessonIds[0], 'Nope.');
    }

    public function testRecentNotesForStudentNewestLessonFirst(): void
    {
        [$teacher, $student, $lessonIds] = $this->makeLessons();
        $teacherCtx = new UserContext($teacher, false);
        NotesManagement::saveLessonNote($teacherCtx, $lessonIds[0], 'Week one.');
        NotesManagement::saveLessonNote($teacherCtx, $lessonIds[1], 'Week two.');
        NotesManagement::saveLessonNote($teacherCtx, $lessonIds[1], ''); // emptied notes are hidden... this empties week two

        $notes = NotesManagement::recentLessonNotesForStudent($student);
        $this->assertCount(1, $notes);
        $this->assertSame('Week one.', $notes[0]['body']);

        NotesManagement::saveLessonNote($teacherCtx, $lessonIds[1], 'Week two, really.');
        $notes = NotesManagement::recentLessonNotesForStudent($student);
        $this->assertCount(2, $notes);
        $this->assertSame('Week two, really.', $notes[0]['body']);
    }
}
