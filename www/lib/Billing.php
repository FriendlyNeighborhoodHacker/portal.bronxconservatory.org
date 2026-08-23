<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';

// Light accounting so balances are explainable. Every row is a ledger entry
// in integer cents; a student's balance = SUM(debits) - SUM(credits).
// Confirming a semester reservation posts the registration / lessons /
// recital-fee / installment-plan-fee debits; payments, scholarships, and
// adjustments are credits.
class Billing {

    public const ENTRY_TYPES = ['registration', 'lessons', 'recital_fee', 'installment_plan_fee', 'payment', 'scholarship_application', 'other'];

    /** The entry types auto-posted when a semester reservation is confirmed. */
    private const CONFIRMATION_CHARGES = ['registration', 'lessons', 'recital_fee', 'installment_plan_fee'];

    private static function pdo(): PDO {
        return pdo();
    }

    // ── Semester confirmation charges ─────────────────────────────────────

    /**
     * Post the semester's charges (registration + lessons + recital fee, from
     * the semester row) as debits on the student's ledger. The installment
     * plan fee is included only when the admin opted the family in
     * ($includeInstallmentFee) — otherwise the daily sweep applies it later if
     * the balance goes unpaid. Idempotent per (student, semester, entry_type):
     * a student confirming a second reservation in the same semester (e.g. a
     * second instrument) is not charged twice.
     */
    public static function postSemesterConfirmationCharges(?UserContext $ctx, int $studentUserId, int $semesterId, int $durationMinutes = 30, bool $includeInstallmentFee = false): void {
        self::assertAdmin($ctx);
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('Semester not found.');
        }
        $charges = self::confirmationChargeAmounts($semester, $durationMinutes);
        if ($includeInstallmentFee) {
            $charges['installment_plan_fee'] = [SemesterManagement::installmentPlanFeeCents($semester), 'Installment plan fee'];
        }
        foreach ($charges as $entryType => [$amountCents, $description]) {
            if ($amountCents <= 0) {
                continue;
            }
            // Skip only while a live (not fully reversed) debit of this type
            // exists — so re-confirming after a reversal re-posts the charge.
            if (self::liveDebitCents($studentUserId, $semesterId, $entryType) > 0) {
                continue;
            }
            self::insertEntry($ctx, $studentUserId, date('Y-m-d'), 'debit', $entryType, $amountCents, $semesterId, $description);
        }
        self::log($ctx, 'billing.confirmation_charges_posted', [
            'student_user_id' => $studentUserId, 'semester_id' => $semesterId,
            'include_installment_fee' => $includeInstallmentFee,
        ]);
    }

    /** The base confirmation charges (installment fee handled separately). */
    private static function confirmationChargeAmounts(array $semester, int $durationMinutes): array {
        return [
            'registration' => [SemesterManagement::registrationFeeCents($semester), 'Semester registration'],
            'lessons' => [SemesterManagement::lessonFeeCents($semester, $durationMinutes), 'Semester lessons'],
            'recital_fee' => [SemesterManagement::recitalFeeCents($semester), 'Recital fee'],
        ];
    }

    /**
     * The line items that WOULD post if this student's reservation were
     * confirmed now — what the admin's confirmation dialog shows. Read-only.
     */
    public static function confirmationChargesPreview(int $studentUserId, int $semesterId, int $durationMinutes): array {
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('Semester not found.');
        }
        $lines = [];
        $total = 0;
        foreach (self::confirmationChargeAmounts($semester, $durationMinutes) as $entryType => [$amountCents, $description]) {
            if ($amountCents <= 0) {
                continue;
            }
            $willPost = self::liveDebitCents($studentUserId, $semesterId, $entryType) <= 0;
            $lines[] = [
                'entry_type' => $entryType,
                'description' => $description,
                'amount_cents' => $amountCents,
                'will_post' => $willPost,
                'skip_reason' => $willPost ? null : 'already charged this semester',
            ];
            if ($willPost) {
                $total += $amountCents;
            }
        }
        $feeCents = SemesterManagement::installmentPlanFeeCents($semester);
        return [
            'lines' => $lines,
            'total_cents' => $total,
            'installment_fee_cents' => $feeCents,
            'installment_available' => $feeCents > 0
                && self::liveDebitCents($studentUserId, $semesterId, 'installment_plan_fee') <= 0,
            'installment_note' => SemesterManagement::installmentFeeNotice($semester),
        ];
    }

    /**
     * What un-confirming (or deleting) a confirmed reservation would reverse —
     * or why nothing will be. Read-only; mirrors the guards in
     * reverseSemesterConfirmationCharges. $excludingReservationId is the
     * reservation about to be unconfirmed: the preview runs before the status
     * flips, so it must not count as "another confirmed reservation".
     */
    public static function reversalPreview(int $studentUserId, int $semesterId, ?int $excludingReservationId = null): array {
        $blockedReason = null;
        if (self::studentHasOccurredLessonInSemester($studentUserId, $semesterId)) {
            $blockedReason = 'the student has already had a lesson this semester';
        } elseif (self::studentHasConfirmedReservationInSemester($studentUserId, $semesterId, $excludingReservationId)) {
            $blockedReason = 'the student holds another confirmed reservation this semester';
        }
        $lines = [];
        $total = 0;
        foreach (self::CONFIRMATION_CHARGES as $entryType) {
            $remaining = self::liveDebitCents($studentUserId, $semesterId, $entryType);
            if ($remaining <= 0) {
                continue;
            }
            $lines[] = ['entry_type' => $entryType, 'amount_cents' => $remaining];
            $total += $remaining;
        }
        return [
            'will_reverse' => $blockedReason === null && $total > 0,
            'blocked_reason' => $blockedReason,
            'lines' => $lines,
            'total_cents' => $total,
        ];
    }

    /**
     * The daily installment-fee sweep. For every semester in progress past its
     * first day, post the installment plan fee to each confirmed student who
     * (a) still owes part of that semester's balance (credit rolled forward
     * from earlier terms counts as payment) and (b) has not already been
     * charged the fee. Idempotent: the live-debit check makes a second run on
     * the same day (or any later day) post nothing new.
     *
     * $ctx null = system run (the daily cron): entries carry a NULL
     * created_by_user_id and the activity log records a system action, the
     * same way Stripe webhook writes do.
     *
     * Returns ['applied' => rows, 'skipped' => int, 'semesters' => int];
     * each applied row: student_user_id, student_name, semester_id,
     * semester_label, amount_cents.
     */
    public static function applyAutomaticInstallmentFees(?UserContext $ctx, ?string $today = null, bool $dryRun = false): array {
        if ($ctx !== null) {
            self::assertAdmin($ctx);
        }
        $today = $today !== null ? self::normalizeDate($today) : date('Y-m-d');

        // In progress and at least the second day: start_date < today <= end_date.
        $st = self::pdo()->prepare('SELECT * FROM semesters WHERE start_date < ? AND end_date >= ?');
        $st->execute([$today, $today]);
        $semesters = $st->fetchAll();

        $applied = [];
        $skipped = 0;
        $semestersSwept = 0;
        foreach ($semesters as $semester) {
            $feeCents = SemesterManagement::installmentPlanFeeCents($semester);
            if ($feeCents <= 0) {
                continue;
            }
            $semestersSwept++;
            $semesterId = (int)$semester['id'];

            $st = self::pdo()->prepare(
                "SELECT DISTINCT r.student_user_id, u.first_name, u.last_name
                 FROM semester_lesson_reservations r
                 JOIN users u ON u.id = r.student_user_id
                 WHERE r.semester_id = ? AND r.status = 'confirmed'"
            );
            $st->execute([$semesterId]);
            foreach ($st->fetchAll() as $student) {
                $studentUserId = (int)$student['student_user_id'];
                if (self::liveDebitCents($studentUserId, $semesterId, 'installment_plan_fee') > 0) {
                    $skipped++;
                    continue;
                }
                $unpaid = false;
                foreach (self::semesterBalancesForStudent($studentUserId, $today) as $row) {
                    if ($row['semester_id'] === $semesterId && $row['balance_cents'] > 0) {
                        $unpaid = true;
                        break;
                    }
                }
                if (!$unpaid) {
                    $skipped++;
                    continue;
                }
                if (!$dryRun) {
                    self::insertEntry(
                        $ctx, $studentUserId, $today, 'debit', 'installment_plan_fee',
                        $feeCents, $semesterId, 'Installment plan fee'
                    );
                    self::log($ctx, 'billing.installment_fee_auto_applied', [
                        'student_user_id' => $studentUserId, 'semester_id' => $semesterId,
                        'amount_cents' => $feeCents,
                    ]);
                }
                $applied[] = [
                    'student_user_id' => $studentUserId,
                    'student_name' => trim((string)$student['first_name'] . ' ' . (string)$student['last_name']),
                    'semester_id' => $semesterId,
                    'semester_label' => SemesterManagement::label($semester),
                    'amount_cents' => $feeCents,
                ];
            }
        }
        if (!$dryRun && $applied) {
            self::log($ctx, 'billing.installment_fee_run', [
                'date' => $today, 'applied' => count($applied), 'skipped' => $skipped,
            ]);
        }
        return ['applied' => $applied, 'skipped' => $skipped, 'semesters' => $semestersSwept];
    }

    /**
     * Undo the auto-posted confirmation charges when a reservation is
     * unconfirmed — but only when the student has had no lessons yet this
     * semester and holds no other confirmed reservation in it. The reversal
     * posts offsetting credits of entry_type 'other' (the debits stay for the
     * audit trail). In any other situation nothing is posted: the admin makes
     * a custom adjustment.
     */
    public static function reverseSemesterConfirmationCharges(?UserContext $ctx, int $studentUserId, int $semesterId): bool {
        self::assertAdmin($ctx);

        if (self::studentHasOccurredLessonInSemester($studentUserId, $semesterId)) {
            return false;
        }
        if (self::studentHasConfirmedReservationInSemester($studentUserId, $semesterId)) {
            return false;
        }

        $reversed = false;
        foreach (self::CONFIRMATION_CHARGES as $entryType) {
            $remaining = self::liveDebitCents($studentUserId, $semesterId, $entryType);
            if ($remaining <= 0) {
                continue;
            }
            self::insertEntry(
                $ctx, $studentUserId, date('Y-m-d'), 'credit', 'other', $remaining, $semesterId,
                'Reversal: ' . str_replace('_', ' ', $entryType) . ' (registration unconfirmed)'
            );
            $reversed = true;
        }
        if ($reversed) {
            self::log($ctx, 'billing.confirmation_charges_reversed', [
                'student_user_id' => $studentUserId, 'semester_id' => $semesterId,
            ]);
        }
        return $reversed;
    }

    // ── Duration-change accounting ────────────────────────────────────────

    /**
     * Calculate lessons used and remaining for a reservation duration change.
     *
     * Returns: ['lessons_total', 'lessons_used', 'lessons_remaining']
     *   - lessons_total: count of all lessons generated for this reservation
     *   - lessons_used: lessons_total - lessons_remaining
     *   - lessons_remaining: count of future lessons (not yet occurred or cancelled)
     *
     * Cancelled lessons are counted as "used" for the purposes of this calculation
     * (since the student was still charged for them).
     */
    public static function lessonsUsedAndRemaining(int $reservationId, int $semesterId): array {
        // Total lessons created for this reservation
        $st = self::pdo()->prepare(
            "SELECT COUNT(*) FROM lessons
             WHERE semester_lesson_reservation_id = ? AND lesson_number > 0"
        );
        $st->execute([$reservationId]);
        $lessonsTotal = (int)$st->fetchColumn();

        // Remaining lessons: not yet occurred (attended IS NULL or 0) and not cancelled
        $st = self::pdo()->prepare(
            "SELECT COUNT(*) FROM lessons
             WHERE semester_lesson_reservation_id = ? AND lesson_number > 0
               AND cancelled_at IS NULL
               AND (attended IS NULL OR attended = 0)"
        );
        $st->execute([$reservationId]);
        $lessonsRemaining = (int)$st->fetchColumn();

        $lessonsUsed = $lessonsTotal - $lessonsRemaining;
        return [
            'lessons_total' => $lessonsTotal,
            'lessons_used' => max(0, $lessonsUsed),
            'lessons_remaining' => $lessonsRemaining,
        ];
    }

    /**
     * Calculate the default refund and new charge for a duration change.
     *
     * The semester's lesson fee is priced for a full "lessons_per_semester" package,
     * but only some lessons may have been generated for this specific reservation.
     * We calculate per-lesson cost using lessons_per_semester (for consistency with
     * the original charge), but track used/remaining against actual lessons generated.
     *
     * Returns: [
     *   'lessons_total', 'lessons_used', 'lessons_remaining',
     *   'lessons_per_semester',
     *   'original_fee_cents', 'amount_spent_cents', 'refund_cents',
     *   'new_fee_cents', 'new_charge_cents'
     * ]
     *
     * Refund = (original payment) - (original fee per lesson × lessons used)
     * New charge = (new fee per lesson) × lessons remaining
     */
    public static function durationChangeLedgerCalculation(
        int $reservationId,
        int $semesterId,
        int $originalDurationMinutes,
        int $newDurationMinutes
    ): array {
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('Semester not found.');
        }

        $lessons = self::lessonsUsedAndRemaining($reservationId, $semesterId);
        $lessonsTotal = $lessons['lessons_total'];
        $lessonsUsed = $lessons['lessons_used'];
        $lessonsRemaining = $lessons['lessons_remaining'];
        $lessonsPerSemester = SemesterManagement::lessonsPerSemester($semester);

        // Original fee for the full semester at original duration
        $originalFeeCents = SemesterManagement::lessonFeeCents($semester, $originalDurationMinutes);

        // Fee per lesson based on the semester's lessons_per_semester (for consistent accounting)
        $feePerLesson = $lessonsPerSemester > 0 ? intdiv($originalFeeCents, $lessonsPerSemester) : 0;
        $amountSpentCents = $feePerLesson * $lessonsUsed;

        // Refund = original payment - amount spent
        $refundCents = max(0, $originalFeeCents - $amountSpentCents);

        // New fee for the full semester at new duration
        $newFeeCents = SemesterManagement::lessonFeeCents($semester, $newDurationMinutes);

        // New charge = fee per lesson (at new rate) × lessons remaining
        $newFeePerLesson = $lessonsPerSemester > 0 ? intdiv($newFeeCents, $lessonsPerSemester) : 0;
        $newChargeCents = $newFeePerLesson * $lessonsRemaining;

        return [
            'lessons_total' => $lessonsTotal,
            'lessons_used' => $lessonsUsed,
            'lessons_remaining' => $lessonsRemaining,
            'lessons_per_semester' => $lessonsPerSemester,
            'original_fee_cents' => $originalFeeCents,
            'amount_spent_cents' => $amountSpentCents,
            'refund_cents' => $refundCents,
            'new_fee_cents' => $newFeeCents,
            'new_charge_cents' => $newChargeCents,
        ];
    }

    /**
     * Post the ledger entries for a reservation duration change.
     * Creates a credit for the refund and a debit for the new charge.
     *
     * Returns: [refund_entry_id, new_charge_entry_id]
     */
    public static function postDurationChangeEntries(
        ?UserContext $ctx,
        int $studentUserId,
        int $semesterId,
        int $refundCents,
        int $newChargeCents,
        int $originalDurationMinutes,
        int $newDurationMinutes
    ): array {
        self::assertAdmin($ctx);

        $refundId = null;
        $chargeId = null;

        if ($refundCents > 0) {
            $refundId = self::insertEntry(
                $ctx, $studentUserId, date('Y-m-d'), 'credit', 'other',
                $refundCents, $semesterId,
                "Duration change refund: {$originalDurationMinutes}→{$newDurationMinutes} min"
            );
        }

        if ($newChargeCents > 0) {
            $chargeId = self::insertEntry(
                $ctx, $studentUserId, date('Y-m-d'), 'debit', 'lessons',
                $newChargeCents, $semesterId,
                "Duration change charge: {$originalDurationMinutes}→{$newDurationMinutes} min"
            );
        }

        if ($refundId || $chargeId) {
            self::log($ctx, 'billing.duration_change_posted', [
                'student_user_id' => $studentUserId,
                'semester_id' => $semesterId,
                'original_duration_minutes' => $originalDurationMinutes,
                'new_duration_minutes' => $newDurationMinutes,
                'refund_cents' => $refundCents,
                'new_charge_cents' => $newChargeCents,
            ]);
        }

        return [$refundId, $chargeId];
    }

    // ── Manual entries ─────────────────────────────────────────────────────

    /** Record a payment taken outside Stripe (check, cash, Zelle, ...). */
    public static function recordManualPayment(?UserContext $ctx, int $studentUserId, int $amountCents, string $date, ?int $semesterId, string $description): int {
        self::assertAdmin($ctx);
        self::assertPositive($amountCents);
        $id = self::insertEntry($ctx, $studentUserId, self::normalizeDate($date), 'credit', 'payment', $amountCents, $semesterId, $description);
        self::log($ctx, 'billing.manual_payment', ['student_user_id' => $studentUserId, 'amount_cents' => $amountCents]);
        return $id;
    }

    public static function applyScholarship(?UserContext $ctx, int $studentUserId, ?int $semesterId, int $amountCents, string $description): int {
        self::assertAdmin($ctx);
        self::assertPositive($amountCents);
        $id = self::insertEntry($ctx, $studentUserId, date('Y-m-d'), 'credit', 'scholarship_application', $amountCents, $semesterId, $description);
        self::log($ctx, 'billing.scholarship_applied', ['student_user_id' => $studentUserId, 'amount_cents' => $amountCents]);
        return $id;
    }

    /** A custom debit or credit ("other" by default) an admin explains via description. */
    public static function addCustomEntry(?UserContext $ctx, int $studentUserId, string $accountingType, int $amountCents, ?int $semesterId, string $description, string $entryType = 'other'): int {
        self::assertAdmin($ctx);
        self::assertPositive($amountCents);
        if (!in_array($accountingType, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Accounting type must be debit or credit.');
        }
        if (!in_array($entryType, self::ENTRY_TYPES, true)) {
            throw new InvalidArgumentException('Unknown entry type.');
        }
        if (trim($description) === '') {
            throw new InvalidArgumentException('A description is required for custom ledger entries.');
        }
        $id = self::insertEntry($ctx, $studentUserId, date('Y-m-d'), $accountingType, $entryType, $amountCents, $semesterId, $description);
        self::log($ctx, 'billing.custom_entry', [
            'student_user_id' => $studentUserId, 'accounting_type' => $accountingType, 'amount_cents' => $amountCents,
        ]);
        return $id;
    }

    /**
     * Record a completed Stripe Checkout payment as a credit. Idempotent via
     * the (stripe_checkout_session_id, for_student_user_id) unique key, so
     * the webhook and the success-redirect fallback can race harmlessly.
     * Returns false when this session's payment for this student was already
     * recorded. $ctx is null for webhook-recorded payments.
     */
    public static function recordStripePayment(int $studentUserId, int $amountCents, string $checkoutSessionId, ?string $paymentIntentId, ?int $semesterId): bool {
        self::assertPositive($amountCents);
        if (trim($checkoutSessionId) === '') {
            throw new InvalidArgumentException('A Stripe checkout session id is required.');
        }
        $st = self::pdo()->prepare(
            'INSERT IGNORE INTO ledger_entries
               (for_student_user_id, entry_date, accounting_type, entry_type, amount_cents, semester_id,
                description, stripe_checkout_session_id, stripe_payment_intent_id, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,NULL)'
        );
        $st->execute([
            $studentUserId, date('Y-m-d'), 'credit', 'payment', $amountCents, $semesterId,
            'Online payment (Stripe)', $checkoutSessionId, $paymentIntentId,
        ]);
        $recorded = $st->rowCount() > 0;
        if ($recorded) {
            self::log(null, 'billing.stripe_payment_recorded', [
                'student_user_id' => $studentUserId,
                'amount_cents' => $amountCents,
                'checkout_session_id' => $checkoutSessionId,
            ]);
        }
        return $recorded;
    }

    /**
     * Record a completed PaymentIntent (the embedded card form on Balance &
     * Payments) as a credit. Idempotent via the (stripe_payment_intent_id,
     * for_student_user_id) unique key — the webhook and the browser's return
     * trip both try, and only the first one writes. Returns false when this
     * intent's payment for this student was already recorded.
     */
    public static function recordStripeIntentPayment(int $studentUserId, int $amountCents, string $paymentIntentId, ?int $semesterId, string $description = 'Online payment (Stripe)'): bool {
        self::assertPositive($amountCents);
        if (trim($paymentIntentId) === '') {
            throw new InvalidArgumentException('A Stripe payment intent id is required.');
        }
        $st = self::pdo()->prepare(
            'INSERT IGNORE INTO ledger_entries
               (for_student_user_id, entry_date, accounting_type, entry_type, amount_cents, semester_id,
                description, stripe_payment_intent_id, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,NULL)'
        );
        $st->execute([
            $studentUserId, date('Y-m-d'), 'credit', 'payment', $amountCents, $semesterId,
            $description, $paymentIntentId,
        ]);
        $recorded = $st->rowCount() > 0;
        if ($recorded) {
            self::log(null, 'billing.stripe_payment_recorded', [
                'student_user_id' => $studentUserId,
                'amount_cents' => $amountCents,
                'payment_intent_id' => $paymentIntentId,
            ]);
        }
        return $recorded;
    }

    /**
     * One ledger row loaded from a CSV when the portal takes over an existing
     * roster: the charges a family already ran up and the payments they
     * already made, on the dates they actually happened. Unlike the other
     * writers here nothing is derived — the caller states the date, the side
     * of the ledger and the entry type — because this is history, not a
     * charge the portal is deciding to post.
     *
     * $fields: student_user_id, entry_date, accounting_type, entry_type,
     * amount_cents, semester_id (nullable), description.
     */
    public static function importEntry(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);
        $amountCents = (int)($fields['amount_cents'] ?? 0);
        self::assertPositive($amountCents);

        $accountingType = (string)($fields['accounting_type'] ?? '');
        if (!in_array($accountingType, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Accounting type must be debit or credit.');
        }
        $entryType = (string)($fields['entry_type'] ?? '');
        if (!in_array($entryType, self::ENTRY_TYPES, true)) {
            throw new InvalidArgumentException('Unknown entry type.');
        }
        $studentUserId = (int)($fields['student_user_id'] ?? 0);
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('A student is required.');
        }
        $semesterId = isset($fields['semester_id']) ? (int)$fields['semester_id'] : null;

        $id = self::insertEntry(
            $ctx,
            $studentUserId,
            self::normalizeDate((string)($fields['entry_date'] ?? '')),
            $accountingType,
            $entryType,
            $amountCents,
            $semesterId !== null && $semesterId > 0 ? $semesterId : null,
            (string)($fields['description'] ?? '')
        );
        self::log($ctx, 'billing.entry_imported', [
            'student_user_id' => $studentUserId, 'entry_type' => $entryType,
            'accounting_type' => $accountingType, 'amount_cents' => $amountCents,
        ]);
        return $id;
    }

    /**
     * Is this exact row already on the ledger? Lets a re-run of the same
     * initialization file report "no change" instead of doubling everyone's
     * balance. $fields as for importEntry().
     */
    public static function entryExists(array $fields): bool {
        $semesterId = isset($fields['semester_id']) ? (int)$fields['semester_id'] : 0;
        $st = self::pdo()->prepare(
            'SELECT 1 FROM ledger_entries
             WHERE for_student_user_id=? AND entry_date=? AND accounting_type=? AND entry_type=?
               AND amount_cents=? AND ' . ($semesterId > 0 ? 'semester_id=?' : 'semester_id IS NULL') . '
             LIMIT 1'
        );
        $params = [
            (int)($fields['student_user_id'] ?? 0),
            self::normalizeDate((string)($fields['entry_date'] ?? '')),
            (string)($fields['accounting_type'] ?? ''),
            (string)($fields['entry_type'] ?? ''),
            (int)($fields['amount_cents'] ?? 0),
        ];
        if ($semesterId > 0) {
            $params[] = $semesterId;
        }
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    /** "$1,234.56", "1234.56", "1234" -> cents. Null when it isn't money. */
    public static function parseAmountCents(string $amount): ?int {
        $amount = trim($amount);
        if ($amount === '') {
            return null;
        }
        $negative = str_starts_with($amount, '-') || (str_starts_with($amount, '(') && str_ends_with($amount, ')'));
        $digits = preg_replace('/[^0-9.]/', '', $amount) ?? '';
        if ($digits === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $digits)) {
            return null;
        }
        $cents = (int)round(((float)$digits) * 100);
        return $negative ? -$cents : $cents;
    }

    // ── Balances ───────────────────────────────────────────────────────────

    /** All-time balance in cents: positive = the family owes money. */
    public static function balanceForStudentCents(int $studentUserId): int {
        $st = self::pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN accounting_type='debit' THEN amount_cents ELSE -amount_cents END), 0)
             FROM ledger_entries WHERE for_student_user_id = ?"
        );
        $st->execute([$studentUserId]);
        return (int)$st->fetchColumn();
    }

    public static function balanceForStudentSemesterCents(int $studentUserId, int $semesterId): int {
        $st = self::pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN accounting_type='debit' THEN amount_cents ELSE -amount_cents END), 0)
             FROM ledger_entries WHERE for_student_user_id = ? AND semester_id = ?"
        );
        $st->execute([$studentUserId, $semesterId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Balance data for a set of students at once (one query — feeds the
     * Semester Schedule grid's color coding). Returns, per student id:
     *   ['semester_debit_cents', 'semester_credit_cents', 'total_balance_cents']
     * Students with no ledger rows are present with zeros.
     */
    public static function semesterBalancesByStudent(int $semesterId, array $studentUserIds): array {
        $out = [];
        foreach ($studentUserIds as $id) {
            $out[(int)$id] = ['semester_debit_cents' => 0, 'semester_credit_cents' => 0, 'total_balance_cents' => 0];
        }
        if (!$out) {
            return $out;
        }
        $ids = array_keys($out);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $st = self::pdo()->prepare(
            "SELECT for_student_user_id,
                    COALESCE(SUM(CASE WHEN semester_id = ? AND accounting_type='debit' THEN amount_cents ELSE 0 END), 0) AS semester_debit_cents,
                    COALESCE(SUM(CASE WHEN semester_id = ? AND accounting_type='credit' THEN amount_cents ELSE 0 END), 0) AS semester_credit_cents,
                    COALESCE(SUM(CASE WHEN accounting_type='debit' THEN amount_cents ELSE -amount_cents END), 0) AS total_balance_cents
             FROM ledger_entries
             WHERE for_student_user_id IN ($placeholders)
             GROUP BY for_student_user_id"
        );
        $st->execute(array_merge([$semesterId, $semesterId], $ids));
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['for_student_user_id']] = [
                'semester_debit_cents' => (int)$row['semester_debit_cents'],
                'semester_credit_cents' => (int)$row['semester_credit_cents'],
                'total_balance_cents' => (int)$row['total_balance_cents'],
            ];
        }
        return $out;
    }

    /** A parent's balance: the sum over their children's balances. */
    public static function balanceForParentCents(int $parentUserId): int {
        $st = self::pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN le.accounting_type='debit' THEN le.amount_cents ELSE -le.amount_cents END), 0)
             FROM parenthood ph
             JOIN ledger_entries le ON le.for_student_user_id = ph.child_user_id
             WHERE ph.parent_user_id = ?"
        );
        $st->execute([$parentUserId]);
        return (int)$st->fetchColumn();
    }

    /** Per-child balances for a parent: [child_user_id => cents]. */
    public static function balancesForParentChildren(int $parentUserId): array {
        $st = self::pdo()->prepare(
            "SELECT ph.child_user_id,
                    COALESCE(SUM(CASE WHEN le.accounting_type='debit' THEN le.amount_cents ELSE -le.amount_cents END), 0) AS balance_cents
             FROM parenthood ph
             LEFT JOIN ledger_entries le ON le.for_student_user_id = ph.child_user_id
             WHERE ph.parent_user_id = ?
             GROUP BY ph.child_user_id"
        );
        $st->execute([$parentUserId]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['child_user_id']] = (int)$row['balance_cents'];
        }
        return $out;
    }

    // ── Balances by semester, and whether they are behind ──────────────────

    /**
     * A student's balance broken out by the semester it belongs to, oldest
     * term first, with any entry that belongs to no term last. Only terms the
     * student has ledger entries in appear.
     *
     * A credit beyond what a term owes rolls forward to the next one, which is
     * how money is applied in practice: a family who overpays in the spring is
     * not shown a spring credit next to a fall bill. Each row carries what the
     * card and the Billing page need to explain itself:
     *
     *   semester_id, label, start_date, end_date,
     *   charged_cents, paid_cents (including credit rolled in from earlier),
     *   balance_cents (never negative — a surplus moves on),
     *   lessons_total, lessons_elapsed, behind
     *
     * The trailing surplus, if any, is the family credit and is not a row.
     */
    public static function semesterBalancesForStudent(int $studentUserId, ?string $today = null): array {
        $st = self::pdo()->prepare(
            "SELECT le.semester_id, s.season, s.year, s.start_date, s.end_date,
                    s.second_installment_due_date, s.installment_plan_fee,
                    COALESCE(SUM(CASE WHEN le.accounting_type='debit' THEN le.amount_cents ELSE 0 END), 0) AS charged_cents,
                    COALESCE(SUM(CASE WHEN le.accounting_type='credit' THEN le.amount_cents ELSE 0 END), 0) AS paid_cents,
                    COALESCE(SUM(CASE WHEN le.accounting_type='debit' AND le.entry_type='installment_plan_fee' THEN le.amount_cents ELSE 0 END), 0) AS installment_fee_cents
             FROM ledger_entries le
             LEFT JOIN semesters s ON s.id = le.semester_id
             WHERE le.for_student_user_id = ?
             GROUP BY le.semester_id, s.season, s.year, s.start_date, s.end_date,
                      s.second_installment_due_date, s.installment_plan_fee
             ORDER BY s.start_date IS NULL, s.start_date, le.semester_id"
        );
        $st->execute([$studentUserId]);

        $rows = [];
        $surplus = 0; // credit carried out of the terms already walked, in cents
        foreach ($st->fetchAll() as $row) {
            $semesterId = $row['semester_id'] !== null ? (int)$row['semester_id'] : null;
            $charged = (int)$row['charged_cents'];
            $paid = (int)$row['paid_cents'] + $surplus;
            $balance = $charged - $paid;
            if ($balance < 0) {
                $surplus = -$balance;
                $paid = $charged;
                $balance = 0;
            } else {
                $surplus = 0;
            }

            $counts = ($balance > 0 && $semesterId !== null)
                ? self::lessonCountsForStudentInSemester($studentUserId, $semesterId, $today)
                : ['total' => 0, 'elapsed' => 0];

            $behindReason = $row['start_date'] !== null
                ? self::semesterPaymentBehindReason(
                    $balance, $charged, $paid, (string)$row['start_date'],
                    $counts['elapsed'], $counts['total'],
                    (int)$row['installment_fee_cents'] > 0,
                    $row['second_installment_due_date'] !== null ? (string)$row['second_installment_due_date'] : null,
                    $today
                )
                : null;

            $rows[] = [
                'semester_id' => $semesterId,
                'label' => $semesterId !== null
                    ? ucfirst((string)$row['season']) . ' ' . (int)$row['year']
                    : 'Other charges',
                'start_date' => $row['start_date'] !== null ? (string)$row['start_date'] : null,
                'end_date' => $row['end_date'] !== null ? (string)$row['end_date'] : null,
                'second_installment_due_date' => $row['second_installment_due_date'] !== null
                    ? (string)$row['second_installment_due_date'] : null,
                'installment_plan_fee_cents' => (int)round((float)($row['installment_plan_fee'] ?? 0) * 100),
                'on_installment_plan' => (int)$row['installment_fee_cents'] > 0,
                'charged_cents' => $charged,
                'paid_cents' => $paid,
                'balance_cents' => $balance,
                'lessons_total' => $counts['total'],
                'lessons_elapsed' => $counts['elapsed'],
                'behind' => $behindReason !== null,
                'behind_reason' => $behindReason,
            ];
        }
        return $rows;
    }

    /**
     * What a child's card shows: the balance, and whether any part of it has
     * fallen behind the schedule families are asked to keep.
     *   ['balance_cents', 'due_cents', 'behind', 'behind_labels',
     *    'behind_reasons', 'semesters']
     * balance_cents is the all-time balance (negative = credit); due_cents is
     * what is actually owed once credits have been applied forward.
     * behind_reasons: one ['label', 'reason'] per past-due term, so a page can
     * say which deadline was missed rather than just "past due".
     */
    public static function balanceSummaryForStudent(int $studentUserId, ?string $today = null): array {
        $semesters = self::semesterBalancesForStudent($studentUserId, $today);
        $due = 0;
        $behindLabels = [];
        $behindReasons = [];
        foreach ($semesters as $row) {
            $due += $row['balance_cents'];
            if ($row['behind']) {
                $behindLabels[] = $row['label'];
                $behindReasons[] = ['label' => $row['label'], 'reason' => (string)$row['behind_reason']];
            }
        }
        return [
            'balance_cents' => self::balanceForStudentCents($studentUserId),
            'due_cents' => $due,
            'behind' => (bool)$behindLabels,
            'behind_labels' => $behindLabels,
            'behind_reasons' => $behindReasons,
            'semesters' => $semesters,
        ];
    }

    /**
     * Is an unpaid semester balance behind what the family was asked to pay,
     * and if so, which deadline did it miss? Null when it is not behind;
     * otherwise a sentence fragment naming the missed deadline, ready to
     * follow the term's label on the Billing page.
     *
     * The payment schedule:
     *   - half the term's charges are due two weeks before it starts;
     *   - the rest before the first lesson — unless the family is on the
     *     installment plan ($onInstallmentPlan: an installment plan fee on
     *     this term's ledger), which extends it to the semester's explicit
     *     second-installment due date ($secondInstallmentDueDate). Legacy
     *     semesters with no date fall back to the lesson before the term's
     *     half-way point (of 14 lessons, before the 6th).
     *
     * Pure on purpose — the caller supplies the term's totals and lesson
     * counts, so the rule can be read (and tested) on its own.
     */
    public static function semesterPaymentBehindReason(
        int $balanceCents,
        int $chargedCents,
        int $paidCents,
        string $semesterStartDate,
        int $lessonsElapsed,
        int $lessonsTotal,
        bool $onInstallmentPlan,
        ?string $secondInstallmentDueDate = null,
        ?string $today = null
    ): ?string {
        if ($balanceCents <= 0) {
            return null;
        }
        $todayTs = strtotime($today ?? date('Y-m-d'));
        $startTs = strtotime($semesterStartDate);
        if ($startTs === false || $todayTs === false) {
            return null;
        }
        // Still more than two weeks out: nothing is late yet.
        if ($startTs > $todayTs + 14 * 86400) {
            return null;
        }
        // Half the term should be paid for by now.
        if ($chargedCents > 0 && $paidCents * 2 < $chargedCents) {
            return 'half of the semester balance was due by '
                . date('M j, Y', $startTs - 14 * 86400) . ', two weeks before the semester start';
        }
        if (!$onInstallmentPlan) {
            // Without the installment plan, the full balance is due before
            // the first lesson.
            return $lessonsElapsed >= 1
                ? 'the full balance was due before the first lesson (accounts without an installment plan pay in full before lessons begin)'
                : null;
        }
        // On the installment plan: the rest by the semester's explicit
        // second-installment due date when one is set.
        if ($secondInstallmentDueDate !== null && $secondInstallmentDueDate !== '') {
            $dueTs = strtotime($secondInstallmentDueDate);
            if ($dueTs !== false) {
                return $todayTs > $dueTs
                    ? 'under the installment plan, the full balance was due by ' . date('M j, Y', $dueTs)
                    : null;
            }
        }
        // Legacy semesters (no explicit date): the rest by the lesson before
        // the half-way point.
        if ($lessonsTotal > 0 && $lessonsElapsed >= ($lessonsTotal / 2) - 1) {
            $dueBeforeLesson = (int)ceil(($lessonsTotal / 2) - 1);
            return $dueBeforeLesson >= 1
                ? 'under the installment plan, the full balance was due before the '
                    . self::ordinal($dueBeforeLesson) . ' lesson'
                : 'under the installment plan, the full balance was due before lessons began';
        }
        return null;
    }

    /**
     * Is an unpaid semester balance behind what the family was asked to pay?
     * The boolean face of semesterPaymentBehindReason(), on the installment
     * schedule (the more lenient of the two).
     */
    public static function isSemesterPaymentBehind(
        int $balanceCents,
        int $chargedCents,
        int $paidCents,
        string $semesterStartDate,
        int $lessonsElapsed,
        int $lessonsTotal,
        ?string $secondInstallmentDueDate = null,
        ?string $today = null
    ): bool {
        return self::semesterPaymentBehindReason(
            $balanceCents, $chargedCents, $paidCents, $semesterStartDate,
            $lessonsElapsed, $lessonsTotal, true, $secondInstallmentDueDate, $today
        ) !== null;
    }

    /** 1 → "1st", 2 → "2nd", 6 → "6th", 12 → "12th". */
    private static function ordinal(int $n): string {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return $n . 'th';
        }
        return $n . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    }

    /**
     * The oldest term this student still owes for — where a payment should be
     * credited. Null when nothing is outstanding or the debt belongs to no
     * term.
     */
    public static function oldestOwedSemesterIdForStudent(int $studentUserId, ?string $today = null): ?int {
        foreach (self::semesterBalancesForStudent($studentUserId, $today) as $row) {
            if ($row['balance_cents'] > 0 && $row['semester_id'] !== null) {
                return $row['semester_id'];
            }
        }
        return null;
    }

    /**
     * What each of a parent's children still owes, the oldest debt first —
     * which is both the order a payment is applied in and the order the
     * Billing page reads in. Children who owe nothing are left out.
     *
     * Rows: student_user_id, first_name, last_name, due_cents,
     *       semester_id / semester_label (the oldest term still owed for,
     *       null when the debt belongs to no term).
     */
    public static function outstandingByChildForParent(int $parentUserId, ?string $today = null): array {
        $st = self::pdo()->prepare(
            'SELECT u.id, u.first_name, u.last_name
             FROM parenthood ph
             JOIN users u ON u.id = ph.child_user_id AND u.is_deleted = 0
             WHERE ph.parent_user_id = ?
             ORDER BY u.first_name, u.last_name, u.id'
        );
        $st->execute([$parentUserId]);

        $rows = [];
        foreach ($st->fetchAll() as $child) {
            $summary = self::balanceSummaryForStudent((int)$child['id'], $today);
            if ($summary['due_cents'] <= 0) {
                continue;
            }
            $oldest = null;
            foreach ($summary['semesters'] as $semesterRow) {
                if ($semesterRow['balance_cents'] > 0) {
                    $oldest = $semesterRow;
                    break;
                }
            }
            $rows[] = [
                'student_user_id' => (int)$child['id'],
                'first_name' => (string)$child['first_name'],
                'last_name' => (string)$child['last_name'],
                'due_cents' => $summary['due_cents'],
                'semester_id' => $oldest['semester_id'] ?? null,
                'semester_label' => $oldest['label'] ?? null,
                // Sort key only: a debt with no term is applied last.
                'oldest_start_date' => $oldest['start_date'] ?? '9999-12-31',
            ];
        }
        usort($rows, fn($a, $b) => [$a['oldest_start_date'], $a['first_name'], $a['student_user_id']]
                              <=> [$b['oldest_start_date'], $b['first_name'], $b['student_user_id']]);
        return $rows;
    }

    /**
     * Split one family payment across the children who owe, oldest debt first
     * (the caller's order), giving each no more than their balance. Pure.
     * Returns [studentUserId => cents], only the children who get something.
     */
    public static function allocatePaymentAcrossStudents(array $studentBalances, int $amountCents): array {
        $remaining = max(0, $amountCents);
        $allocation = [];
        foreach ($studentBalances as $studentUserId => $balanceCents) {
            if ($remaining <= 0) {
                break;
            }
            $share = min($remaining, max(0, (int)$balanceCents));
            if ($share > 0) {
                $allocation[(int)$studentUserId] = $share;
                $remaining -= $share;
            }
        }
        return $allocation;
    }

    /** The student's ledger line items (optionally one semester), oldest first. */
    public static function ledgerForStudent(int $studentUserId, ?int $semesterId = null): array {
        $sql = 'SELECT le.*, s.season, s.year
                FROM ledger_entries le
                LEFT JOIN semesters s ON s.id = le.semester_id
                WHERE le.for_student_user_id = ?';
        $params = [$studentUserId];
        if ($semesterId !== null) {
            $sql .= ' AND le.semester_id = ?';
            $params[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY le.entry_date, le.id');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** "$123.45" (negative balances render as "-$12.00"). */
    public static function formatCents(int $cents): string {
        $sign = $cents < 0 ? '-' : '';
        return $sign . '$' . number_format(abs($cents) / 100, 2);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function sumCents(int $studentUserId, int $semesterId, string $accountingType, string $entryType): int {
        $st = self::pdo()->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entries
             WHERE for_student_user_id=? AND semester_id=? AND accounting_type=? AND entry_type=?'
        );
        $st->execute([$studentUserId, $semesterId, $accountingType, $entryType]);
        return (int)$st->fetchColumn();
    }

    /** Debited minus reversed for one (student, semester, entry_type) — what still stands. */
    private static function liveDebitCents(int $studentUserId, int $semesterId, string $entryType): int {
        return self::sumCents($studentUserId, $semesterId, 'debit', $entryType)
            - self::reversedCents($studentUserId, $semesterId, $entryType);
    }

    /** Cents already reversed for a confirmation charge (matched by description). */
    private static function reversedCents(int $studentUserId, int $semesterId, string $entryType): int {
        $st = self::pdo()->prepare(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entries
             WHERE for_student_user_id=? AND semester_id=? AND accounting_type='credit'
               AND entry_type='other' AND description LIKE ?"
        );
        $st->execute([$studentUserId, $semesterId, 'Reversal: ' . str_replace('_', ' ', $entryType) . '%']);
        return (int)$st->fetchColumn();
    }

    /**
     * How many lessons the term holds for this student and how many have
     * happened: ['total' => n, 'elapsed' => k]. Cancelled lessons count as
     * neither — the family was not taught, so they cannot be late for them.
     */
    private static function lessonCountsForStudentInSemester(int $studentUserId, int $semesterId, ?string $today = null): array {
        $asOf = ($today ?? date('Y-m-d')) . ' 23:59:59';
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(l.start_datetime <= ?), 0) AS elapsed
             FROM lessons l
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE COALESCE(r.student_user_id, l.student_user_id) = ?
               AND COALESCE(r.semester_id, l.semester_id) = ?
               AND l.cancelled_at IS NULL'
        );
        $st->execute([$asOf, $studentUserId, $semesterId]);
        $row = $st->fetch() ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'elapsed' => (int)($row['elapsed'] ?? 0)];
    }

    private static function studentHasOccurredLessonInSemester(int $studentUserId, int $semesterId): bool {
        $st = self::pdo()->prepare(
            'SELECT 1 FROM lessons l
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE COALESCE(r.student_user_id, l.student_user_id) = ?
               AND COALESCE(r.semester_id, l.semester_id) = ?
               AND l.start_datetime <= NOW()
             LIMIT 1'
        );
        $st->execute([$studentUserId, $semesterId]);
        return (bool)$st->fetchColumn();
    }

    private static function studentHasConfirmedReservationInSemester(int $studentUserId, int $semesterId, ?int $excludingReservationId = null): bool {
        $sql = "SELECT 1 FROM semester_lesson_reservations
             WHERE student_user_id=? AND semester_id=? AND status='confirmed'";
        $args = [$studentUserId, $semesterId];
        if ($excludingReservationId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludingReservationId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($args);
        return (bool)$st->fetchColumn();
    }

    private static function insertEntry(?UserContext $ctx, int $studentUserId, string $date, string $accountingType, string $entryType, int $amountCents, ?int $semesterId, string $description): int {
        $st = self::pdo()->prepare(
            'INSERT INTO ledger_entries
               (for_student_user_id, entry_date, accounting_type, entry_type, amount_cents, semester_id, description, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([$studentUserId, $date, $accountingType, $entryType, $amountCents, $semesterId, $description, $ctx?->id]);
        return (int)self::pdo()->lastInsertId();
    }

    private static function assertPositive(int $amountCents): void {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Amount must be positive (cents).');
        }
    }

    private static function normalizeDate(string $date, string $label = 'Date'): string {
        $ts = strtotime(trim($date));
        if (trim($date) === '' || $ts === false) {
            throw new InvalidArgumentException($label . ' is not a valid date.');
        }
        return date('Y-m-d', $ts);
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
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
