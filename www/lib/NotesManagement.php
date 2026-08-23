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

    /**
     * Notes for a set of lessons in one query, newest first within each
     * lesson — the raw material for a "most recent note" preview that a
     * caller groups by lesson_id, so a page listing several lessons does not
     * pay one query per lesson.
     */
    public static function notesForLessons(array $lessonIds): array {
        $ids = array_values(array_unique(array_map('intval', $lessonIds)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare(
            "SELECT ln.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM lesson_notes ln
             JOIN users u ON u.id = ln.created_by_user_id
             WHERE ln.lesson_id IN ($placeholders) AND ln.body <> ''
             ORDER BY ln.lesson_id, ln.created_at DESC, ln.id DESC"
        );
        $st->execute($ids);
        return $st->fetchAll();
    }

    /**
     * How many notes each of these lessons has, as [lesson_id => count], in
     * one query — for the schedule views that show a count next to each
     * lesson so a family knows whether opening it is worth the tap. Lessons
     * with no notes are simply absent from the map.
     */
    public static function noteCountsForLessons(array $lessonIds): array {
        $ids = array_values(array_unique(array_map('intval', $lessonIds)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare(
            "SELECT lesson_id, COUNT(*) AS n FROM lesson_notes
             WHERE lesson_id IN ($placeholders) AND body <> ''
             GROUP BY lesson_id"
        );
        $st->execute($ids);
        $counts = [];
        foreach ($st->fetchAll() as $row) {
            $counts[(int)$row['lesson_id']] = (int)$row['n'];
        }
        return $counts;
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

    /**
     * Every lesson note for a student, newest lesson first (several notes on
     * one lesson come back newest first among themselves) — the admin's
     * all-notes page. $semesterId narrows to one semester.
     */
    public static function allLessonNotesForStudent(int $studentUserId, ?int $semesterId = null): array {
        $sql = "SELECT ln.*, l.start_datetime,
                    a.first_name AS author_first_name, a.last_name AS author_last_name
             FROM lesson_notes ln
             JOIN lessons l ON l.id = ln.lesson_id
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             JOIN users a ON a.id = ln.created_by_user_id
             WHERE ln.body <> '' AND COALESCE(r.student_user_id, l.student_user_id) = ?";
        $args = [$studentUserId];
        if ($semesterId !== null) {
            $sql .= ' AND COALESCE(r.semester_id, l.semester_id) = ?';
            $args[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY l.start_datetime DESC, ln.created_at DESC, ln.id DESC');
        $st->execute($args);
        return $st->fetchAll();
    }

    /**
     * The one-line summary the student page shows: how many lesson notes the
     * student has (optionally within one semester) and the date of the most
     * recent noted lesson. ['count' => int, 'last_lesson_date' => ?string].
     */
    public static function lessonNoteSummaryForStudent(int $studentUserId, ?int $semesterId = null): array {
        $sql = "SELECT COUNT(*) AS n, MAX(l.start_datetime) AS last_lesson_date
             FROM lesson_notes ln
             JOIN lessons l ON l.id = ln.lesson_id
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE ln.body <> '' AND COALESCE(r.student_user_id, l.student_user_id) = ?";
        $args = [$studentUserId];
        if ($semesterId !== null) {
            $sql .= ' AND COALESCE(r.semester_id, l.semester_id) = ?';
            $args[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $row = $st->fetch() ?: [];
        $count = (int)($row['n'] ?? 0);
        return [
            'count' => $count,
            'last_lesson_date' => $count > 0 && $row['last_lesson_date'] !== null
                ? (string)$row['last_lesson_date'] : null,
        ];
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
