<?php
// GET: display the duration-change accounting screen as a full page overlay.
//
// Query parameters:
//   reservation_id: the reservation being modified
//   new_duration_minutes: the proposed new duration
//   calculation: JSON-encoded calculation result from Billing::durationChangeLedgerCalculation()
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

$reservationId = (int)($_GET['reservation_id'] ?? 0);
$newDurationMinutes = (int)($_GET['new_duration_minutes'] ?? 0);
$calculationJson = (string)($_GET['calculation'] ?? '');

if (!$reservationId || !$newDurationMinutes || !$calculationJson) {
    echo "Missing parameters.";
    exit;
}

$reservation = ReservationManagement::findReservation($reservationId);
if (!$reservation) {
    echo "Reservation not found.";
    exit;
}

$calculation = json_decode($calculationJson, true);
if (!is_array($calculation)) {
    echo "Invalid calculation data.";
    exit;
}

$semester = SemesterManagement::find((int)$reservation['semester_id']);
$st = pdo()->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
$st->execute([(int)$reservation['student_user_id']]);
$student = $st->fetch();
if (!$student) {
    $student = ['first_name' => 'Unknown', 'last_name' => 'Student'];
}

$originalDuration = (int)$reservation['duration_minutes'];
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Duration Change Accounting</title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .accounting-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .accounting-card {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            max-width: 600px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .accounting-card h1 {
            margin-top: 0;
            font-size: 1.5rem;
        }
        .card-subtitle {
            color: #666;
            margin: 0.5rem 0 2rem 0;
            font-size: 0.95rem;
        }
        .accounting-section {
            margin: 2rem 0;
            padding: 1rem;
            background: #f5f5f5;
            border-radius: 4px;
        }
        .accounting-section h3 {
            margin-top: 0;
            font-size: 0.95rem;
            color: #333;
        }
        .lesson-counts {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            font-size: 0.9rem;
        }
        .lesson-count-item {
            text-align: center;
            padding: 0.75rem;
            background: white;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .lesson-count-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
        }
        .lesson-count-label {
            color: #666;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        .amount-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin: 1rem 0;
        }
        .amount-box {
            display: flex;
            flex-direction: column;
        }
        .amount-box label {
            font-weight: bold;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .amount-box input {
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            font-family: monospace;
        }
        .amount-display {
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f9f9f9;
            font-family: monospace;
            text-align: right;
        }
        .refund-row {
            background: #e8f5e9;
            border-left: 3px solid #4caf50;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .charge-row {
            background: #ffebee;
            border-left: 3px solid #f44336;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .row-amount {
            text-align: right;
            font-weight: bold;
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }
        .row-label {
            font-size: 0.9rem;
            color: #555;
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 2rem;
        }
        .actions button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-cancel {
            background: #ccc;
            color: #333;
        }
        .btn-cancel:hover {
            background: #999;
        }
        .btn-confirm {
            background: #4caf50;
            color: white;
        }
        .btn-confirm:hover {
            background: #45a049;
        }
        .summary-text {
            background: #f0f0f0;
            padding: 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
<div class="accounting-overlay">
    <div class="accounting-card">
        <h1>Duration Change Accounting</h1>
        <p class="card-subtitle">
            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
            — <?php echo htmlspecialchars($semester['season'] . ' ' . $semester['year']); ?>
        </p>

        <div class="summary-text">
            <strong>Duration change:</strong> <?php echo $originalDuration; ?> min → <?php echo $newDurationMinutes; ?> min
        </div>

        <div class="accounting-section">
            <h3>Lesson Progress</h3>
            <div class="lesson-counts">
                <div class="lesson-count-item">
                    <div class="lesson-count-value"><?php echo (int)$calculation['lessons_total']; ?></div>
                    <div class="lesson-count-label">Total lessons</div>
                </div>
                <div class="lesson-count-item">
                    <div class="lesson-count-value"><?php echo (int)$calculation['lessons_used']; ?></div>
                    <div class="lesson-count-label">Used/attended</div>
                </div>
                <div class="lesson-count-item">
                    <div class="lesson-count-value"><?php echo (int)$calculation['lessons_remaining']; ?></div>
                    <div class="lesson-count-label">Remaining</div>
                </div>
            </div>
        </div>

        <div class="accounting-section">
            <h3>Refund Calculation</h3>
            <p style="font-size: 0.85rem; color: #666; margin: 0 0 1rem 0;">
                Original semester fee (<?php echo $originalDuration; ?> min): $<?php echo number_format($calculation['original_fee_cents'] / 100, 2); ?><br>
                Per-lesson cost: $<?php echo number_format(($calculation['original_fee_cents'] / $calculation['lessons_per_semester']), 2); ?><br>
                Amount spent (<?php echo (int)$calculation['lessons_used']; ?> lessons × per-lesson cost): $<?php echo number_format($calculation['amount_spent_cents'] / 100, 2); ?><br>
                <strong>Refund = $<?php echo number_format($calculation['original_fee_cents'] / 100, 2); ?> − $<?php echo number_format($calculation['amount_spent_cents'] / 100, 2); ?></strong>
            </p>
            <div class="refund-row">
                <div class="row-label">Refund to student</div>
                <input type="text" id="refund_cents" value="<?php echo htmlspecialchars((string)$calculation['refund_cents']); ?>" data-initial="<?php echo htmlspecialchars((string)$calculation['refund_cents']); ?>">
                <div class="row-amount" id="refund_display">$<?php echo number_format($calculation['refund_cents'] / 100, 2); ?></div>
            </div>
        </div>

        <div class="accounting-section">
            <h3>New Charge Calculation</h3>
            <p style="font-size: 0.85rem; color: #666; margin: 0 0 1rem 0;">
                New semester fee (<?php echo $newDurationMinutes; ?> min): $<?php echo number_format($calculation['new_fee_cents'] / 100, 2); ?><br>
                Per-lesson cost: $<?php echo number_format(($calculation['new_fee_cents'] / $calculation['lessons_per_semester']), 2); ?><br>
                <strong>New charge = (<?php echo number_format(($calculation['new_fee_cents'] / $calculation['lessons_per_semester']), 2); ?> × <?php echo (int)$calculation['lessons_remaining']; ?> remaining lessons)</strong>
            </p>
            <div class="charge-row">
                <div class="row-label">New charge to student</div>
                <input type="text" id="new_charge_cents" value="<?php echo htmlspecialchars((string)$calculation['new_charge_cents']); ?>" data-initial="<?php echo htmlspecialchars((string)$calculation['new_charge_cents']); ?>">
                <div class="row-amount" id="new_charge_display">$<?php echo number_format($calculation['new_charge_cents'] / 100, 2); ?></div>
            </div>
        </div>

        <div class="actions">
            <button class="btn-cancel" onclick="window.history.back();">Cancel</button>
            <button class="btn-confirm" onclick="submitAccounting();">Post Entries & Update Duration</button>
        </div>
    </div>
</div>

<script>
const csrf = '<?php echo htmlspecialchars($_GET['csrf'] ?? ''); ?>';

document.getElementById('refund_cents').addEventListener('change', function() {
    const cents = parseInt(this.value) || 0;
    document.getElementById('refund_display').textContent = '$' + (cents / 100).toFixed(2);
});

document.getElementById('new_charge_cents').addEventListener('change', function() {
    const cents = parseInt(this.value) || 0;
    document.getElementById('new_charge_display').textContent = '$' + (cents / 100).toFixed(2);
});

function submitAccounting() {
    const refundCents = parseInt(document.getElementById('refund_cents').value) || 0;
    const newChargeCents = parseInt(document.getElementById('new_charge_cents').value) || 0;
    const newDuration = <?php echo $newDurationMinutes; ?>;
    const reservationId = <?php echo $reservationId; ?>;

    const formData = new FormData();
    formData.append('reservation_id', reservationId);
    formData.append('new_duration_minutes', newDuration);
    formData.append('refund_cents', refundCents);
    formData.append('new_charge_cents', newChargeCents);
    formData.append('csrf', csrf);

    fetch('/admin/duration_change_accounting_eval.php', {
        method: 'POST',
        body: formData,
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            alert('Duration change posted. Ledger entries recorded.');
            window.location = '/admin/schedule.php?semester=<?php echo (int)$reservation['semester_id']; ?>';
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => alert('Request failed: ' + err));
}
</script>
</body>
</html>
