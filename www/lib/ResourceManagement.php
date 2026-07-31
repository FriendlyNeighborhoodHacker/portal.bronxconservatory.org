<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';

/**
 * Lesson resources: voice recordings, sheet music, practice materials.
 * The binary lives in private_files; downloads go through
 * resource_download.php, which asks canUserDownload().
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
     * Store an uploaded file ($_FILES entry) as a resource attached to a
     * lesson and/or directly to a student. Teachers may upload for their own
     * lessons/students; admins for anyone.
     */
    public static function addResource(?UserContext $ctx, ?int $lessonId, ?int $studentUserId, string $title, array $file): int {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if ($lessonId === null && $studentUserId === null) {
            throw new InvalidArgumentException('A resource must attach to a lesson or a student.');
        }
        if ($lessonId !== null) {
            $lesson = LessonManagement::getLesson($lessonId);
            if (!$lesson) {
                throw new InvalidArgumentException('Lesson not found.');
            }
            if (!$ctx->admin && !LessonManagement::isEffectiveTeacher($ctx->id, $lesson)) {
                throw new RuntimeException('Only the lesson\'s teacher may add materials.');
            }
            // A lesson-attached resource for an individual lesson defaults to
            // that student, so it shows in "My Materials".
            if ($studentUserId === null && !empty($lesson['student_user_id'])) {
                $studentUserId = (int)$lesson['student_user_id'];
            }
        } elseif (!$ctx->admin) {
            // Student-only attachment: any teacher may share materials with a
            // student (kept simple for the draft).
            $isTeacher = (bool)self::pdo()->query('SELECT 1 FROM teacher_profiles WHERE user_id = ' . (int)$ctx->id)->fetchColumn();
            if (!$isTeacher) {
                throw new RuntimeException('Only teachers and admins may add materials.');
            }
        }

        $title = trim($title);
        if ($title === '') {
            $title = (string)($file['name'] ?? 'Material');
        }

        $fileId = Files::storeUploadedPrivateFile($ctx->id, $file, self::MAX_BYTES, self::ALLOWED_MIME_TYPES);
        self::pdo()->prepare(
            'INSERT INTO lesson_resources (lesson_id, student_user_id, title, private_file_id, uploaded_by_user_id)
             VALUES (?,?,?,?,?)'
        )->execute([$lessonId, $studentUserId, $title, $fileId, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'resource.added', ['resource_id' => $id, 'lesson_id' => $lessonId, 'student_user_id' => $studentUserId]);
        return $id;
    }

    public static function find(int $resourceId): ?array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length
             FROM lesson_resources lr JOIN private_files pf ON pf.id = lr.private_file_id
             WHERE lr.id = ? LIMIT 1'
        );
        $st->execute([$resourceId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // A student's materials (attached directly or via their lessons), newest
    // first.
    public static function resourcesForStudent(int $studentUserId): array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length,
                    l.start_datetime AS lesson_datetime,
                    up.first_name AS uploader_first_name, up.last_name AS uploader_last_name
             FROM lesson_resources lr
             JOIN private_files pf ON pf.id = lr.private_file_id
             LEFT JOIN lessons l ON l.id = lr.lesson_id
             LEFT JOIN users up ON up.id = lr.uploaded_by_user_id
             WHERE lr.student_user_id = ?
                OR (lr.lesson_id IS NOT NULL AND EXISTS (
                      SELECT 1 FROM group_lesson_students gls
                      WHERE gls.lesson_id = lr.lesson_id AND gls.student_user_id = ?))
             ORDER BY lr.created_at DESC, lr.id DESC'
        );
        $st->execute([$studentUserId, $studentUserId]);
        return $st->fetchAll();
    }

    public static function resourcesForLesson(int $lessonId): array {
        $st = self::pdo()->prepare(
            'SELECT lr.*, pf.original_filename, pf.content_type, pf.byte_length
             FROM lesson_resources lr JOIN private_files pf ON pf.id = lr.private_file_id
             WHERE lr.lesson_id = ? ORDER BY lr.created_at DESC, lr.id DESC'
        );
        $st->execute([$lessonId]);
        return $st->fetchAll();
    }

    // Download authorization: admins; the uploader; the student it belongs
    // to; that student's parents; anyone who may view the attached lesson.
    public static function canUserDownload(int $userId, int $resourceId): bool {
        $resource = self::find($resourceId);
        if (!$resource) {
            return false;
        }
        $user = UserManagement::findById($userId);
        if ($user && !empty($user['is_admin'])) {
            return true;
        }
        if ((int)($resource['uploaded_by_user_id'] ?? 0) === $userId) {
            return true;
        }
        $studentId = (int)($resource['student_user_id'] ?? 0);
        if ($studentId) {
            if ($studentId === $userId || StudentTeacherManagement::isParentOf($userId, $studentId)) {
                return true;
            }
        }
        if (!empty($resource['lesson_id'])) {
            return LessonManagement::canUserViewLesson($userId, (int)$resource['lesson_id']);
        }
        return false;
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
