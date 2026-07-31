<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LessonManagement.php';

/**
 * Two kinds of notes:
 *  - internal admin notes about a family or person (notes table; the action
 *    queue's "Spoke with mom Maria..." entries) — admin-only;
 *  - lesson notes a teacher writes after a lesson (lesson_notes table) —
 *    visible to the student, their parents, and admins. One row per teacher
 *    per lesson; the teacher dashboard's auto-save upserts it.
 */
class NotesManagement {

    public const CATEGORIES = ['family', 'student', 'teacher', 'parent'];

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Internal admin notes =====

    public static function addNote(?UserContext $ctx, string $category, ?int $familyId, ?int $userId, string $body): int {
        self::assertAdmin($ctx);
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unknown note category: ' . $category);
        }
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Note text is required.');
        }
        self::pdo()->prepare(
            'INSERT INTO notes (category, family_id, user_id, body, created_by_user_id) VALUES (?,?,?,?,?)'
        )->execute([$category, $familyId, $userId, $body, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'note.added', ['note_id' => $id, 'category' => $category, 'family_id' => $familyId, 'user_id' => $userId]);
        return $id;
    }

    public static function notesForFamily(int $familyId): array {
        $st = self::pdo()->prepare(
            'SELECT n.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM notes n LEFT JOIN users u ON u.id = n.created_by_user_id
             WHERE n.family_id = ? ORDER BY n.created_at DESC, n.id DESC'
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    public static function notesForUser(int $userId): array {
        $st = self::pdo()->prepare(
            'SELECT n.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM notes n LEFT JOIN users u ON u.id = n.created_by_user_id
             WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    // ===== Lesson notes =====

    // Upsert the teacher's note for a lesson (the dashboard auto-save).
    // Returns the saved row. Only the lesson's effective teacher or an admin
    // may write.
    public static function saveLessonNote(?UserContext $ctx, int $lessonId, string $body): array {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $lesson = LessonManagement::getLesson($lessonId);
        if (!$lesson) {
            throw new InvalidArgumentException('Lesson not found.');
        }
        if (!$ctx->admin && !LessonManagement::isEffectiveTeacher($ctx->id, $lesson)) {
            throw new RuntimeException('Only the lesson\'s teacher may write its note.');
        }

        self::pdo()->prepare(
            'INSERT INTO lesson_notes (lesson_id, teacher_user_id, body) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE body = VALUES(body)'
        )->execute([$lessonId, $ctx->id, trim($body)]);

        self::log($ctx, 'lesson_note.saved', ['lesson_id' => $lessonId]);

        $st = self::pdo()->prepare('SELECT * FROM lesson_notes WHERE lesson_id=? AND teacher_user_id=? LIMIT 1');
        $st->execute([$lessonId, $ctx->id]);
        return $st->fetch() ?: [];
    }

    public static function lessonNoteFor(int $lessonId, int $teacherUserId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM lesson_notes WHERE lesson_id=? AND teacher_user_id=? LIMIT 1');
        $st->execute([$lessonId, $teacherUserId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // A student's teacher notes, newest first — the student/parent dashboards.
    public static function recentLessonNotesForStudent(int $studentUserId, int $limit = 10): array {
        $st = self::pdo()->prepare(
            'SELECT ln.*, l.start_datetime, l.lesson_type, l.name AS lesson_name,
                    i.name AS instrument_name,
                    t.first_name AS teacher_first_name, t.last_name AS teacher_last_name
             FROM lesson_notes ln
             JOIN lessons l ON l.id = ln.lesson_id
             LEFT JOIN instruments i ON i.id = l.instrument_id
             JOIN users t ON t.id = ln.teacher_user_id
             WHERE ln.body <> \'\'
               AND (l.student_user_id = ?
                    OR EXISTS (SELECT 1 FROM group_lesson_students gls
                               WHERE gls.lesson_id = l.id AND gls.student_user_id = ?))
             ORDER BY l.start_datetime DESC
             LIMIT ' . (int)$limit
        );
        $st->execute([$studentUserId, $studentUserId]);
        return $st->fetchAll();
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
