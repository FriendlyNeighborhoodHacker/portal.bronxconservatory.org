<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Light accounting so balances are explainable. Every row is a ledger entry
// in integer cents; a student's balance = SUM(debits) - SUM(credits).
// Confirming a semester reservation posts the registration / lessons /
// recital-fee debits; payments, scholarships, and adjustments are credits.
class Billing {

    public const ENTRY_TYPES = ['registration', 'lessons', 'recital_fee', 'payment', 'scholarship_application', 'other'];

    /** The entry types auto-posted when a semester reservation is confirmed. */
    private const CONFIRMATION_CHARGES = ['registration', 'lessons', 'recital_fee'];

    private static function pdo(): PDO {
        return pdo();
    }

    // ── Semester confirmation charges ─────────────────────────────────────

    /**
     * Post the semester's charges (registration + lessons + recital fee, from
     * Settings) as debits on the student's ledger. Idempotent per
     * (student, semester, entry_type): a student confirming a second
     * reservation in the same semester (e.g. a second instrument) is not
     * charged twice.
     */
    public static function postSemesterConfirmationCharges(?UserContext $ctx, int $studentUserId, int $semesterId): void {
        self::assertAdmin($ctx);
        $charges = [
            'registration' => [Settings::registrationCostCents(), 'Semester registration'],
            'lessons' => [Settings::semesterLessonCostCents(), 'Semester lessons'],
            'recital_fee' => [Settings::recitalFeeCents(), 'Recital fee'],
        ];
        foreach ($charges as $entryType => [$amountCents, $description]) {
            if ($amountCents <= 0) {
                continue;
            }
            // Skip only while a live (not fully reversed) debit of this type
            // exists — so re-confirming after a reversal re-posts the charge.
            $liveDebit = self::sumCents($studentUserId, $semesterId, 'debit', $entryType)
                - self::reversedCents($studentUserId, $semesterId, $entryType);
            if ($liveDebit > 0) {
                continue;
            }
            self::insertEntry($ctx, $studentUserId, date('Y-m-d'), 'debit', $entryType, $amountCents, $semesterId, $description);
        }
        self::log($ctx, 'billing.confirmation_charges_posted', [
            'student_user_id' => $studentUserId, 'semester_id' => $semesterId,
        ]);
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
            $debited = self::sumCents($studentUserId, $semesterId, 'debit', $entryType);
            $alreadyReversed = self::reversedCents($studentUserId, $semesterId, $entryType);
            $remaining = $debited - $alreadyReversed;
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

    private static function studentHasOccurredLessonInSemester(int $studentUserId, int $semesterId): bool {
        $st = self::pdo()->prepare(
            'SELECT 1 FROM lessons l
             JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE r.student_user_id = ? AND r.semester_id = ? AND l.start_datetime <= NOW()
             LIMIT 1'
        );
        $st->execute([$studentUserId, $semesterId]);
        return (bool)$st->fetchColumn();
    }

    private static function studentHasConfirmedReservationInSemester(int $studentUserId, int $semesterId): bool {
        $st = self::pdo()->prepare(
            "SELECT 1 FROM semester_lesson_reservations
             WHERE student_user_id=? AND semester_id=? AND status='confirmed' LIMIT 1"
        );
        $st->execute([$studentUserId, $semesterId]);
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
