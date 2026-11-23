<?php
// GET JSON: what confirming a reservation would charge (action=confirm), or
// what un-confirming/deleting one would reverse (action=unconfirm) — the data
// behind the schedule grid's charge-confirmation dialog. Read-only.
//
// action=confirm needs either reservation_id (optionally duration_minutes to
// preview at a newly selected length) or, for a not-yet-created reservation,
// semester_id + student_user_id + duration_minutes (+ location_id,
// day_of_week and first_lesson_date when the slot starts late, so the lesson
// fee is previewed for the lessons that remain).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');

/** entry_type -> the label shown in the dialog. */
function reservation_charge_type_label(string $entryType): string {
    return [
        'registration' => 'Semester registration',
        'lessons' => 'Semester lessons',
        'recital_fee' => 'Recital fee',
        'installment_plan_fee' => 'Installment plan fee',
    ][$entryType] ?? ucfirst(str_replace('_', ' ', $entryType));
}

try {
    $action = (string)($_GET['action'] ?? '');
    $reservationId = (int)($_GET['reservation_id'] ?? 0);

    if ($action === 'confirm') {
        if ($reservationId > 0) {
            $reservation = ReservationManagement::findReservation($reservationId);
            if (!$reservation) {
                throw new InvalidArgumentException('That reservation is no longer here.');
            }
            $studentUserId = (int)$reservation['student_user_id'];
            $semesterId = (int)$reservation['semester_id'];
            $durationMinutes = (int)($_GET['duration_minutes'] ?? 0) ?: (int)$reservation['duration_minutes'];
            $locationId = (int)$reservation['location_id'];
            $dayOfWeek = (int)$reservation['day_of_week'];
            // The edit dialog saves the date before confirming, so preview
            // with what is in the form rather than what is stored.
            $firstLessonDate = isset($_GET['first_lesson_date'])
                ? trim((string)$_GET['first_lesson_date'])
                : (string)($reservation['first_lesson_date'] ?? '');
        } else {
            $studentUserId = (int)($_GET['student_user_id'] ?? 0);
            $semesterId = (int)($_GET['semester_id'] ?? 0);
            $durationMinutes = (int)($_GET['duration_minutes'] ?? 30);
            $locationId = (int)($_GET['location_id'] ?? 0);
            $dayOfWeek = (int)($_GET['day_of_week'] ?? -1);
            $firstLessonDate = trim((string)($_GET['first_lesson_date'] ?? ''));
        }
        if ($studentUserId <= 0 || $semesterId <= 0) {
            throw new InvalidArgumentException('A student and semester are required.');
        }
        $lessonCount = ReservationManagement::lateStartLessonCount(
            $semesterId, $locationId, $dayOfWeek, $firstLessonDate !== '' ? $firstLessonDate : null
        );
        $preview = Billing::confirmationChargesPreview($studentUserId, $semesterId, $durationMinutes, $lessonCount, $locationId ?: null);
        $lines = [];
        foreach ($preview['lines'] as $line) {
            $lines[] = [
                'label' => (string)$line['description'],
                'amount' => Billing::formatCents((int)$line['amount_cents']),
                'will_post' => (bool)$line['will_post'],
                'skip_reason' => $line['skip_reason'],
            ];
        }
        $payload = [
            'ok' => true,
            'mode' => 'confirm',
            'lines' => $lines,
            'total' => Billing::formatCents((int)$preview['total_cents']),
            'installment_available' => (bool)$preview['installment_available'],
            'installment_fee' => Billing::formatCents((int)$preview['installment_fee_cents']),
            'installment_note' => $preview['installment_note'],
        ];
        // A student who already has a credit (a registration payment moved
        // onto the ledger, an overpayment) — shown under the total so the
        // admin can see the charge being met by money already received.
        $balanceCents = Billing::balanceForStudentCents($studentUserId);
        if ($balanceCents < 0) {
            $afterCents = $balanceCents + (int)$preview['total_cents'];
            $payload['credit'] = Billing::formatCents(-$balanceCents);
            $payload['balance_after_label'] = $afterCents > 0 ? 'Balance due after these charges'
                : ($afterCents < 0 ? 'Credit left after these charges' : 'Balance after these charges');
            $payload['balance_after'] = Billing::formatCents(abs($afterCents));
        }
    } elseif ($action === 'unconfirm') {
        $reservation = ReservationManagement::findReservation($reservationId);
        if (!$reservation) {
            throw new InvalidArgumentException('That reservation is no longer here.');
        }
        $studentUserId = (int)$reservation['student_user_id'];
        $semesterId = (int)$reservation['semester_id'];
        $preview = Billing::reversalPreview($studentUserId, $semesterId, $reservationId);
        $lines = [];
        foreach ($preview['lines'] as $line) {
            $lines[] = [
                'label' => 'Reversal: ' . reservation_charge_type_label((string)$line['entry_type']),
                'amount' => Billing::formatCents((int)$line['amount_cents']),
            ];
        }
        $payload = [
            'ok' => true,
            'mode' => 'unconfirm',
            'will_reverse' => (bool)$preview['will_reverse'],
            'blocked_reason' => $preview['blocked_reason'],
            'lines' => $lines,
            'total' => Billing::formatCents((int)$preview['total_cents']),
        ];
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }

    $student = UserManagement::findById($studentUserId);
    $semester = SemesterManagement::find($semesterId);
    $payload['student_name'] = $student
        ? trim((string)$student['first_name'] . ' ' . (string)$student['last_name']) : '';
    $payload['semester_label'] = $semester ? SemesterManagement::label($semester) : '';
    echo json_encode($payload);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
