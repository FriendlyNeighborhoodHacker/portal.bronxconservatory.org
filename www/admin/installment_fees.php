<?php
// Admin: preview (dry-run) today's installment-fee sweep and run it by hand.
// The daily cron (www/bin/apply_installment_fees.php) does the same thing on
// its own; this page exists so an admin can see exactly who would be charged
// before anything posts — the same line-item-confirmation policy as the rest
// of the billing UI.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

$flash = $_SESSION['installment_fees_flash'] ?? null;
$flashError = $_SESSION['installment_fees_flash_error'] ?? null;
unset($_SESSION['installment_fees_flash'], $_SESSION['installment_fees_flash_error']);

$preview = Billing::applyAutomaticInstallmentFees(null, null, true);

header_html('Installment Fee Sweep');
?>

<div class="page-head">
  <h2>Installment Fee Sweep</h2>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <p class="small">From the second day of a semester on, confirmed students who still owe part
  of that semester's balance and have not already been charged the installment plan fee get it
  applied automatically by a daily job. This page shows what today's run would charge; the
  button applies exactly the charges listed. Running it twice is safe — a student is never
  charged the fee twice for the same semester.</p>

  <?php if ($preview['applied']): ?>
    <table class="list">
      <thead><tr><th>Student</th><th>Semester</th><th style="text-align:right;">Fee</th></tr></thead>
      <tbody>
        <?php foreach ($preview['applied'] as $row): ?>
        <tr>
          <td><a href="/admin/student.php?id=<?=(int)$row['student_user_id']?>"><?=h($row['student_name'])?></a></td>
          <td><?=h($row['semester_label'])?></td>
          <td style="text-align:right;"><?=h(Billing::formatCents((int)$row['amount_cents']))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="/admin/installment_fees_run_eval.php" class="stack" style="margin-top:12px;"
          onsubmit="return confirm('Post the installment fee to the <?=count($preview['applied'])?> student(s) listed?');">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <div class="actions">
        <button type="submit" class="button primary">Apply <?=count($preview['applied'])?> installment fee<?=count($preview['applied']) === 1 ? '' : 's'?> now</button>
      </div>
    </form>
  <?php else: ?>
    <p>Nobody would be charged today.
      <span class="small">(<?=(int)$preview['semesters']?> in-progress semester<?=(int)$preview['semesters'] === 1 ? '' : 's'?> with an installment fee checked;
      <?=(int)$preview['skipped']?> student<?=(int)$preview['skipped'] === 1 ? '' : 's'?> skipped as paid up or already charged.)</span></p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>The daily job</h3>
  <p class="small">The sweep runs nightly via cron — see <code>docs/cron.md</code>:</p>
  <p class="small"><code>15 2 * * * php .../www/bin/apply_installment_fees.php</code></p>
</div>

<?php footer_html(); ?>
