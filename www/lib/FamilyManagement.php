<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/InstrumentCatalog.php';
require_once __DIR__ . '/FamilyAccessTokens.php';
require_once __DIR__ . '/EmailTemplates.php';

// Thrown when a registration comes in under an email that already has a
// working account — the caller shows "log in to re-enroll" instead of a
// generic error.
class DuplicateAccountException extends RuntimeException {
}

/**
 * Families: the registration unit that drives the admin Action Queue.
 * See docs/registration_flow.md — one form, two paths, one data model.
 */
class FamilyManagement {

    public const STATUSES = ['needs_follow_up', 'ready_to_enroll', 'schedule_assigned', 'enrolled'];

    public const STATUS_LABELS = [
        'needs_follow_up' => 'Needs Follow-Up',
        'ready_to_enroll' => 'Ready to Enroll',
        'schedule_assigned' => 'Schedule Assigned',
        'enrolled' => 'Enrolled',
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * The whole registration form submission in one transaction: family +
     * parent user + student users/profiles/instruments + parenthood +
     * preferences row. Runs with no logged-in user ($ctx null).
     *
     * $parentData: first_name, last_name, email, cell_phone, home_phone,
     *   preferred_contact_method, address_street_1/2, address_city,
     *   address_state, address_zip, relationship (mother|father|guardian),
     *   emergency_contact_name, emergency_contact_phone, medical_notes,
     *   parent_is_student (bool — the adult takes lessons themselves),
     *   parent_instrument_ids (when parent_is_student).
     * $students: list of ['first_name','last_name','date_of_birth',
     *   'experience_level','school_name','grade','instrument_ids'].
     * $prefs: preferred_days (array), time_window (array),
     *   preferred_location_id, teacher_gender_pref, constraints_text,
     *   how_heard, consent_photo_release, consent_terms, consent_liability.
     * $path: 'complete_enrollment' | 'talk_first'.
     *
     * Returns ['family_id','parent_user_id','student_user_ids'].
     * Throws DuplicateAccountException if the parent email already has a
     * password-bearing account.
     */
    public static function createFamilyFromRegistration(?UserContext $ctx, array $parentData, array $students, array $prefs, string $path): array {
        if (!in_array($path, ['complete_enrollment', 'talk_first'], true)) {
            throw new InvalidArgumentException('Unknown registration path: ' . $path);
        }

        $email = strtolower(trim((string)($parentData['email'] ?? '')));
        $existing = UserManagement::findAuthByEmail($email);
        if ($existing && (string)$existing['password_hash'] !== '') {
            throw new DuplicateAccountException(
                'It looks like you already have a BCM account under ' . $email . '.'
            );
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $familyName = trim((string)$parentData['last_name']);
            $status = $path === 'talk_first' ? 'needs_follow_up' : 'ready_to_enroll';

            $pdo->prepare('INSERT INTO families (family_name, status) VALUES (?,?)')
                ->execute([$familyName, $status]);
            $familyId = (int)$pdo->lastInsertId();

            // Parent user (reuses a passwordless row if the email exists).
            $parentUserId = UserManagement::findOrCreateByEmail(
                $ctx,
                (string)$parentData['first_name'],
                (string)$parentData['last_name'],
                $email
            );
            $pdo->prepare(
                'UPDATE users SET
                   cell_phone = ?, home_phone = ?, preferred_contact_method = ?,
                   address_street_1 = ?, address_street_2 = ?, address_city = ?,
                   address_state = ?, address_zip = ?,
                   emergency_contact_name = ?, emergency_contact_phone = ?,
                   medical_notes = ?, family_id = ?
                 WHERE id = ?'
            )->execute([
                self::orNull($parentData['cell_phone'] ?? null),
                self::orNull($parentData['home_phone'] ?? null),
                self::orNull($parentData['preferred_contact_method'] ?? null),
                self::orNull($parentData['address_street_1'] ?? null),
                self::orNull($parentData['address_street_2'] ?? null),
                self::orNull($parentData['address_city'] ?? null),
                self::orNull($parentData['address_state'] ?? null),
                self::orNull($parentData['address_zip'] ?? null),
                self::orNull($parentData['emergency_contact_name'] ?? null),
                self::orNull($parentData['emergency_contact_phone'] ?? null),
                self::orNull($parentData['medical_notes'] ?? null),
                $familyId,
                $parentUserId,
            ]);
            $pdo->prepare('UPDATE families SET primary_parent_user_id = ? WHERE id = ?')
                ->execute([$parentUserId, $familyId]);

            // An adult registering for themselves is both parent and student.
            if (!empty($parentData['parent_is_student'])) {
                StudentTeacherManagement::ensureStudentProfile($ctx, $parentUserId, [
                    'experience_level' => $parentData['parent_experience_level'] ?? null,
                ]);
                InstrumentCatalog::setStudentInstruments($ctx, $parentUserId, (array)($parentData['parent_instrument_ids'] ?? []));
            }

            $relationship = self::orNull($parentData['relationship'] ?? null);
            $studentUserIds = [];
            foreach ($students as $student) {
                $first = trim((string)($student['first_name'] ?? ''));
                $last = trim((string)($student['last_name'] ?? ''));
                if ($first === '' || $last === '') {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO users (first_name, last_name, password_hash, family_id) VALUES (?,?,\'\',?)'
                )->execute([$first, $last, $familyId]);
                $studentUserId = (int)$pdo->lastInsertId();

                StudentTeacherManagement::ensureStudentProfile($ctx, $studentUserId, [
                    'date_of_birth' => $student['date_of_birth'] ?? null,
                    'experience_level' => $student['experience_level'] ?? null,
                    'school_name' => $student['school_name'] ?? null,
                    'grade' => $student['grade'] ?? null,
                ]);
                InstrumentCatalog::setStudentInstruments($ctx, $studentUserId, (array)($student['instrument_ids'] ?? []));
                StudentTeacherManagement::linkParentChild($ctx, $parentUserId, $studentUserId, $relationship);
                $studentUserIds[] = $studentUserId;
            }

            if (!$studentUserIds && empty($parentData['parent_is_student'])) {
                throw new InvalidArgumentException('At least one student is required.');
            }

            $pdo->prepare(
                'INSERT INTO registration_submissions
                   (family_id, submitted_by_user_id, path, preferred_days, time_window,
                    preferred_location_id, teacher_gender_pref, constraints_text, how_heard,
                    consent_photo_release, consent_terms, consent_liability)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $familyId,
                $parentUserId,
                $path,
                implode(',', (array)($prefs['preferred_days'] ?? [])) ?: null,
                implode(',', (array)($prefs['time_window'] ?? [])) ?: null,
                !empty($prefs['preferred_location_id']) ? (int)$prefs['preferred_location_id'] : null,
                (string)($prefs['teacher_gender_pref'] ?? 'none'),
                self::orNull($prefs['constraints_text'] ?? null),
                self::orNull($prefs['how_heard'] ?? null),
                !empty($prefs['consent_photo_release']) ? 1 : 0,
                !empty($prefs['consent_terms']) ? 1 : 0,
                !empty($prefs['consent_liability']) ? 1 : 0,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'family.registered', [
            'family_id' => $familyId,
            'path' => $path,
            'student_count' => count($studentUserIds),
        ]);

        return [
            'family_id' => $familyId,
            'parent_user_id' => $parentUserId,
            'student_user_ids' => $studentUserIds,
        ];
    }

    // Families for the Action Queue, optionally filtered by status. Each row
    // carries its latest registration preferences, student count, and the
    // newest internal note snippet.
    public static function listFamiliesByStatus(?string $status = null): array {
        $sql = 'SELECT f.*, p.first_name AS parent_first_name, p.last_name AS parent_last_name,
                       p.email AS parent_email, p.cell_phone AS parent_cell_phone
                FROM families f
                LEFT JOIN users p ON p.id = f.primary_parent_user_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE f.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY f.created_at DESC';
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $families = $st->fetchAll();

        foreach ($families as &$family) {
            $family['submission'] = self::latestSubmission((int)$family['id']);
            $family['students'] = self::studentsOfFamily((int)$family['id']);
            $family['latest_note'] = self::latestNoteSnippet((int)$family['id']);
        }
        return $families;
    }

    // Everything the family detail page needs.
    public static function getFamilyDetail(int $familyId): ?array {
        $st = self::pdo()->prepare(
            'SELECT f.*, p.first_name AS parent_first_name, p.last_name AS parent_last_name,
                    p.email AS parent_email, p.cell_phone AS parent_cell_phone,
                    p.home_phone AS parent_home_phone, p.preferred_contact_method,
                    p.address_street_1, p.address_street_2, p.address_city, p.address_state, p.address_zip,
                    p.emergency_contact_name, p.emergency_contact_phone, p.medical_notes
             FROM families f
             LEFT JOIN users p ON p.id = f.primary_parent_user_id
             WHERE f.id = ? LIMIT 1'
        );
        $st->execute([$familyId]);
        $family = $st->fetch();
        if (!$family) {
            return null;
        }
        $family['submission'] = self::latestSubmission($familyId);
        $family['students'] = self::studentsOfFamily($familyId);
        return $family;
    }

    // The queue's one-line description, e.g.
    // "Martinez family — 2 students, Piano, Sat mornings, Bronx Community
    //  College, prefers female teacher".
    public static function familySummaryLine(array $family): string {
        $parts = [];
        $students = $family['students'] ?? [];
        $n = count($students);
        $parts[] = $n . ' student' . ($n === 1 ? '' : 's');

        $instruments = [];
        foreach ($students as $s) {
            foreach ($s['instruments'] ?? [] as $name) {
                $instruments[$name] = true;
            }
        }
        if ($instruments) {
            $parts[] = implode('/', array_keys($instruments));
        }

        $sub = $family['submission'] ?? null;
        if ($sub) {
            $when = [];
            if (!empty($sub['preferred_days'])) {
                $when[] = str_replace(',', '/', (string)$sub['preferred_days']);
            }
            if (!empty($sub['time_window'])) {
                $when[] = str_replace(',', '/', (string)$sub['time_window']);
            }
            if ($when) {
                $parts[] = implode(' ', $when);
            }
            if (!empty($sub['location_name'])) {
                $parts[] = $sub['location_name'];
            }
            if (($sub['teacher_gender_pref'] ?? 'none') !== 'none') {
                $parts[] = 'prefers ' . $sub['teacher_gender_pref'] . ' teacher';
            }
        }

        return ($family['family_name'] ?? '') . ' family — ' . implode(', ', $parts);
    }

    public static function setStatus(?UserContext $ctx, int $familyId, string $status): void {
        self::assertAdmin($ctx);
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown family status: ' . $status);
        }
        self::pdo()->prepare('UPDATE families SET status = ? WHERE id = ?')->execute([$status, $familyId]);
        self::log($ctx, 'family.status_changed', ['family_id' => $familyId, 'status' => $status]);
    }

    // Family confirmed via their token link (or an admin marked it done).
    // $ctx is null for the token flow — the token itself is the authorization.
    public static function markEnrolled(?UserContext $ctx, int $familyId): void {
        self::pdo()->prepare("UPDATE families SET status = 'enrolled' WHERE id = ?")->execute([$familyId]);
        self::log($ctx, 'family.enrolled', ['family_id' => $familyId]);
    }

    /**
     * The "Great news" email: issues a family access token for the primary
     * parent and sends the schedule. Also flips status to schedule_assigned.
     * $sendEmail (to-email, subject, html, to-name) => bool is injectable for
     * tests; defaults to send_email().
     */
    public static function sendScheduleAssignedEmail(?UserContext $ctx, int $familyId, ?callable $sendEmail = null): void {
        self::assertAdmin($ctx);
        $family = self::getFamilyDetail($familyId);
        if (!$family) {
            throw new InvalidArgumentException('Family not found.');
        }
        if (empty($family['primary_parent_user_id']) || empty($family['parent_email'])) {
            throw new InvalidArgumentException('This family has no parent email on file.');
        }

        require_once __DIR__ . '/LessonManagement.php';
        $lessons = LessonManagement::lessonsForFamily($familyId, date('Y-m-d'));
        $lines = [];
        foreach ($lessons as $lesson) {
            $lines[] = LessonManagement::lessonSummaryLine($lesson);
        }
        if (!$lines) {
            throw new InvalidArgumentException('Assign at least one lesson before emailing the schedule.');
        }

        $token = FamilyAccessTokens::issueForFamilyRecipient($familyId, (int)$family['primary_parent_user_id']);
        $url = FamilyAccessTokens::scheduleReviewUrl($token);
        $email = EmailTemplates::scheduleAssigned((string)$family['parent_first_name'], $lines, $url);

        $send = $sendEmail ?? fn($to, $subject, $html, $toName) => send_email($to, $subject, $html, $toName);
        $ok = $send((string)$family['parent_email'], $email['subject'], $email['html'], trim($family['parent_first_name'] . ' ' . $family['parent_last_name']));

        self::pdo()->prepare(
            'INSERT INTO notification_log (family_id, recipient_user_id, notification_type, notification_date, email_address, delivery_status, error_message)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $familyId,
            (int)$family['primary_parent_user_id'],
            'schedule_assigned',
            date('Y-m-d'),
            (string)$family['parent_email'],
            $ok ? 'sent' : 'failed',
            $ok ? null : 'send_email returned failure',
        ]);

        self::setStatus($ctx, $familyId, 'schedule_assigned');
        self::log($ctx, 'family.schedule_email_sent', ['family_id' => $familyId, 'success' => $ok]);
    }

    // ===== Internals =====

    private static function latestSubmission(int $familyId): ?array {
        $st = self::pdo()->prepare(
            'SELECT rs.*, l.name AS location_name
             FROM registration_submissions rs
             LEFT JOIN locations l ON l.id = rs.preferred_location_id
             WHERE rs.family_id = ?
             ORDER BY rs.created_at DESC, rs.id DESC LIMIT 1'
        );
        $st->execute([$familyId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // The family's students (child rows plus a parent who is also a student),
    // each with instrument names.
    private static function studentsOfFamily(int $familyId): array {
        $st = self::pdo()->prepare(
            'SELECT u.id, u.first_name, u.last_name,
                    sp.date_of_birth, sp.experience_level, sp.school_name, sp.grade
             FROM users u
             JOIN student_profiles sp ON sp.user_id = u.id
             WHERE u.family_id = ?
             ORDER BY u.id'
        );
        $st->execute([$familyId]);
        $students = $st->fetchAll();
        foreach ($students as &$s) {
            $s['instruments'] = InstrumentCatalog::namesForStudent((int)$s['id']);
        }
        return $students;
    }

    private static function latestNoteSnippet(int $familyId): ?string {
        $st = self::pdo()->prepare(
            'SELECT body FROM notes WHERE family_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $st->execute([$familyId]);
        $body = $st->fetchColumn();
        if ($body === false) {
            return null;
        }
        $body = trim((string)$body);
        return mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body;
    }

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

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
