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

    public function testEveryNoteIsKeptAndSigned(): void
    {
        [$teacher, , $lessonIds] = $this->makeLessons();
        $teacherCtx = new UserContext($teacher, false);

        $saved = NotesManagement::addLessonNote($teacherCtx, $lessonIds[0], 'Worked on scales.');
        $this->assertSame('Worked on scales.', $saved['body']);
        $this->assertSame('Tess', $saved['author_first_name']);

        // A second note from the same author is a second note, not an edit —
        // this is a thread, not a box.
        NotesManagement::addLessonNote($teacherCtx, $lessonIds[0], 'Also started Suzuki book 2.');
        NotesManagement::addLessonNote($this->ctx, $lessonIds[0], 'Please collect the signed form.');

        $notes = NotesManagement::lessonNotesForLesson($lessonIds[0]);
        $this->assertCount(3, $notes);
        $this->assertSame(
            ['Worked on scales.', 'Also started Suzuki book 2.', 'Please collect the signed form.'],
            array_column($notes, 'body')
        );
    }

    public function testTheFamilyMayWriteToo(): void
    {
        [, $student, $lessonIds] = $this->makeLessons();
        $parent = fx_parent_of($student);

        NotesManagement::addLessonNote(new UserContext($parent, false), $lessonIds[0], 'She has a cold — can we make this up?');
        NotesManagement::addLessonNote(new UserContext($student, false), $lessonIds[0], 'Practised 20 minutes a day.');

        $this->assertCount(2, NotesManagement::lessonNotesForLesson($lessonIds[0]));
    }

    public function testStrangersMayNotWriteAndEmptyNotesAreRefused(): void
    {
        [$teacher, , $lessonIds] = $this->makeLessons();
        $other = fx_teacher('Olga', 'Other');

        try {
            NotesManagement::addLessonNote(new UserContext($other, false), $lessonIds[0], 'Nope.');
            $this->fail('A teacher with no claim on the lesson should not be able to write on it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not yours', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        NotesManagement::addLessonNote(new UserContext($teacher, false), $lessonIds[0], '   ');
    }

    public function testRecentNotesForStudentNewestFirst(): void
    {
        [$teacher, $student, $lessonIds] = $this->makeLessons();
        $teacherCtx = new UserContext($teacher, false);
        NotesManagement::addLessonNote($teacherCtx, $lessonIds[0], 'Week one.');
        NotesManagement::addLessonNote($teacherCtx, $lessonIds[1], 'Week two.');
        NotesManagement::addLessonNote($teacherCtx, $lessonIds[1], 'Week two, afterthought.');

        $notes = NotesManagement::recentLessonNotesForStudent($student);
        $this->assertCount(3, $notes);
        // Newest lesson first, and within it the newest note.
        $this->assertSame('Week two, afterthought.', $notes[0]['body']);
        $this->assertSame('Week two.', $notes[1]['body']);
        $this->assertSame('Week one.', $notes[2]['body']);
        $this->assertSame('Tess', $notes[0]['author_first_name']);
    }
}
