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
     * the semester row) as debits on the student's ledger. Idempotent per
     * (student, semester, entry_type): a student confirming a second
     * reservation in the same semester (e.g. a second instrument) is not
     * charged twice.
     */
    public static function postSemesterConfirmationCharges(?UserContext $ctx, int $studentUserId, int $semesterId, int $durationMinutes = 30): void {
        self::assertAdmin($ctx);
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('Semester not found.');
        }
        $charges = [
            'registration' => [SemesterManagement::registrationFeeCents($semester), 'Semester registration'],
            'lessons' => [SemesterManagement::lessonFeeCents($semester, $durationMinutes), 'Semester lessons'],
            'recital_fee' => [SemesterManagement::recitalFeeCents($semester), 'Recital fee'],
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
                    COALESCE(SUM(CASE WHEN le.accounting_type='debit' THEN le.amount_cents ELSE 0 END), 0) AS charged_cents,
                    COALESCE(SUM(CASE WHEN le.accounting_type='credit' THEN le.amount_cents ELSE 0 END), 0) AS paid_cents
             FROM ledger_entries le
             LEFT JOIN semesters s ON s.id = le.semester_id
             WHERE le.for_student_user_id = ?
             GROUP BY le.semester_id, s.season, s.year, s.start_date, s.end_date
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

            $rows[] = [
                'semester_id' => $semesterId,
                'label' => $semesterId !== null
                    ? ucfirst((string)$row['season']) . ' ' . (int)$row['year']
                    : 'Other charges',
                'start_date' => $row['start_date'] !== null ? (string)$row['start_date'] : null,
                'end_date' => $row['end_date'] !== null ? (string)$row['end_date'] : null,
                'charged_cents' => $charged,
                'paid_cents' => $paid,
                'balance_cents' => $balance,
                'lessons_total' => $counts['total'],
                'lessons_elapsed' => $counts['elapsed'],
                'behind' => $row['start_date'] !== null && self::isSemesterPaymentBehind(
                    $balance, $charged, $paid, (string)$row['start_date'],
                    $counts['elapsed'], $counts['total'], $today
                ),
            ];
        }
        return $rows;
    }

    /**
     * What a child's card shows: the balance, and whether any part of it has
     * fallen behind the schedule families are asked to keep.
     *   ['balance_cents', 'due_cents', 'behind', 'behind_labels', 'semesters']
     * balance_cents is the all-time balance (negative = credit); due_cents is
     * what is actually owed once credits have been applied forward.
     */
    public static function balanceSummaryForStudent(int $studentUserId, ?string $today = null): array {
        $semesters = self::semesterBalancesForStudent($studentUserId, $today);
        $due = 0;
        $behindLabels = [];
        foreach ($semesters as $row) {
            $due += $row['balance_cents'];
            if ($row['behind']) {
                $behindLabels[] = $row['label'];
            }
        }
        return [
            'balance_cents' => self::balanceForStudentCents($studentUserId),
            'due_cents' => $due,
            'behind' => (bool)$behindLabels,
            'behind_labels' => $behindLabels,
            'semesters' => $semesters,
        ];
    }

    /**
     * Is an unpaid semester balance behind what the family was asked to pay?
     *
     * Families are asked for half the term's charges by two weeks before it
     * starts, and the rest by the lesson before its half-way point (of 14
     * lessons, by the 6th). So a balance is behind when the term is close
     * enough to count and either of those two moments has passed unpaid.
     *
     * Pure on purpose — the caller supplies the term's totals and lesson
     * counts, so the rule can be read (and tested) on its own.
     */
    public static function isSemesterPaymentBehind(
        int $balanceCents,
        int $chargedCents,
        int $paidCents,
        string $semesterStartDate,
        int $lessonsElapsed,
        int $lessonsTotal,
        ?string $today = null
    ): bool {
        if ($balanceCents <= 0) {
            return false;
        }
        $todayTs = strtotime($today ?? date('Y-m-d'));
        $startTs = strtotime($semesterStartDate);
        if ($startTs === false || $todayTs === false) {
            return false;
        }
        // Still more than two weeks out: nothing is late yet.
        if ($startTs > $todayTs + 14 * 86400) {
            return false;
        }
        // Half the term should be paid for by now.
        if ($chargedCents > 0 && $paidCents * 2 < $chargedCents) {
            return true;
        }
        // And the rest by the lesson before the half-way point.
        return $lessonsTotal > 0 && $lessonsElapsed >= ($lessonsTotal / 2) - 1;
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
