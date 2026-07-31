<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * The fixed instrument list (Piano, Guitar, Voice, Violin, Viola, Cello,
 * Double Bass — seeded by schema.sql) and the student/teacher instrument
 * assignments.
 */
class InstrumentCatalog {
    private static function pdo(): PDO {
        return pdo();
    }

    public static function all(): array {
        return self::pdo()->query('SELECT id, name FROM instruments ORDER BY sort_order, name')->fetchAll();
    }

    public static function findByName(string $name): ?array {
        $st = self::pdo()->prepare('SELECT id, name FROM instruments WHERE name = ? LIMIT 1');
        $st->execute([trim($name)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function namesForStudent(int $studentUserId): array {
        $st = self::pdo()->prepare(
            'SELECT i.name FROM student_instruments si
             JOIN instruments i ON i.id = si.instrument_id
             WHERE si.student_user_id = ? ORDER BY i.sort_order'
        );
        $st->execute([$studentUserId]);
        return array_column($st->fetchAll(), 'name');
    }

    public static function namesForTeacher(int $teacherUserId): array {
        $st = self::pdo()->prepare(
            'SELECT i.name FROM teacher_instruments ti
             JOIN instruments i ON i.id = ti.instrument_id
             WHERE ti.teacher_user_id = ? ORDER BY i.sort_order'
        );
        $st->execute([$teacherUserId]);
        return array_column($st->fetchAll(), 'name');
    }

    // Replace a student's instrument set. $ctx may be null (public registration).
    public static function setStudentInstruments(?UserContext $ctx, int $studentUserId, array $instrumentIds): void {
        self::setInstruments('student_instruments', 'student_user_id', $studentUserId, $instrumentIds);
        self::log($ctx, 'student.set_instruments', ['student_user_id' => $studentUserId, 'instrument_ids' => array_values($instrumentIds)]);
    }

    public static function setTeacherInstruments(?UserContext $ctx, int $teacherUserId, array $instrumentIds): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        self::setInstruments('teacher_instruments', 'teacher_user_id', $teacherUserId, $instrumentIds);
        self::log($ctx, 'teacher.set_instruments', ['teacher_user_id' => $teacherUserId, 'instrument_ids' => array_values($instrumentIds)]);
    }

    private static function setInstruments(string $table, string $userColumn, int $userId, array $instrumentIds): void {
        $pdo = self::pdo();
        $pdo->prepare("DELETE FROM $table WHERE $userColumn = ?")->execute([$userId]);
        $insert = $pdo->prepare("INSERT INTO $table ($userColumn, instrument_id) VALUES (?, ?)");
        foreach (array_unique(array_map('intval', $instrumentIds)) as $instrumentId) {
            if ($instrumentId > 0) {
                $insert->execute([$userId, $instrumentId]);
            }
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
