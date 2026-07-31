<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/UserManagement.php';

/**
 * Lessons: one-off and recurring, individual and group.
 *
 * Recurring lessons are weekly templates; real lesson rows are materialized
 * from them (generateOccurrencesThrough) because per-occurrence substitutes,
 * cancellations, attendance, and teacher notes all need real rows.
 */
class LessonManagement {

    private const LESSON_SELECT = '
        SELECT l.*, i.name AS instrument_name, loc.name AS location_name,
               t.first_name AS teacher_first_name, t.last_name AS teacher_last_name,
               sub.first_name AS sub_first_name, sub.last_name AS sub_last_name,
               s.first_name AS student_first_name, s.last_name AS student_last_name
        FROM lessons l
        LEFT JOIN instruments i ON i.id = l.instrument_id
        LEFT JOIN locations loc ON loc.id = l.location_id
        JOIN users t ON t.id = l.teacher_user_id
        LEFT JOIN users sub ON sub.id = l.substitute_teacher_user_id
        LEFT JOIN users s ON s.id = l.student_user_id';

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Creating and editing =====

    /**
     * $fields: lesson_type, name, instrument_id, teacher_user_id,
     * student_user_id, location_id, room, is_online, start_datetime,
     * duration_minutes. For group lessons pass student_user_ids /
     * teacher_user_ids arrays for the rosters.
     */
    public static function createLesson(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO lessons (lesson_type, name, instrument_id, teacher_user_id, student_user_id,
                                  location_id, room, is_online, start_datetime, duration_minutes,
                                  recurring_lesson_id, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $fields['lesson_type'] ?? 'individual',
            self::orNull($fields['name'] ?? null),
            self::intOrNull($fields['instrument_id'] ?? null),
            (int)$fields['teacher_user_id'],
            self::intOrNull($fields['student_user_id'] ?? null),
            self::intOrNull($fields['location_id'] ?? null),
            self::orNull($fields['room'] ?? null),
            !empty($fields['is_online']) ? 1 : 0,
            (string)$fields['start_datetime'],
            (int)($fields['duration_minutes'] ?? 30),
            self::intOrNull($fields['recurring_lesson_id'] ?? null),
            $ctx->id,
        ]);
        $lessonId = (int)$pdo->lastInsertId();

        if (($fields['lesson_type'] ?? 'individual') === 'group') {
            self::setGroupMembership($ctx, $lessonId,
                (array)($fields['student_user_ids'] ?? []),
                (array)($fields['teacher_user_ids'] ?? []));
        }

