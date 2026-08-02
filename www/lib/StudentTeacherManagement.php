<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/InstrumentCatalog.php';
require_once __DIR__ . '/KeywordSearch.php';

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

    public static function unlinkParentChild(?UserContext $ctx, int $parentUserId, int $childUserId): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        self::pdo()->prepare('DELETE FROM parenthood WHERE parent_user_id = ? AND child_user_id = ?')
            ->execute([$parentUserId, $childUserId]);
        self::log($ctx, 'parenthood.unlinked', ['parent_user_id' => $parentUserId, 'child_user_id' => $childUserId]);
    }

    public static function listTeachers(): array {
        $teachers = self::pdo()->query(
            'SELECT u.id, u.first_name, u.last_name, tp.gender
             FROM teacher_profiles tp JOIN users u ON u.id = tp.user_id
             WHERE u.is_deleted = 0
             ORDER BY u.first_name, u.last_name'
        )->fetchAll();
        foreach ($teachers as &$t) {
            $t['instruments'] = InstrumentCatalog::namesForTeacher((int)$t['id']);
        }
        return $teachers;
    }

    public static function listStudents(): array {
        return self::pdo()->query(
            'SELECT u.id, u.first_name, u.last_name
             FROM student_profiles sp JOIN users u ON u.id = sp.user_id
             WHERE u.is_deleted = 0
             ORDER BY u.first_name, u.last_name'
        )->fetchAll();
    }

    /**
     * The admin Students list: filter by keyword (prefix tokens over the
     * student's and their parents' names, phone numbers, and address line),
     * by teacher (has a reservation with them in $semesterId), and by
     * instrument. Each row carries the student's parents and instruments.
     */
    public static function listStudentsFiltered(string $keyword = '', ?int $teacherUserId = null, ?int $instrumentId = null, ?int $semesterId = null): array {
        $sql = 'SELECT DISTINCT u.id, u.first_name, u.last_name, u.preferred_name, u.photo_public_file_id
                FROM student_profiles sp
                JOIN users u ON u.id = sp.user_id
                WHERE u.is_deleted = 0';
        $params = [];

        foreach (KeywordSearch::tokens($keyword) as $i => $token) {
            $like = $token . '%';
            $student = KeywordSearch::likeAnyClause(
                ['u.first_name', 'u.last_name', 'u.preferred_name',
                 'u.cell_phone', 'u.home_phone', 'u.address_street_1'],
                $like, "s{$i}", $params
            );
            $parent = KeywordSearch::likeAnyClause(
                ['pu.first_name', 'pu.last_name', 'pu.preferred_name',
                 'pu.cell_phone', 'pu.home_phone', 'pu.address_street_1'],
                $like, "p{$i}", $params
            );
            $sql .= " AND ({$student}
                OR EXISTS (
                    SELECT 1 FROM parenthood phk
                    JOIN users pu ON pu.id = phk.parent_user_id
                    WHERE phk.child_user_id = u.id AND pu.is_deleted = 0
                      AND {$parent}
                ))";
        }

        if ($teacherUserId !== null && $teacherUserId > 0) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM semester_lesson_reservations r
                WHERE r.student_user_id = u.id AND r.teacher_user_id = :teacher_id AND r.status <> 'deleted'"
                . ($semesterId !== null ? ' AND r.semester_id = :semester_id' : '') . ')';
            $params[':teacher_id'] = $teacherUserId;
            if ($semesterId !== null) {
                $params[':semester_id'] = $semesterId;
            }
        }

        if ($instrumentId !== null && $instrumentId > 0) {
            $sql .= ' AND EXISTS (SELECT 1 FROM student_instruments si
                                  WHERE si.student_user_id = u.id AND si.instrument_id = :instrument_id)';
            $params[':instrument_id'] = $instrumentId;
        }

        $st = self::pdo()->prepare($sql . ' ORDER BY u.first_name, u.last_name');
        $st->execute($params);
        $students = $st->fetchAll();
        foreach ($students as &$s) {
            $s['instruments'] = InstrumentCatalog::namesForStudent((int)$s['id']);
            $s['parents'] = self::parentsOfStudent((int)$s['id']);
        }
        return $students;
    }

    /** The admin Teachers list: keyword prefix tokens + instrument filter. */
    public static function listTeachersFiltered(string $keyword = '', ?int $instrumentId = null): array {
        $sql = 'SELECT u.id, u.first_name, u.last_name, u.preferred_name, u.email, u.cell_phone,
                       u.photo_public_file_id, tp.gender
                FROM teacher_profiles tp
                JOIN users u ON u.id = tp.user_id
                WHERE u.is_deleted = 0';
        $params = [];

        foreach (KeywordSearch::tokens($keyword) as $i => $token) {
            $sql .= ' AND ' . KeywordSearch::likeAnyClause(
                ['u.first_name', 'u.last_name', 'u.preferred_name',
                 'u.cell_phone', 'u.email', 'u.address_street_1'],
                $token . '%', "t{$i}", $params
            );
        }

        if ($instrumentId !== null && $instrumentId > 0) {
            $sql .= ' AND EXISTS (SELECT 1 FROM teacher_instruments ti
                                  WHERE ti.teacher_user_id = u.id AND ti.instrument_id = :instrument_id)';
            $params[':instrument_id'] = $instrumentId;
        }

        $st = self::pdo()->prepare($sql . ' ORDER BY u.first_name, u.last_name');
        $st->execute($params);
        $teachers = $st->fetchAll();
        foreach ($teachers as &$t) {
            $t['instruments'] = InstrumentCatalog::namesForTeacher((int)$t['id']);
        }
        return $teachers;
    }

    /** The students a teacher has (non-deleted) reservations with this semester. */
    public static function studentsForTeacherInSemester(int $teacherUserId, int $semesterId): array {
        $st = self::pdo()->prepare(
            "SELECT DISTINCT u.id, u.first_name, u.last_name, u.preferred_name, r.status AS reservation_status
             FROM semester_lesson_reservations r
             JOIN users u ON u.id = r.student_user_id
             WHERE r.teacher_user_id = ? AND r.semester_id = ? AND r.status <> 'deleted' AND u.is_deleted = 0
             ORDER BY u.first_name, u.last_name"
        );
        $st->execute([$teacherUserId, $semesterId]);
        return $st->fetchAll();
    }

    /** Typeahead: students whose first or last name starts with $prefix. */
    public static function searchStudentsByNamePrefix(string $prefix, int $limit = 10): array {
        return self::searchByNamePrefix('student_profiles', $prefix, $limit);
    }

    /** Typeahead: teachers whose first or last name starts with $prefix. */
    public static function searchTeachersByNamePrefix(string $prefix, int $limit = 10): array {
        return self::searchByNamePrefix('teacher_profiles', $prefix, $limit);
    }

    /**
     * Typeahead for "Link Existing Parent": anybody who could be linked to a
     * child as their parent, whether or not they are already a parent of
     * someone.
     *
     * Being a parent here is not a profile you hold, it is a parenthood row
     * you are in — so searching only people who already have one made
     * unlinking a family's only child permanent: the parent stopped being a
     * parent and could never be found again. Nobody is filtered out by role
     * (a teacher can have a child at the school; so can an adult student), so
     * each result carries what it already is and existing parents sort first.
     *
     * People who are somebody's child here are left out: the school already
     * records an adult responsible for them, which is as good as saying they
     * are not one. That also keeps a common surname from filling the list
     * with the children who share it. Should a family ever need one — an
     * older sibling who is now a guardian — unlinking that person's own
     * parent puts them back in the list.
     *
     * Passing the child being linked leaves them and their current parents
     * out of the list — you cannot be your own parent, and offering somebody
     * already linked only invites a confusing no-op.
     *
     * Rows: id, first_name, last_name, email, is_parent, is_teacher, is_student.
     */
    public static function searchPeopleForParentLink(string $prefix, ?int $forChildUserId = null, int $limit = 10): array {
        $params = [];
        $keywordClause = KeywordSearch::allTokensClause(
            ['u.first_name', 'u.last_name', 'u.preferred_name'], $prefix, 'n', $params
        );
        if ($keywordClause === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $excludeSql = '';
        if ($forChildUserId !== null && $forChildUserId > 0) {
            $excludeSql = ' AND u.id <> :child_id
               AND NOT EXISTS (SELECT 1 FROM parenthood linked
                               WHERE linked.parent_user_id = u.id AND linked.child_user_id = :child_id_2)';
            $params[':child_id'] = $forChildUserId;
            $params[':child_id_2'] = $forChildUserId;
        }
        $st = self::pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email,
                    EXISTS (SELECT 1 FROM parenthood ph WHERE ph.parent_user_id = u.id) AS is_parent,
                    EXISTS (SELECT 1 FROM teacher_profiles tp WHERE tp.user_id = u.id) AS is_teacher,
                    EXISTS (SELECT 1 FROM student_profiles sp WHERE sp.user_id = u.id) AS is_student
             FROM users u
             WHERE u.is_deleted = 0 AND $keywordClause
               AND NOT EXISTS (SELECT 1 FROM parenthood own WHERE own.child_user_id = u.id)" . $excludeSql . "
             ORDER BY is_parent DESC, u.first_name, u.last_name
             LIMIT $limit"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    private static function searchByNamePrefix(string $profileTable, string $prefix, int $limit): array {
        $params = [];
        // Each word typed must match one of the name columns, so a typeahead
        // still finds somebody once you have typed their whole name.
        $keywordClause = KeywordSearch::allTokensClause(
            ['u.first_name', 'u.last_name', 'u.preferred_name'], $prefix, 'n', $params
        );
        if ($keywordClause === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $st = self::pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM $profileTable p
             JOIN users u ON u.id = p.user_id
             WHERE u.is_deleted = 0 AND $keywordClause
             ORDER BY u.first_name, u.last_name
             LIMIT $limit"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Split a keyword search into lowercase tokens (max 5, ignore empties). */
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
