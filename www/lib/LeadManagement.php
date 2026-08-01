<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/InstrumentCatalog.php';

/**
 * Registration leads: public registration-wizard submissions. Deliberately
 * quarantined from live data — a lead only becomes real users / student
 * profiles / reservations when an admin runs convertLead() from Admin >
 * Leads. Payments made at registration time are held on the lead and moved
 * to a student's ledger during conversion.
 */
class LeadManagement {

    public const STATUSES = ['new', 'contacted', 'scheduled', 'converted', 'declined'];

    public const STATUS_LABELS = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'scheduled' => 'Scheduled',
        'converted' => 'Converted',
        'declined' => 'Declined',
    ];

    // The wizard's instrument choices, verbatim from the paper/WuFoo form.
    // "Cello/Bass" is resolved to a real instruments row at convert time.
    public const INSTRUMENT_CHOICES = ['Voice', 'Piano', 'Violin', 'Viola', 'Guitar', 'Cello/Bass'];

    public const LESSON_LENGTHS = [30, 60];

    public const AVAILABILITY_BLOCKS = [
        '9-11' => '9:00AM–11:00AM',
        '11-1' => '11:00AM–1:00PM',
        '1-3' => '1:00PM–3:00PM',
        '3-5' => '3:00PM–5:00PM',
        '5-7' => '5:00PM–7:00PM',
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Pricing =====

    /**
     * The itemized quote for a set of students, straight from Settings.
     * $students: [['first_name','instrument','lesson_length_minutes','guitar_ensemble'], ...]
     * Returns ['lines' => [['label','amount_cents'],...], 'total_cents', 'due_now_cents'].
     *
     * Registration fee once per family; per student: tuition (30/60) plus one
     * recital fee per lesson block (private lesson = one block, ensemble = a
     * second block); installment plan adds its fee. Due now = everything for
     * full pay; for installment, all fees plus half of each tuition line.
     */
    public static function priceQuote(array $students, bool $installmentPlan): array {
        $lines = [];
        $tuitionCents = 0;
        $feeCents = 0;

        $registration = Settings::registrationCostCents();
        if ($registration > 0) {
            $lines[] = ['label' => 'Registration fee (one per family per semester)', 'amount_cents' => $registration];
            $feeCents += $registration;
        }

        $recital = Settings::recitalFeeCents();
        foreach ($students as $student) {
            $name = trim((string)($student['first_name'] ?? '')) ?: 'Student';
            $length = (int)($student['lesson_length_minutes'] ?? 30);
            $tuition = $length === 60 ? Settings::tuition60Cents() : Settings::tuition30Cents();
            $lines[] = [
                'label' => $name . ' — ' . $length . '-minute private lessons (full semester)',
                'amount_cents' => $tuition,
            ];
            $tuitionCents += $tuition;
            if ($recital > 0) {
                $lines[] = ['label' => $name . ' — Recital & Logistics fee', 'amount_cents' => $recital];
                $feeCents += $recital;
            }

            if (!empty($student['guitar_ensemble'])) {
                $ensemble = Settings::tuitionEnsembleCents();
                $lines[] = [
                    'label' => $name . ' — Guitar Ensemble (full semester)',
                    'amount_cents' => $ensemble,
                ];
                $tuitionCents += $ensemble;
                if ($recital > 0) {
                    $lines[] = ['label' => $name . ' — Recital & Logistics fee (Guitar Ensemble)', 'amount_cents' => $recital];
                    $feeCents += $recital;
                }
            }
        }

        if ($installmentPlan) {
            $installmentFee = Settings::installmentFeeCents();
            if ($installmentFee > 0) {
                $lines[] = ['label' => 'Installment plan fee', 'amount_cents' => $installmentFee];
                $feeCents += $installmentFee;
            }
        }

        $total = $tuitionCents + $feeCents;
        // Installment: all fees now + half the tuition now (round up the odd cent).
        $dueNow = $installmentPlan ? $feeCents + intdiv($tuitionCents + 1, 2) : $total;

        return ['lines' => $lines, 'total_cents' => $total, 'due_now_cents' => $dueNow];
    }

    // ===== Create (public wizard; $ctx is null) =====

    /**
     * Persist a completed wizard submission as a lead + its students, with
     * the quote frozen as computed right now. Throws InvalidArgumentException
     * on bad input (callers flash the message back to the form).
     */
    public static function createLead(?UserContext $ctx, ?int $semesterId, array $parent, array $students, array $scheduling, bool $installmentPlan): int {
        $first = trim((string)($parent['first_name'] ?? ''));
        $last = trim((string)($parent['last_name'] ?? ''));
        $email = strtolower(trim((string)($parent['email'] ?? '')));
        $phone = trim((string)($parent['phone'] ?? ''));
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Parent first and last name are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
            throw new InvalidArgumentException('A valid phone number is required.');
        }

        $cleanStudents = [];
        foreach ($students as $student) {
            $sFirst = trim((string)($student['first_name'] ?? ''));
            $sLast = trim((string)($student['last_name'] ?? ''));
            if ($sFirst === '' && $sLast === '') {
                continue;
            }
            if ($sFirst === '' || $sLast === '') {
                throw new InvalidArgumentException('Each student needs both a first and last name.');
            }
            $instrument = (string)($student['instrument'] ?? '');
            if (!in_array($instrument, self::INSTRUMENT_CHOICES, true)) {
                throw new InvalidArgumentException('Please choose an instrument for ' . $sFirst . '.');
            }
            $length = (int)($student['lesson_length_minutes'] ?? 0);
            if (!in_array($length, self::LESSON_LENGTHS, true)) {
                throw new InvalidArgumentException('Please choose a lesson length for ' . $sFirst . '.');
            }
            $age = (int)($student['age'] ?? 0);
            $cleanStudents[] = [
                'first_name' => $sFirst,
                'last_name' => $sLast,
                'age' => ($age >= 1 && $age <= 120) ? $age : null,
                'instrument' => $instrument,
                'lesson_length_minutes' => $length,
                'guitar_ensemble' => !empty($student['guitar_ensemble']) ? 1 : 0,
            ];
        }
        if (!$cleanStudents) {
            throw new InvalidArgumentException('At least one student is required.');
        }

        $quote = self::priceQuote($cleanStudents, $installmentPlan);

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO leads
                   (semester_id, status, parent_first_name, parent_last_name, email, phone, sms_consent,
                    address_street_1, address_street_2, address_city, address_state, address_zip,
                    location_preference, preferred_days, availability_blocks, scheduling_notes,
                    policies_agreed_at, installment_plan, quote_json,
                    amount_quoted_cents, amount_due_now_cents)
                 VALUES (?,\'new\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?)'
            )->execute([
                $semesterId,
                $first,
                $last,
                $email,
                $phone,
                !empty($parent['sms_consent']) ? 1 : 0,
                trim((string)($parent['address_street_1'] ?? '')),
                self::orNull($parent['address_street_2'] ?? null),
                trim((string)($parent['address_city'] ?? '')),
                trim((string)($parent['address_state'] ?? '')),
                trim((string)($parent['address_zip'] ?? '')),
                self::orNull($scheduling['location_preference'] ?? null),
                json_encode(array_values((array)($scheduling['preferred_days'] ?? []))),
                json_encode(array_values((array)($scheduling['availability_blocks'] ?? []))),
                self::orNull($scheduling['notes'] ?? null),
                $installmentPlan ? 1 : 0,
                json_encode($quote['lines']),
                $quote['total_cents'],
                $quote['due_now_cents'],
            ]);
            $leadId = (int)$pdo->lastInsertId();

            $insert = $pdo->prepare(
                'INSERT INTO lead_students (lead_id, first_name, last_name, age, instrument, lesson_length_minutes, guitar_ensemble)
                 VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($cleanStudents as $student) {
                $insert->execute([
                    $leadId, $student['first_name'], $student['last_name'], $student['age'],
                    $student['instrument'], $student['lesson_length_minutes'], $student['guitar_ensemble'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'lead.created', [
            'lead_id' => $leadId,
            'semester_id' => $semesterId,
            'student_count' => count($cleanStudents),
            'amount_quoted_cents' => $quote['total_cents'],
        ]);
        return $leadId;
    }

    // ===== Reads =====

    public static function findLead(int $leadId): ?array {
        $st = self::pdo()->prepare(
            'SELECT l.*, s.season, s.year
             FROM leads l LEFT JOIN semesters s ON s.id = l.semester_id
             WHERE l.id = ? LIMIT 1'
        );
        $st->execute([$leadId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function studentsForLead(int $leadId): array {
        $st = self::pdo()->prepare('SELECT * FROM lead_students WHERE lead_id = ? ORDER BY id');
        $st->execute([$leadId]);
        return $st->fetchAll();
    }

    // Queue rows: each lead plus its students, newest first.
    public static function listLeads(?string $status = null): array {
        $sql = 'SELECT l.*, s.season, s.year
                FROM leads l LEFT JOIN semesters s ON s.id = l.semester_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE l.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC';
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $leads = $st->fetchAll();
        foreach ($leads as &$lead) {
            $lead['students'] = self::studentsForLead((int)$lead['id']);
        }
        return $leads;
    }

    public static function statusCounts(): array {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (self::pdo()->query('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $row) {
            $counts[(string)$row['status']] = (int)$row['n'];
        }
        return $counts;
    }

    // ===== Admin updates =====

    public static function updateStatus(?UserContext $ctx, int $leadId, string $status): void {
        self::assertAdmin($ctx);
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown lead status: ' . $status);
        }
        self::pdo()->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$status, $leadId]);
        self::log($ctx, 'lead.status_changed', ['lead_id' => $leadId, 'status' => $status]);
    }

    public static function saveAdminNotes(?UserContext $ctx, int $leadId, string $notes): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('UPDATE leads SET admin_notes = ? WHERE id = ?')->execute([trim($notes), $leadId]);
        self::log($ctx, 'lead.notes_saved', ['lead_id' => $leadId]);
    }

    // ===== Stripe =====

    public static function attachCheckoutSession(?UserContext $ctx, int $leadId, string $sessionId): void {
        self::pdo()->prepare('UPDATE leads SET stripe_checkout_session_id = ? WHERE id = ?')
            ->execute([$sessionId, $leadId]);
        self::log($ctx, 'lead.checkout_session_attached', ['lead_id' => $leadId]);
    }

    /**
     * Record a completed Stripe payment on the lead. Idempotent: the guarded
     * UPDATE only fires while the session matches and nothing has been
     * recorded yet, so webhook + return-page races are harmless. Returns
     * whether this call recorded the payment.
     */
    public static function recordLeadPayment(int $leadId, int $amountCents, string $sessionId, ?string $paymentIntentId): bool {
        if ($amountCents <= 0 || trim($sessionId) === '') {
            return false;
        }
        $st = self::pdo()->prepare(
            'UPDATE leads
             SET amount_paid_cents = ?, stripe_payment_intent_id = ?, paid_at = NOW()
             WHERE id = ? AND stripe_checkout_session_id = ? AND amount_paid_cents = 0'
        );
        $st->execute([$amountCents, $paymentIntentId, $leadId, $sessionId]);
        $recorded = $st->rowCount() > 0;
        if ($recorded) {
            self::log(null, 'lead.payment_recorded', [
                'lead_id' => $leadId,
                'amount_cents' => $amountCents,
                'checkout_session_id' => $sessionId,
            ]);
        }
        return $recorded;
    }

    public static function findLeadByCheckoutSession(string $sessionId): ?array {
        if (trim($sessionId) === '') {
            return null;
        }
        $st = self::pdo()->prepare('SELECT * FROM leads WHERE stripe_checkout_session_id = ? LIMIT 1');
        $st->execute([$sessionId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // ===== Convert (admin) =====

    // The default instruments-table match for a wizard choice. "Cello/Bass"
    // defaults to Cello; the convert form lets the admin pick Double Bass.
    public static function instrumentIdForChoice(string $choice): ?int {
        $name = $choice === 'Cello/Bass' ? 'Cello' : $choice;
        $row = InstrumentCatalog::findByName($name);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Turn a lead into live data: parent user (created or adopted by email),
     * child users with student profiles + instruments + parenthood links,
     * optional reservation placements, and the lead's Stripe payment moved to
     * a chosen student's ledger.
     *
     * $options:
     *   'students' => [lead_student_id => [
     *       'instrument_id' => int,          // admin's Cello/Bass resolution
     *       'date_of_birth' => ?string,
     *       'reservation' => ?['teacher_user_id','location_id','day_of_week','start_time','duration_minutes'],
     *   ]],
     *   'payment_target_lead_student_id' => ?int,
     *
     * Idempotent/safe to re-enter: already-converted lead students (their
     * converted_student_user_id is set) are skipped, the parent adoption is
     * an upsert, and the ledger payment insert is UNIQUE-guarded (plus a
     * cross-student session check so one session never credits two students).
     * Throws on reservation conflicts (nothing further is attempted; already
     * created people stay recorded on the lead so re-running just continues).
     */
    public static function convertLead(UserContext $ctx, int $leadId, array $options = []): array {
        self::assertAdmin($ctx);
        $lead = self::findLead($leadId);
        if (!$lead) {
            throw new InvalidArgumentException('Lead not found.');
        }
        $leadStudents = self::studentsForLead($leadId);
        if (!$leadStudents) {
            throw new InvalidArgumentException('This lead has no students.');
        }
        $studentOptions = (array)($options['students'] ?? []);

        // 1. Parent: adopt by email or create, then fill in contact details
        //    non-destructively (only set what the lead knows).
        $adopted = UserManagement::adoptOrCreatePerson($ctx, [
            'first_name' => (string)$lead['parent_first_name'],
            'last_name' => (string)$lead['parent_last_name'],
            'email' => (string)$lead['email'],
            'cell_phone' => (string)$lead['phone'],
        ]);
        $parentUserId = (int)$adopted['id'];
        $profileFields = [];
        foreach ([
            'address_street_1' => 'address_street_1',
            'address_street_2' => 'address_street_2',
            'address_city' => 'address_city',
            'address_state' => 'address_state',
            'address_zip' => 'address_zip',
        ] as $leadKey => $userKey) {
            if (trim((string)($lead[$leadKey] ?? '')) !== '') {
                $profileFields[$userKey] = (string)$lead[$leadKey];
            }
        }
        if ($profileFields) {
            UserManagement::updateProfile($ctx, $parentUserId, $profileFields);
        }

        // 2. Students.
        $studentUserIds = [];
        foreach ($leadStudents as $leadStudent) {
            $leadStudentId = (int)$leadStudent['id'];
            if (!empty($leadStudent['converted_student_user_id'])) {
                $studentUserIds[$leadStudentId] = (int)$leadStudent['converted_student_user_id'];
                continue;
            }
            $opts = (array)($studentOptions[$leadStudentId] ?? []);

            $studentUserId = UserManagement::createUser($ctx, [
                'first_name' => (string)$leadStudent['first_name'],
                'last_name' => (string)$leadStudent['last_name'],
                'email' => '',
                'no_login' => true,
            ]);
            StudentTeacherManagement::ensureStudentProfile($ctx, $studentUserId, [
                'date_of_birth' => $opts['date_of_birth'] ?? null,
            ]);
            $instrumentId = (int)($opts['instrument_id'] ?? 0)
                ?: self::instrumentIdForChoice((string)$leadStudent['instrument']);
            if ($instrumentId) {
                InstrumentCatalog::addStudentInstruments($ctx, $studentUserId, [$instrumentId]);
            }
            StudentTeacherManagement::linkParentChild($ctx, $parentUserId, $studentUserId);

            self::pdo()->prepare('UPDATE lead_students SET converted_student_user_id = ? WHERE id = ?')
                ->execute([$studentUserId, $leadStudentId]);
            $studentUserIds[$leadStudentId] = $studentUserId;
        }

        // 3. Optional reservation placements (pending_reach_out, no charges —
        //    charges post when the reservation is later confirmed).
        require_once __DIR__ . '/ReservationManagement.php';
        $reservationIds = [];
        $semesterId = !empty($lead['semester_id']) ? (int)$lead['semester_id'] : null;
        foreach ($leadStudents as $leadStudent) {
            $leadStudentId = (int)$leadStudent['id'];
            $reservation = $studentOptions[$leadStudentId]['reservation'] ?? null;
            if (!$reservation || empty($reservation['teacher_user_id'])) {
                continue;
            }
            $reservationIds[] = ReservationManagement::createReservation($ctx, [
                'semester_id' => $reservation['semester_id'] ?? $semesterId,
                'teacher_user_id' => (int)$reservation['teacher_user_id'],
                'location_id' => (int)($reservation['location_id'] ?? 0),
                'student_user_id' => $studentUserIds[$leadStudentId],
                'day_of_week' => (int)($reservation['day_of_week'] ?? -1),
                'start_time' => (string)($reservation['start_time'] ?? ''),
                'duration_minutes' => (int)($reservation['duration_minutes'] ?? (int)$leadStudent['lesson_length_minutes']),
                'status' => 'pending_reach_out',
            ], ['post_charges' => false]);
        }

        // 4. Move the Stripe payment onto a student's ledger. Skipped (with a
        //    notice) when the session is already on ANY ledger, so one session
        //    can never credit two students.
        $paymentRecorded = false;
        $paymentNotice = null;
        $paidCents = (int)$lead['amount_paid_cents'];
        $sessionId = (string)($lead['stripe_checkout_session_id'] ?? '');
        $targetLeadStudentId = (int)($options['payment_target_lead_student_id'] ?? 0);
        if ($paidCents > 0 && $sessionId !== '' && $targetLeadStudentId && isset($studentUserIds[$targetLeadStudentId])) {
            $st = self::pdo()->prepare('SELECT COUNT(*) FROM ledger_entries WHERE stripe_checkout_session_id = ?');
            $st->execute([$sessionId]);
            if ((int)$st->fetchColumn() > 0) {
                $paymentNotice = 'This payment is already on a ledger — not recorded again.';
            } else {
                require_once __DIR__ . '/Billing.php';
                $paymentRecorded = Billing::recordStripePayment(
                    $studentUserIds[$targetLeadStudentId],
                    $paidCents,
                    $sessionId,
                    self::orNull($lead['stripe_payment_intent_id'] ?? null),
                    $semesterId
                );
            }
        }

        // 5. Mark the lead converted.
        self::pdo()->prepare(
            "UPDATE leads SET status = 'converted', converted_parent_user_id = ?, converted_at = COALESCE(converted_at, NOW()) WHERE id = ?"
        )->execute([$parentUserId, $leadId]);

        self::log($ctx, 'lead.converted', [
            'lead_id' => $leadId,
            'parent_user_id' => $parentUserId,
            'student_user_ids' => array_values($studentUserIds),
            'reservation_ids' => $reservationIds,
            'payment_recorded' => $paymentRecorded,
        ]);

        return [
            'parent_user_id' => $parentUserId,
            'parent_existed' => (bool)$adopted['existed'],
            'student_user_ids' => $studentUserIds,
            'reservation_ids' => $reservationIds,
            'payment_recorded' => $paymentRecorded,
            'payment_notice' => $paymentNotice,
        ];
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

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
