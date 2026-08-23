<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/LocationDatesCsvImport.php';

// The ledger CSV (semester wizard step 7): where every family's money stood
// when the portal took over — the registration, lesson and recital charges
// they already ran up, and the payments they already made. Nothing here is
// computed from the schedule; each row states its own date, side and amount,
// because this is history being recorded, not charges being decided.
//
// Rows land on the semester being imported. $context requires
// ['semester_id' => n].
class LedgerEntriesCsvImport {

    /**
     * Entry types an admin might type, and which side of the ledger each one
     * lands on by default. 'other' has no default: an adjustment can go
     * either way, so those rows must say.
     */
    private const ENTRY_TYPES = [
        'registration' => ['registration', 'debit'],
        'registration fee' => ['registration', 'debit'],
        'lessons' => ['lessons', 'debit'],
        'lesson' => ['lessons', 'debit'],
        'lesson fees' => ['lessons', 'debit'],
        'tuition' => ['lessons', 'debit'],
        'recital' => ['recital_fee', 'debit'],
        'recital fee' => ['recital_fee', 'debit'],
        'recital_fee' => ['recital_fee', 'debit'],
        'installment' => ['installment_plan_fee', 'debit'],
        'installment fee' => ['installment_plan_fee', 'debit'],
        'installment plan fee' => ['installment_plan_fee', 'debit'],
        'installment_plan_fee' => ['installment_plan_fee', 'debit'],
        'payment' => ['payment', 'credit'],
        'scholarship' => ['scholarship_application', 'credit'],
        'scholarship application' => ['scholarship_application', 'credit'],
        'scholarship_application' => ['scholarship_application', 'credit'],
        'other' => ['other', null],
        'adjustment' => ['other', null],
    ];

    /** Description used when a row doesn't give one. */
    private const DEFAULT_DESCRIPTIONS = [
        'registration' => 'Semester registration',
        'lessons' => 'Semester lessons',
        'recital_fee' => 'Recital fee',
        'installment_plan_fee' => 'Installment plan fee',
        'payment' => 'Payment received',
        'scholarship_application' => 'Scholarship',
        'other' => 'Adjustment',
    ];

