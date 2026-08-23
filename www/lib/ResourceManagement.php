<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/LessonManagement.php';

/**
 * Lesson resources: an uploaded file (voice recording, sheet music — stored
 * in private_files) or an external link, always attached to a lesson.
 * File downloads go through resource_download.php, which asks
 * canUserDownload(); visibility follows the lesson's reservation
 * (student / parents / effective teacher / admins).
 */
class ResourceManagement {

    public const MAX_BYTES = 50 * 1024 * 1024; // recordings can be large

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/ogg',
        'video/mp4', 'video/quicktime',
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * Store an uploaded file ($_FILES entry) as a resource on a lesson.
     * Anyone who may see the lesson may add materials (like notes).
     */
    public static function addFileResource(?UserContext $ctx, int $lessonId, string $title, array $file): int {
        $lesson = self::assertCanAddToLesson($ctx, $lessonId);

        $title = trim($title);
        if ($title === '') {
            $title = (string)($file['name'] ?? 'Material');
        }

        $fileId = Files::storeUploadedPrivateFile($ctx->id, $file, self::MAX_BYTES, self::ALLOWED_MIME_TYPES);
        self::pdo()->prepare(
            "INSERT INTO lesson_resources (lesson_id, resource_type, title, private_file_id, created_by_user_id)
             VALUES (?,'file',?,?,?)"
        )->execute([$lessonId, $title, $fileId, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'resource.file_added', ['resource_id' => $id, 'lesson_id' => $lessonId]);
        return $id;
    }

    /** Attach an external link (YouTube practice video, sheet music page...). */
    public static function addLinkResource(?UserContext $ctx, int $lessonId, string $title, string $url): int {
        self::assertCanAddToLesson($ctx, $lessonId);

        $title = trim($title);
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('A valid http(s) link is required.');
        }
        if ($title === '') {
            $title = $url;
        }

        self::pdo()->prepare(
            "INSERT INTO lesson_resources (lesson_id, resource_type, title, url, created_by_user_id)
             VALUES (?,'link',?,?,?)"
        )->execute([$lessonId, $title, $url, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'resource.link_added', ['resource_id' => $id, 'lesson_id' => $lessonId, 'url' => $url]);
        return $id;
    }

    /**
     * May this person remove this material? Its creator or an admin — the
     * same rule deleteResource() enforces, exposed so the edit UI can offer
     * a Remove control only where pressing it would actually work.
     */
    public static function canUserDelete(?UserContext $ctx, array $resource): bool {
        if (!$ctx) {
            return false;
        }
        return $ctx->admin || (int)($resource['created_by_user_id'] ?? 0) === $ctx->id;
    }

    /** Remove a resource (its creator or an admin). The private file goes too. */
    public static function deleteResource(?UserContext $ctx, int $resourceId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $resource = self::find($resourceId);
        if (!$resource) {
            throw new InvalidArgumentException('Resource not found.');
        }
        if (!self::canUserDelete($ctx, $resource)) {
            throw new RuntimeException('Only the person who added a material may remove it.');
        }
        self::pdo()->prepare('DELETE FROM lesson_resources WHERE id=?')->execute([$resourceId]);
        if (!empty($resource['private_file_id'])) {
            Files::deletePrivateFile((int)$resource['private_file_id']);
        }
        self::log($ctx, 'resource.deleted', ['resource_id' => $resourceId]);
    }

    public static function find(int $resourceId): ?array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length
             FROM lesson_resources lr
             LEFT JOIN private_files pf ON pf.id = lr.private_file_id
             WHERE lr.id = ? LIMIT 1'
        );
        $st->execute([$resourceId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function resourcesForLesson(int $lessonId): array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length,
                    up.first_name AS uploader_first_name, up.last_name AS uploader_last_name
             FROM lesson_resources lr
             LEFT JOIN private_files pf ON pf.id = lr.private_file_id
             LEFT JOIN users up ON up.id = lr.created_by_user_id
             WHERE lr.lesson_id = ? ORDER BY lr.created_at DESC, lr.id DESC'
        );
        $st->execute([$lessonId]);
        return $st->fetchAll();
    }

    /**
     * How many materials each of these lessons has, as [lesson_id => count],
     * in one query — the companion to NotesManagement::noteCountsForLessons
     * for schedule views. Lessons with no materials are absent from the map.
     */
    public static function resourceCountsForLessons(array $lessonIds): array {
        $ids = array_values(array_unique(array_map('intval', $lessonIds)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare(
            "SELECT lesson_id, COUNT(*) AS n FROM lesson_resources
             WHERE lesson_id IN ($placeholders)
             GROUP BY lesson_id"
        );
        $st->execute($ids);
        $counts = [];
        foreach ($st->fetchAll() as $row) {
            $counts[(int)$row['lesson_id']] = (int)$row['n'];
        }
        return $counts;
    }

    /**
     * A student's materials for a semester ("My Materials"), in chronological
     * order of the lesson each is attached to.
     */
    public static function resourcesForStudentInSemester(int $studentUserId, int $semesterId): array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length,
                    l.start_datetime AS lesson_datetime, l.lesson_number,
                    up.first_name AS uploader_first_name, up.last_name AS uploader_last_name
             FROM lesson_resources lr
             LEFT JOIN private_files pf ON pf.id = lr.private_file_id
             JOIN lessons l ON l.id = lr.lesson_id
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             LEFT JOIN users up ON up.id = lr.created_by_user_id
             WHERE COALESCE(r.student_user_id, l.student_user_id) = ?
               AND COALESCE(r.semester_id, l.semester_id) = ?
             ORDER BY l.start_datetime, lr.id'
        );
        $st->execute([$studentUserId, $semesterId]);
        return $st->fetchAll();
    }

    // Download authorization: admins; the creator; anyone who may view the
    // attached lesson (student / parents / effective teacher).
    public static function canUserDownload(int $userId, int $resourceId): bool {
        $resource = self::find($resourceId);
        if (!$resource) {
            return false;
        }
        if ((int)($resource['created_by_user_id'] ?? 0) === $userId) {
            return true;
        }
        return LessonManagement::canUserViewLesson($userId, (int)$resource['lesson_id']);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @return array the lesson row */
    private static function assertCanAddToLesson(?UserContext $ctx, int $lessonId): array {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $lesson = LessonManagement::getLesson($lessonId);
        if (!$lesson) {
            throw new InvalidArgumentException('Lesson not found.');
        }
        // Same circle as notes: anyone who may see the lesson may add to it —
        // its teacher, an admin, the student, and their parents — so a student
        // can attach their own practice recording to the thread.
        if (!$ctx->admin && !LessonManagement::canUserViewLesson($ctx->id, $lessonId)) {
            throw new RuntimeException('Only people on this lesson may add materials.');
        }
        return $lesson;
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
