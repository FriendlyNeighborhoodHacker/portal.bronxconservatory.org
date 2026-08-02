<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LessonManagement.php';

/**
 * Lesson notes: what was said about a lesson, by its teacher, by an admin, or
 * by the family. Each note is its own row and stays put — it is signed with
 * its author and the time it was written, so a lesson carries a short thread
 * anyone who may see the lesson can read and add to.
 *
 * Everyone who may see a lesson may write on it (LessonManagement::
 * canUserViewLesson). A parent asking "she was ill, can we make this up?" is
 * as much a note about the lesson as the teacher's account of it, and both
 * belong in the same place.
 */
class NotesManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * Add a note to a lesson. Returns the saved row (with its author's name,
     * ready to render). Empty notes are refused rather than stored — the box
     * being blank is not something worth saying.
     */
    public static function addLessonNote(?UserContext $ctx, int $lessonId, string $body): array {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('Write something before saving the note.');
        }
        if (!LessonManagement::getLesson($lessonId)) {
            throw new InvalidArgumentException('Lesson not found.');
        }
        if (!LessonManagement::canUserViewLesson($ctx->id, $lessonId)) {
            throw new RuntimeException('This lesson is not yours to write on.');
        }

        self::pdo()->prepare('INSERT INTO lesson_notes (lesson_id, created_by_user_id, body) VALUES (?,?,?)')
            ->execute([$lessonId, $ctx->id, $body]);
        $id = (int)self::pdo()->lastInsertId();

        self::log($ctx, 'lesson_note.added', ['lesson_id' => $lessonId, 'lesson_note_id' => $id]);

        $st = self::pdo()->prepare(
            'SELECT ln.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM lesson_notes ln JOIN users u ON u.id = ln.created_by_user_id
             WHERE ln.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        return $st->fetch() ?: [];
    }

    /** A lesson's notes, with author names, in the order they were written. */
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

    // A student's lesson notes, newest lesson first — the student and parent
    // dashboards' notes cards. Several notes on one lesson come back newest
    // first among themselves.
    public static function recentLessonNotesForStudent(int $studentUserId, int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        $st = self::pdo()->prepare(
            "SELECT ln.*, l.start_datetime,
                    a.first_name AS author_first_name, a.last_name AS author_last_name
             FROM lesson_notes ln
             JOIN lessons l ON l.id = ln.lesson_id
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             JOIN users a ON a.id = ln.created_by_user_id
             WHERE ln.body <> '' AND COALESCE(r.student_user_id, l.student_user_id) = ?
             ORDER BY l.start_datetime DESC, ln.created_at DESC, ln.id DESC
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
