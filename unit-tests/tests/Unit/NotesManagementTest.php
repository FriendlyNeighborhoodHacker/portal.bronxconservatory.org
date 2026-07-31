<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotesManagementTest extends TestCase
{
    private UserContext $adminCtx;
    private UserContext $teacherCtx;
    private int $studentId;
    private int $lessonId;

    protected function setUp(): void
    {
        test_reset_all();

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at) VALUES ('Admin', 'A', 'admin@example.com', 'h', 1, NOW())");
        $this->adminCtx = new UserContext((int)pdo()->lastInsertId(), true);
        UserContext::set($this->adminCtx);

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Tina', 'Teacher', 'h')");
        $teacherId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $teacherId . ')');
        $this->teacherCtx = new UserContext($teacherId, false);

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Sofia', 'Student', '')");
        $this->studentId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $this->studentId . ')');

        $this->lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $teacherId,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-01 09:00:00',
        ]);
    }

    public function testLessonNoteAutoSaveUpserts(): void
    {
        $note1 = NotesManagement::saveLessonNote($this->teacherCtx, $this->lessonId, 'Worked on scales.');
        $this->assertSame('Worked on scales.', $note1['body']);

        $note2 = NotesManagement::saveLessonNote($this->teacherCtx, $this->lessonId, 'Worked on scales and arpeggios.');
        $this->assertSame('Worked on scales and arpeggios.', $note2['body']);
        $this->assertSame((int)$note1['id'], (int)$note2['id']); // same row, updated

        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM lesson_notes')->fetchColumn());
    }

    public function testOnlyTheLessonsTeacherMayWriteItsNote(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Other', 'Teacher', 'h')");
        $otherId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $otherId . ')');
        $this->expectException(RuntimeException::class);
        NotesManagement::saveLessonNote(new UserContext($otherId, false), $this->lessonId, 'Not my lesson');
    }

    public function testRecentLessonNotesForStudentNewestFirst(): void
    {
        NotesManagement::saveLessonNote($this->teacherCtx, $this->lessonId, 'First lesson note.');

        $lesson2 = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $this->teacherCtx->id,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-08 09:00:00',
        ]);
        NotesManagement::saveLessonNote($this->teacherCtx, $lesson2, 'Second lesson note.');

        $notes = NotesManagement::recentLessonNotesForStudent($this->studentId);
        $this->assertCount(2, $notes);
        $this->assertSame('Second lesson note.', $notes[0]['body']);
        $this->assertSame('First lesson note.', $notes[1]['body']);
    }

    public function testAdminNotesCategoryAndAccess(): void
    {
        pdo()->exec("INSERT INTO families (family_name) VALUES ('Martinez')");
        $familyId = (int)pdo()->lastInsertId();

        NotesManagement::addNote($this->adminCtx, 'family', $familyId, null, 'Spoke with mom Maria.');
        $notes = NotesManagement::notesForFamily($familyId);
        $this->assertCount(1, $notes);
        $this->assertSame('Spoke with mom Maria.', $notes[0]['body']);

        try {
            NotesManagement::addNote($this->adminCtx, 'bogus', $familyId, null, 'x');
            $this->fail('Expected InvalidArgumentException for bad category');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('category', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        NotesManagement::addNote($this->teacherCtx, 'family', $familyId, null, 'Teachers cannot add admin notes');
    }
}