    public static function targetFields(): array {
        return [
            'student_name' => 'Student Name',
            'entry_type' => 'Entry Type',
            'amount' => 'Amount',
            'date' => 'Date',
            'accounting_type' => 'Debit or Credit',
            'description' => 'Description',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('A semester is required for a ledger import.');
        }

        $out = [];
        // Identical rows within one file are almost always a copy-paste
        // accident; two real payments of the same amount on the same day
        // would still be one row each, so we flag rather than silently double.
        $seen = [];

        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $studentName = trim((string)($row['student_name'] ?? ''));
            $student = null;
            if ($studentName === '') {
                $status = 'error';
                $messages[] = 'Student name is required.';
            } else {
                $matches = self::matchStudents($studentName);
                if (count($matches) === 0) {
                    $status = 'error';
                    $messages[] = 'No match found for student "' . $studentName . '" — upload students first.';
                } elseif (count($matches) > 1) {
                    $status = 'error';
                    $messages[] = 'Multiple students match "' . $studentName . '" — use their email instead.';
                } else {
                    $student = $matches[0];
                }
            }

            $entryTypeRaw = trim((string)($row['entry_type'] ?? ''));
            $entryType = null;
            $defaultSide = null;
            if ($entryTypeRaw === '') {
                $status = 'error';
                $messages[] = 'Entry type is required (registration, lessons, recital fee, payment, scholarship or other).';
            } else {
                $known = self::ENTRY_TYPES[self::norm($entryTypeRaw)] ?? null;
                if ($known === null) {
                    $status = 'error';
                    $messages[] = 'Unknown entry type "' . $entryTypeRaw
                        . '" — use registration, lessons, recital fee, installment plan fee, payment, scholarship or other.';
                } else {
                    [$entryType, $defaultSide] = $known;
                }
            }

            $amountCents = Billing::parseAmountCents((string)($row['amount'] ?? ''));
            if ($amountCents === null) {
                $status = 'error';
                $messages[] = 'Amount is required, as dollars (e.g. 425.00 or $425).';
            } elseif ($amountCents <= 0) {
                $status = 'error';
                $messages[] = 'Amount must be positive — use the Debit or Credit column to say which way it goes.';
            }

            // Blank date means the semester's start: these are opening
            // balances, and dating them "today" would misfile the history.
            $dateRaw = trim((string)($row['date'] ?? ''));
            $date = $dateRaw === ''
                ? (string)$semester['start_date']
                : LocationDatesCsvImport::parseDate($dateRaw);
            if ($date === null) {
                $status = 'error';
                $messages[] = 'Date is not a valid date (e.g. 8/15/2026) — leave it blank for the semester start.';
            }

            $sideRaw = trim((string)($row['accounting_type'] ?? ''));
            $accountingType = $defaultSide;
            if ($sideRaw !== '') {
                $side = self::normSide($sideRaw);
                if ($side === null) {
                    $status = 'error';
                    $messages[] = 'Debit or Credit must be "debit" (a charge) or "credit" (a payment).';
                } else {
                    $accountingType = $side;
                }
            } elseif ($entryType !== null && $defaultSide === null) {
                $status = 'error';
                $messages[] = 'An "other" entry must say whether it is a debit or a credit.';
            }

            $description = trim((string)($row['description'] ?? ''));
            if ($description === '' && $entryType !== null) {
                $description = self::DEFAULT_DESCRIPTIONS[$entryType] ?? 'Adjustment';
            }

            if ($status !== 'error' && $student && $entryType !== null && $accountingType !== null && $date !== null) {
                $fields = [
                    'student_user_id' => (int)$student['id'],
                    'entry_date' => $date,
                    'accounting_type' => $accountingType,
                    'entry_type' => $entryType,
                    'amount_cents' => $amountCents,
                    'semester_id' => $semesterId,
                    'description' => $description,
                ];
                $key = implode(':', [
                    $fields['student_user_id'], $date, $accountingType, $entryType, $amountCents,
                ]);
                if (isset($seen[$key])) {
                    $status = 'error';
                    $messages[] = 'Identical to row ' . $seen[$key] . ' — remove one, or give them different dates.';
                } elseif (Billing::entryExists($fields)) {
                    $seen[$key] = $i + 1;
                    $changes = 'Already recorded (no change)';
                } else {
                    $seen[$key] = $i + 1;
                    $changes = ($accountingType === 'debit' ? 'Charge ' : 'Credit ')
                        . $student['first_name'] . ' ' . $student['last_name'] . ' '
                        . Billing::formatCents($amountCents)
                        . ' — ' . str_replace('_', ' ', $entryType)
                        . ', ' . date('M j, Y', strtotime($date));
                    $row['_fields'] = $fields;
                }
            }

            $out[] = [
                'row' => $i + 1,
                'data' => $row,
                'status' => $status,
                'changes' => $changes,
                'messages' => $messages,
            ];
        }
        return $out;
    }

    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $added = 0;
        $skipped = 0;
        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid' || !isset($entry['data']['_fields'])) {
                $skipped++;
                continue;
            }
            Billing::importEntry($ctx, $entry['data']['_fields']);
            $added++;
        }
        return ['created' => $added, 'updated' => 0, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** "debit"/"charge" -> debit; "credit"/"payment" -> credit; else null. */
    private static function normSide(string $raw): ?string {
        return match (self::norm($raw)) {
            'debit', 'charge', 'd' => 'debit',
            'credit', 'payment', 'c' => 'credit',
            default => null,
        };
    }

    /** Students whose "first last" / "preferred last" / email matches. */
    private static function matchStudents(string $identifier): array {
        $norm = self::norm($identifier);
        if (str_contains($norm, '@')) {
            $st = pdo()->prepare(
                "SELECT u.id, u.first_name, u.last_name
                 FROM student_profiles sp
                 JOIN users u ON u.id = sp.user_id
                 WHERE u.is_deleted = 0
                   AND (LOWER(u.email) = ? OR LOWER(COALESCE(u.secondary_email, '')) = ?)"
            );
            $st->execute([$norm, $norm]);
            return $st->fetchAll();
        }
        $st = pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name
             FROM student_profiles sp
             JOIN users u ON u.id = sp.user_id
             WHERE u.is_deleted = 0
               AND (LOWER(CONCAT(u.first_name, ' ', u.last_name)) = ?
                    OR LOWER(CONCAT(COALESCE(u.preferred_name, ''), ' ', u.last_name)) = ?)"
        );
        $st->execute([$norm, $norm]);
        return $st->fetchAll();
    }

    private static function norm(string $value): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }
}
