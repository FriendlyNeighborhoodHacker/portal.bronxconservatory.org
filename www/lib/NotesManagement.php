<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LessonManagement.php';

/**
 * Lesson notes: written after a lesson by its teacher (the dashboard's
 * auto-save) or by an admin (the calendar's lesson modal). Visible to the
 * student, their parents, and admins. One row per author per lesson; saving
 * again upserts.
 */
class NotesManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    // Upsert the caller's note for a lesson. Returns the saved row. Only the
    // lesson's effective teacher or an admin may write.
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
            'INSERT INTO lesson_notes (lesson_id, created_by_user_id, body) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE body = VALUES(body)'
        )->execute([$lessonId, $ctx->id, trim($body)]);

        self::log($ctx, 'lesson_note.saved', ['lesson_id' => $lessonId]);

        $st = self::pdo()->prepare('SELECT * FROM lesson_notes WHERE lesson_id=? AND created_by_user_id=? LIMIT 1');
        $st->execute([$lessonId, $ctx->id]);
        return $st->fetch() ?: [];
    }

    /** One author's note for a lesson (the auto-save box's current value). */
    public static function lessonNoteFor(int $lessonId, int $authorUserId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM lesson_notes WHERE lesson_id=? AND created_by_user_id=? LIMIT 1');
        $st->execute([$lessonId, $authorUserId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** All non-empty notes on a lesson, with author names, oldest first. */
    public static function lessonNotesForLesson(int $lessonId): array {
        $st = self::pdo()->prepare(
            "SELECT ln.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM lesson_notes ln
             JOIN users u ON u.id = ln.created_by_user_id
             WHERE ln.lesson_id = ? AND ln.body <> ''
             ORDER BY ln.created_at, ln.id"
        );
        $st->execute([$lessonId]);
        return $st->fetchAll();
    }

    // A student's lesson notes, newest lesson first — the student/parent
    // dashboards' "Teacher Notes" cards.
    public static function recentLessonNotesForStudent(int $studentUserId, int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        $st = self::pdo()->prepare(
            "SELECT ln.*, l.start_datetime,
                    a.first_name AS teacher_first_name, a.last_name AS teacher_last_name
             FROM lesson_notes ln
             JOIN lessons l ON l.id = ln.lesson_id
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             JOIN users a ON a.id = ln.created_by_user_id
             WHERE ln.body <> '' AND COALESCE(r.student_user_id, l.student_user_id) = ?
             ORDER BY l.start_datetime DESC
             LIMIT $limit"
        );
        $st->execute([$studentUserId]);
        return $st->fetchAll();
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
