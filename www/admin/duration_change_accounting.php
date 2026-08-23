<?php
// GET: the review screen for changing a CONFIRMED reservation's duration —
// the admin sees (and can adjust) the refund and new-charge ledger entries
// before anything posts. Reached from the schedule grid's edit modal when a
// duration change needs accounting.
//
// Query parameters: reservation_id, new_duration_minutes. The calculation is
// recomputed server-side here (never trusted from the URL), and the form uses
// the session CSRF token like every other form.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

$reservationId = (int)($_GET['reservation_id'] ?? 0);
$newDurationMinutes = (int)($_GET['new_duration_minutes'] ?? 0);

$reservation = $reservationId > 0 ? ReservationManagement::findReservation($reservationId) : null;
if (!$reservation || $newDurationMinutes <= 0) {
    header('Location: /admin/schedule.php');
    exit;
}
$semesterId = (int)$reservation['semester_id'];
$originalDuration = (int)$reservation['duration_minutes'];
if ($reservation['status'] !== 'confirmed' || $newDurationMinutes === $originalDuration) {
    header('Location: /admin/schedule.php?semester=' . $semesterId);
    exit;
}

$semester = SemesterManagement::find($semesterId);
$student = UserManagement::findById((int)$reservation['student_user_id']);
$studentName = $student
    ? trim((string)$student['first_name'] . ' ' . (string)$student['last_name'])
    : 'Unknown student';

$calculation = Billing::durationChangeLedgerCalculation(
    $reservationId, $semesterId, $originalDuration, $newDurationMinutes
);
$perLessonOld = $calculation['original_fee_cents'] / max(1, (int)$calculation['lessons_per_semester']);
$perLessonNew = $calculation['new_fee_cents'] / max(1, (int)$calculation['lessons_per_semester']);

header_html('Duration Change');
?>

<div class="page-head">
  <h2>Duration Change — Review Charges</h2>
</div>

<div class="card">
  <p><strong><?=h($studentName)?></strong>
    <?php if ($semester): ?>— <?=h(SemesterManagement::label($semester))?><?php endif; ?><br>
    <strong>Duration change:</strong> <?=$originalDuration?> min &rarr; <?=$newDurationMinutes?> min</p>

  <h3>Lesson Progress</h3>
  <p class="small">
    Total lessons: <strong><?=(int)$calculation['lessons_total']?></strong> ·
    Used/attended: <strong><?=(int)$calculation['lessons_used']?></strong> ·
    Remaining: <strong><?=(int)$calculation['lessons_remaining']?></strong>
  </p>

  <form method="post" action="/admin/duration_change_accounting_eval.php" class="stack" id="durationAccountingForm">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="reservation_id" value="<?=$reservationId?>">
    <input type="hidden" name="new_duration_minutes" value="<?=$newDurationMinutes?>">

    <h3>Refund (credit)</h3>
    <p class="small">
      Original semester fee (<?=$originalDuration?> min): <?=h(Billing::formatCents((int)$calculation['original_fee_cents']))?><br>
      Per-lesson cost: <?=h(Billing::formatCents((int)round($perLessonOld)))?><br>
      Amount spent (<?=(int)$calculation['lessons_used']?> lessons used): <?=h(Billing::formatCents((int)$calculation['amount_spent_cents']))?><br>
      <strong>Refund = original fee &minus; amount spent</strong>
    </p>
    <label>Refund to student ($)
      <input type="text" name="refund_amount" value="<?=h(number_format($calculation['refund_cents'] / 100, 2, '.', ''))?>" style="max-width:160px;">
    </label>

    <h3>New charge (debit)</h3>
    <p class="small">
      New semester fee (<?=$newDurationMinutes?> min): <?=h(Billing::formatCents((int)$calculation['new_fee_cents']))?><br>
      Per-lesson cost: <?=h(Billing::formatCents((int)round($perLessonNew)))?><br>
      <strong>New charge = per-lesson cost &times; <?=(int)$calculation['lessons_remaining']?> remaining lessons</strong>
    </p>
    <label>New charge to student ($)
      <input type="text" name="new_charge_amount" value="<?=h(number_format($calculation['new_charge_cents'] / 100, 2, '.', ''))?>" style="max-width:160px;">
    </label>

    <p class="small">Both amounts are editable — what you post is what appears on the
    student's ledger. Nothing is posted until you confirm below.</p>
    <div class="error small hidden" id="durationAccountingErr"></div>
    <div class="actions">
      <a class="button" href="/admin/schedule.php?semester=<?=$semesterId?>">Cancel</a>
      <button type="submit" class="button primary">Post Entries &amp; Update Duration</button>
    </div>
  </form>
</div>

<script>
document.getElementById('durationAccountingForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var errEl = document.getElementById('durationAccountingErr');
  errEl.classList.add('hidden');
  fetch('/admin/duration_change_accounting_eval.php', {
    method: 'POST', body: new FormData(this), credentials: 'same-origin'
  })
    .then(function (r) { return r.json(); })
    .then(function (json) {
      if (json && json.ok) {
        window.location = '/admin/schedule.php?semester=<?=$semesterId?>';
      } else {
        errEl.textContent = (json && json.error) || 'Something went wrong.';
        errEl.classList.remove('hidden');
      }
    })
    .catch(function () {
      errEl.textContent = 'Network error.';
      errEl.classList.remove('hidden');
    });
});
</script>

<?php footer_html(); ?>