        self::log($ctx, 'lesson.created', ['lesson_id' => $lessonId]);
        return $lessonId;
    }

    public static function updateLesson(?UserContext $ctx, int $lessonId, array $fields): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare(
            'UPDATE lessons SET lesson_type=?, name=?, instrument_id=?, teacher_user_id=?,
                    student_user_id=?, location_id=?, room=?, is_online=?, start_datetime=?,
                    duration_minutes=?, status=?
             WHERE id=?'
        )->execute([
            $fields['lesson_type'] ?? 'individual',
            self::orNull($fields['name'] ?? null),
            self::intOrNull($fields['instrument_id'] ?? null),
            (int)$fields['teacher_user_id'],
            self::intOrNull($fields['student_user_id'] ?? null),
            self::intOrNull($fields['location_id'] ?? null),
            self::orNull($fields['room'] ?? null),
            !empty($fields['is_online']) ? 1 : 0,
            (string)$fields['start_datetime'],
            (int)($fields['duration_minutes'] ?? 30),
            $fields['status'] ?? 'scheduled',
            $lessonId,
        ]);
        if (($fields['lesson_type'] ?? 'individual') === 'group' && isset($fields['student_user_ids'])) {
            self::setGroupMembership($ctx, $lessonId,
                (array)$fields['student_user_ids'],
                (array)($fields['teacher_user_ids'] ?? []));
        }
        self::log($ctx, 'lesson.updated', ['lesson_id' => $lessonId]);
    }

    public static function cancelLesson(?UserContext $ctx, int $lessonId): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare("UPDATE lessons SET status='cancelled' WHERE id=?")->execute([$lessonId]);
        self::log($ctx, 'lesson.cancelled', ['lesson_id' => $lessonId]);
    }

    // Replace a group lesson's rosters. Keeps existing attendance marks for
    // students who stay on the roster.
    public static function setGroupMembership(?UserContext $ctx, int $lessonId, array $studentUserIds, array $teacherUserIds): void {
        self::assertAdmin($ctx);
        $pdo = self::pdo();

        $studentUserIds = array_values(array_unique(array_filter(array_map('intval', $studentUserIds))));
        $teacherUserIds = array_values(array_unique(array_filter(array_map('intval', $teacherUserIds))));

        if ($studentUserIds) {
            $placeholders = implode(',', array_fill(0, count($studentUserIds), '?'));
            $pdo->prepare("DELETE FROM group_lesson_students WHERE lesson_id=? AND student_user_id NOT IN ($placeholders)")
                ->execute(array_merge([$lessonId], $studentUserIds));
        } else {
            $pdo->prepare('DELETE FROM group_lesson_students WHERE lesson_id=?')->execute([$lessonId]);
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO group_lesson_students (lesson_id, student_user_id) VALUES (?,?)');
        foreach ($studentUserIds as $sid) {
            $ins->execute([$lessonId, $sid]);
        }

        $pdo->prepare('DELETE FROM group_lesson_teachers WHERE lesson_id=?')->execute([$lessonId]);
        $ins = $pdo->prepare('INSERT INTO group_lesson_teachers (lesson_id, teacher_user_id) VALUES (?,?)');
        foreach ($teacherUserIds as $tid) {
            $ins->execute([$lessonId, $tid]);
        }
        self::log($ctx, 'lesson.roster_set', ['lesson_id' => $lessonId, 'students' => count($studentUserIds), 'teachers' => count($teacherUserIds)]);
    }

    // ===== Recurring lessons =====

    /**
     * Create a weekly recurring template. $fields: lesson_type, name,
     * instrument_id, teacher_user_id, student_user_id (individual),
     * location_id, room, is_online, day_of_week (0=Sun..6=Sat), start_time,
     * duration_minutes, start_date, end_date. Group rosters via
     * $studentUserIds / $teacherUserIds.
     */
    public static function createRecurring(?UserContext $ctx, array $fields, array $studentUserIds = [], array $teacherUserIds = []): int {
        self::assertAdmin($ctx);
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO recurring_lessons (lesson_type, name, instrument_id, teacher_user_id, student_user_id,
                                            location_id, room, is_online, day_of_week, start_time,
                                            duration_minutes, start_date, end_date, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $fields['lesson_type'] ?? 'individual',
            self::orNull($fields['name'] ?? null),
            self::intOrNull($fields['instrument_id'] ?? null),
            (int)$fields['teacher_user_id'],
            self::intOrNull($fields['student_user_id'] ?? null),
            self::intOrNull($fields['location_id'] ?? null),
            self::orNull($fields['room'] ?? null),
            !empty($fields['is_online']) ? 1 : 0,
            (int)$fields['day_of_week'],
            (string)$fields['start_time'],
            (int)($fields['duration_minutes'] ?? 30),
            (string)$fields['start_date'],
            self::orNull($fields['end_date'] ?? null),
            $ctx->id,
        ]);
        $recurringId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT IGNORE INTO recurring_lesson_students (recurring_lesson_id, student_user_id) VALUES (?,?)');
        foreach (array_unique(array_filter(array_map('intval', $studentUserIds))) as $sid) {
            $ins->execute([$recurringId, $sid]);
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO recurring_lesson_teachers (recurring_lesson_id, teacher_user_id) VALUES (?,?)');
        foreach (array_unique(array_filter(array_map('intval', $teacherUserIds))) as $tid) {
            $ins->execute([$recurringId, $tid]);
        }

        self::log($ctx, 'recurring_lesson.created', ['recurring_lesson_id' => $recurringId]);
        return $recurringId;
    }

    public static function getRecurring(int $recurringId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM recurring_lessons WHERE id=? LIMIT 1');
        $st->execute([$recurringId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function listRecurring(bool $activeOnly = false): array {
        $sql = 'SELECT rl.*, i.name AS instrument_name, loc.name AS location_name,
                       t.first_name AS teacher_first_name, t.last_name AS teacher_last_name,
                       s.first_name AS student_first_name, s.last_name AS student_last_name
                FROM recurring_lessons rl
                LEFT JOIN instruments i ON i.id = rl.instrument_id
                LEFT JOIN locations loc ON loc.id = rl.location_id
                JOIN users t ON t.id = rl.teacher_user_id
                LEFT JOIN users s ON s.id = rl.student_user_id';
        if ($activeOnly) {
            $sql .= ' WHERE rl.is_active = 1';
        }
        $sql .= ' ORDER BY rl.day_of_week, rl.start_time';
        return self::pdo()->query($sql)->fetchAll();
    }

    public static function setRecurringActive(?UserContext $ctx, int $recurringId, bool $active): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('UPDATE recurring_lessons SET is_active=? WHERE id=?')
            ->execute([$active ? 1 : 0, $recurringId]);
        self::log($ctx, 'recurring_lesson.active_set', ['recurring_lesson_id' => $recurringId, 'active' => $active]);
    }

    /**
     * Materialize a recurring template's occurrences into lessons, from its
     * start_date through $throughDate (capped by end_date). Idempotent:
     * dates that already have an occurrence row are skipped, so it can be
     * re-run to extend the horizon. Returns the number of lessons created.
     */
    public static function generateOccurrencesThrough(?UserContext $ctx, int $recurringId, string $throughDate): int {
        self::assertAdmin($ctx);
        $template = self::getRecurring($recurringId);
        if (!$template) {
            throw new InvalidArgumentException('Recurring lesson not found.');
        }
        if (empty($template['is_active'])) {
            return 0;
        }

        $end = $template['end_date'] !== null && $template['end_date'] < $throughDate
            ? (string)$template['end_date'] : $throughDate;

        // Existing occurrence dates for this template.
        $st = self::pdo()->prepare('SELECT DATE(start_datetime) AS d FROM lessons WHERE recurring_lesson_id=?');
        $st->execute([$recurringId]);
        $existing = array_flip(array_column($st->fetchAll(), 'd'));

        // Group rosters are copied onto each occurrence at generation time.
        $st = self::pdo()->prepare('SELECT student_user_id FROM recurring_lesson_students WHERE recurring_lesson_id=?');
        $st->execute([$recurringId]);
        $studentIds = array_column($st->fetchAll(), 'student_user_id');
        $st = self::pdo()->prepare('SELECT teacher_user_id FROM recurring_lesson_teachers WHERE recurring_lesson_id=?');
        $st->execute([$recurringId]);
        $teacherIds = array_column($st->fetchAll(), 'teacher_user_id');

        $created = 0;
        $date = new DateTimeImmutable((string)$template['start_date']);
        // Advance to the template's weekday.
        $offset = ((int)$template['day_of_week'] - (int)$date->format('w') + 7) % 7;
        $date = $date->modify("+$offset days");
        $endDate = new DateTimeImmutable($end);

        $insert = self::pdo()->prepare(
            'INSERT INTO lessons (lesson_type, name, instrument_id, teacher_user_id, student_user_id,
                                  location_id, room, is_online, start_datetime, duration_minutes,
                                  recurring_lesson_id, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insStudent = self::pdo()->prepare('INSERT IGNORE INTO group_lesson_students (lesson_id, student_user_id) VALUES (?,?)');
        $insTeacher = self::pdo()->prepare('INSERT IGNORE INTO group_lesson_teachers (lesson_id, teacher_user_id) VALUES (?,?)');

        while ($date <= $endDate) {
            $day = $date->format('Y-m-d');
            if (!isset($existing[$day])) {
                $insert->execute([
                    $template['lesson_type'],
                    $template['name'],
                    $template['instrument_id'],
                    $template['teacher_user_id'],
                    $template['student_user_id'],
                    $template['location_id'],
                    $template['room'],
                    $template['is_online'],
                    $day . ' ' . $template['start_time'],
                    $template['duration_minutes'],
                    $recurringId,
                    $ctx->id,
                ]);
                $lessonId = (int)self::pdo()->lastInsertId();
                if ($template['lesson_type'] === 'group') {
                    foreach ($studentIds as $sid) {
                        $insStudent->execute([$lessonId, (int)$sid]);
                    }
                    foreach ($teacherIds as $tid) {
                        $insTeacher->execute([$lessonId, (int)$tid]);
                    }
                }
                $created++;
            }
            $date = $date->modify('+7 days');
        }

        self::log($ctx, 'recurring_lesson.generated', ['recurring_lesson_id' => $recurringId, 'through' => $throughDate, 'created' => $created]);
        return $created;
    }

    // ===== Teaching-day actions =====

    // Substitute teacher override: who actually taught (null clears it).
    public static function setSubstituteTeacher(?UserContext $ctx, int $lessonId, ?int $teacherUserId): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('UPDATE lessons SET substitute_teacher_user_id=? WHERE id=?')
            ->execute([$teacherUserId, $lessonId]);
        self::log($ctx, 'lesson.substitute_set', ['lesson_id' => $lessonId, 'substitute_teacher_user_id' => $teacherUserId]);
    }

    /**
     * Mark attendance. For individual lessons pass $studentUserId = null
     * (writes lessons.attended); for group lessons pass the student whose
     * roster row to mark. The lesson's teacher (or substitute) and admins may
     * mark attendance.
     */
    public static function markAttendance(?UserContext $ctx, int $lessonId, ?int $studentUserId, bool $attended): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $lesson = self::getLesson($lessonId);
        if (!$lesson) {
            throw new InvalidArgumentException('Lesson not found.');
        }
        if (!$ctx->admin && !self::isEffectiveTeacher($ctx->id, $lesson)) {
            throw new RuntimeException('Only the lesson\'s teacher may mark attendance.');
        }

        if ($lesson['lesson_type'] === 'individual' || $studentUserId === null) {
            self::pdo()->prepare('UPDATE lessons SET attended=? WHERE id=?')
                ->execute([$attended ? 1 : 0, $lessonId]);
        } else {
            $st = self::pdo()->prepare('UPDATE group_lesson_students SET attended=? WHERE lesson_id=? AND student_user_id=?');
            $st->execute([$attended ? 1 : 0, $lessonId, $studentUserId]);
            if ($st->rowCount() === 0) {
                // rowCount can be 0 for a no-op update; only fail if the
                // student truly isn't on the roster.
                $chk = self::pdo()->prepare('SELECT 1 FROM group_lesson_students WHERE lesson_id=? AND student_user_id=?');
                $chk->execute([$lessonId, $studentUserId]);
                if (!$chk->fetchColumn()) {
                    throw new InvalidArgumentException('That student is not in this group lesson.');
                }
            }
        }
        self::log($ctx, 'lesson.attendance_marked', ['lesson_id' => $lessonId, 'student_user_id' => $studentUserId, 'attended' => $attended]);
    }

    // ===== Queries =====

    public static function getLesson(int $lessonId): ?array {
        $st = self::pdo()->prepare(self::LESSON_SELECT . ' WHERE l.id = ? LIMIT 1');
        $st->execute([$lessonId]);
        $lesson = $st->fetch();
        if (!$lesson) {
            return null;
        }
        if ($lesson['lesson_type'] === 'group') {
            $lesson['group_students'] = self::groupStudents($lessonId);
        }
        return $lesson;
    }

    private static function groupStudents(int $lessonId): array {
        $st = self::pdo()->prepare(
            'SELECT gls.student_user_id, gls.attended, u.first_name, u.last_name
             FROM group_lesson_students gls JOIN users u ON u.id = gls.student_user_id
             WHERE gls.lesson_id = ? ORDER BY u.first_name, u.last_name'
        );
        $st->execute([$lessonId]);
        return $st->fetchAll();
    }

    // A teacher's lessons on a date, chronological — the teacher dashboard.
    // Includes lessons where they are the substitute or on a group roster.
    public static function lessonsForTeacherOnDate(int $teacherUserId, string $date): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT . '
             WHERE DATE(l.start_datetime) = ?
               AND (l.teacher_user_id = ? OR l.substitute_teacher_user_id = ?
                    OR EXISTS (SELECT 1 FROM group_lesson_teachers glt
                               WHERE glt.lesson_id = l.id AND glt.teacher_user_id = ?))
             ORDER BY l.start_datetime'
        );
        $st->execute([$date, $teacherUserId, $teacherUserId, $teacherUserId]);
        $lessons = $st->fetchAll();
        foreach ($lessons as &$lesson) {
            if ($lesson['lesson_type'] === 'group') {
                $lesson['group_students'] = self::groupStudents((int)$lesson['id']);
            }
        }
        return $lessons;
    }

    // A student's lessons from $fromDate forward (individual + group).
    public static function upcomingLessonsForStudent(int $studentUserId, string $fromDate, int $limit = 20): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT . '
             WHERE l.start_datetime >= ?
               AND (l.student_user_id = ?
                    OR EXISTS (SELECT 1 FROM group_lesson_students gls
                               WHERE gls.lesson_id = l.id AND gls.student_user_id = ?))
             ORDER BY l.start_datetime
             LIMIT ' . (int)$limit
        );
        $st->execute([$fromDate . ' 00:00:00', $studentUserId, $studentUserId]);
        return $st->fetchAll();
    }

    // All of a family's students' lessons from $fromDate forward — the
    // schedule-review page and the "Great news" email.
    public static function lessonsForFamily(int $familyId, string $fromDate): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT . '
             WHERE l.start_datetime >= ? AND l.status <> \'cancelled\'
               AND (EXISTS (SELECT 1 FROM users fu WHERE fu.id = l.student_user_id AND fu.family_id = ?)
                    OR EXISTS (SELECT 1 FROM group_lesson_students gls
                               JOIN users fu ON fu.id = gls.student_user_id
                               WHERE gls.lesson_id = l.id AND fu.family_id = ?))
             ORDER BY l.start_datetime'
        );
        $st->execute([$fromDate . ' 00:00:00', $familyId, $familyId]);
        return $st->fetchAll();
    }

    // All lessons in a date range (admin calendar list).
    public static function lessonsBetween(string $fromDate, string $toDate): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT . '
             WHERE l.start_datetime >= ? AND l.start_datetime < ?
             ORDER BY l.start_datetime'
        );
        $st->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        return $st->fetchAll();
    }

    // "Sofia — Violin with Maria Garcia — Sat, Apr 11, 9:00 AM at Bronx
    // Community College (Room 12)" for schedule emails.
    public static function lessonSummaryLine(array $lesson): string {
        $who = $lesson['lesson_type'] === 'group'
            ? (string)($lesson['name'] ?? 'Group class')
            : trim((string)$lesson['student_first_name'] . ' ' . (string)$lesson['student_last_name']);
        $what = $lesson['instrument_name'] ? $lesson['instrument_name'] : 'Music';
        $teacher = trim((string)$lesson['teacher_first_name'] . ' ' . (string)$lesson['teacher_last_name']);
        $when = date('D, M j, g:i A', strtotime((string)$lesson['start_datetime']));
        if (!empty($lesson['is_online'])) {
            $where = 'online';
        } else {
            $where = (string)($lesson['location_name'] ?? '');
            if (!empty($lesson['room'])) {
                $where .= ($where !== '' ? ' ' : '') . '(Room ' . $lesson['room'] . ')';
            }
            $where = $where !== '' ? $where : 'location TBD';
        }
        return $who . ' — ' . $what . ' with ' . $teacher . ' — ' . $when . ' at ' . $where;
    }

    // May this user see this lesson (and its notes/resources)? Admins,
    // the teacher/substitute/roster teachers, the student(s), and their
    // parents.
    public static function canUserViewLesson(int $userId, int $lessonId): bool {
        $lesson = self::getLesson($lessonId);
        if (!$lesson) {
            return false;
        }
        $user = UserManagement::findById($userId);
        if ($user && !empty($user['is_admin'])) {
            return true;
        }
        if (self::isEffectiveTeacher($userId, $lesson)) {
            return true;
        }
        $studentIds = self::lessonStudentIds($lesson);
        if (in_array($userId, $studentIds, true)) {
            return true;
        }
        foreach ($studentIds as $sid) {
            if (StudentTeacherManagement::isParentOf($userId, $sid)) {
                return true;
            }
        }
        return false;
    }

    public static function lessonStudentIds(array $lesson): array {
        if ($lesson['lesson_type'] === 'individual') {
            return $lesson['student_user_id'] ? [(int)$lesson['student_user_id']] : [];
        }
        $students = $lesson['group_students'] ?? self::groupStudents((int)$lesson['id']);
        return array_map(fn($s) => (int)$s['student_user_id'], $students);
    }

    // The teacher actually responsible today: the substitute if set, else the
    // assigned teacher, plus group roster teachers.
    public static function isEffectiveTeacher(int $userId, array $lesson): bool {
        if ((int)$lesson['teacher_user_id'] === $userId || (int)($lesson['substitute_teacher_user_id'] ?? 0) === $userId) {
            return true;
        }
        if ($lesson['lesson_type'] === 'group') {
            $st = self::pdo()->prepare('SELECT 1 FROM group_lesson_teachers WHERE lesson_id=? AND teacher_user_id=?');
            $st->execute([(int)$lesson['id'], $userId]);
            return (bool)$st->fetchColumn();
        }
        return false;
    }

    // ===== Internals =====

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function orNull($v): ?string {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private static function intOrNull($v): ?int {
        if ($v === null || $v === '' || (int)$v <= 0) return null;
        return (int)$v;
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
