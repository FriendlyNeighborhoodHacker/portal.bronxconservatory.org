<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/InstrumentCatalog.php';

/**
 * Student and teacher profiles (whose existence defines the student/teacher
 * roles) and the parent-child relationships between users.
 */
class StudentTeacherManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // Create or update a student profile. $ctx may be null (public
    // registration). $fields: date_of_birth, class_of, experience_level,
    // school_name, grade — all optional.
    public static function ensureStudentProfile(?UserContext $ctx, int $userId, array $fields = []): void {
        $st = self::pdo()->prepare(
            'INSERT INTO student_profiles (user_id, date_of_birth, class_of, experience_level, school_name, grade)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               date_of_birth = COALESCE(VALUES(date_of_birth), date_of_birth),
               class_of = COALESCE(VALUES(class_of), class_of),
               experience_level = COALESCE(VALUES(experience_level), experience_level),
               school_name = COALESCE(VALUES(school_name), school_name),
               grade = COALESCE(VALUES(grade), grade)'
        );
        $st->execute([
            $userId,
            self::orNull($fields['date_of_birth'] ?? null),
            self::orNull($fields['class_of'] ?? null),
            self::orNull($fields['experience_level'] ?? null),
            self::orNull($fields['school_name'] ?? null),
            self::orNull($fields['grade'] ?? null),
        ]);
        self::log($ctx, 'student.profile_saved', ['user_id' => $userId]);
    }

    public static function ensureTeacherProfile(?UserContext $ctx, int $userId, array $fields = []): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        $st = self::pdo()->prepare(
            'INSERT INTO teacher_profiles (user_id, bio, gender)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE
               bio = COALESCE(VALUES(bio), bio),
               gender = COALESCE(VALUES(gender), gender)'
        );
        $st->execute([
            $userId,
            self::orNull($fields['bio'] ?? null),
            self::orNull($fields['gender'] ?? null),
        ]);
        self::log($ctx, 'teacher.profile_saved', ['user_id' => $userId]);
    }

    public static function removeStudentProfile(?UserContext $ctx, int $userId): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        self::pdo()->prepare('DELETE FROM student_profiles WHERE user_id = ?')->execute([$userId]);
        self::log($ctx, 'student.profile_removed', ['user_id' => $userId]);
    }

    public static function removeTeacherProfile(?UserContext $ctx, int $userId): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        self::pdo()->prepare('DELETE FROM teacher_profiles WHERE user_id = ?')->execute([$userId]);
        self::log($ctx, 'teacher.profile_removed', ['user_id' => $userId]);
    }

    // Link a parent to a child. $ctx may be null (public registration).
    // $role: mother | father | guardian | null.
    public static function linkParentChild(?UserContext $ctx, int $parentUserId, int $childUserId, ?string $role = null): void {
        $st = self::pdo()->prepare(
            'INSERT INTO parenthood (parent_user_id, child_user_id, role) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        );
        $st->execute([$parentUserId, $childUserId, self::orNull($role)]);
        self::log($ctx, 'parenthood.linked', ['parent_user_id' => $parentUserId, 'child_user_id' => $childUserId, 'role' => $role]);
    }

    // A parent's children with their student profile and instrument names.
    public static function childrenOfParent(int $parentUserId): array {
        $st = self::pdo()->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.preferred_name, u.photo_public_file_id,
                    sp.date_of_birth, sp.experience_level, sp.school_name, sp.grade
             FROM parenthood ph
             JOIN users u ON u.id = ph.child_user_id
             LEFT JOIN student_profiles sp ON sp.user_id = u.id
             WHERE ph.parent_user_id = ?
             ORDER BY u.first_name, u.last_name'
        );
        $st->execute([$parentUserId]);
        $children = $st->fetchAll();
        foreach ($children as &$child) {
            $child['instruments'] = InstrumentCatalog::namesForStudent((int)$child['id']);
        }
        return $children;
    }

    public static function parentsOfStudent(int $studentUserId): array {
        $st = self::pdo()->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.cell_phone, ph.role
             FROM parenthood ph
             JOIN users u ON u.id = ph.parent_user_id
             WHERE ph.child_user_id = ?
             ORDER BY u.first_name'
        );
        $st->execute([$studentUserId]);
        return $st->fetchAll();
    }

    public static function isParentOf(int $parentUserId, int $studentUserId): bool {
        $st = self::pdo()->prepare('SELECT 1 FROM parenthood WHERE parent_user_id = ? AND child_user_id = ?');
        $st->execute([$parentUserId, $studentUserId]);
        return (bool)$st->fetchColumn();
    }

    public static function listTeachers(): array {
        $teachers = self::pdo()->query(
            'SELECT u.id, u.first_name, u.last_name, tp.gender
             FROM teacher_profiles tp JOIN users u ON u.id = tp.user_id
             ORDER BY u.first_name, u.last_name'
        )->fetchAll();
        foreach ($teachers as &$t) {
            $t['instruments'] = InstrumentCatalog::namesForTeacher((int)$t['id']);
        }
        return $teachers;
    }

    public static function listStudents(): array {
        return self::pdo()->query(
            'SELECT u.id, u.first_name, u.last_name, u.family_id
             FROM student_profiles sp JOIN users u ON u.id = sp.user_id
             ORDER BY u.first_name, u.last_name'
        )->fetchAll();
    }

    private static function orNull($v): ?string {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
