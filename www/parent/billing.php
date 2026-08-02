<?php
// Parent Billing: what the family owes, term by term and line by line, and
// the form that starts a payment. The amount is editable because families are
// asked for half the term up front and the rest by its half-way lesson — a
// parent paying the half they were asked for should not have to call.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);

$flash = $_SESSION['billing_flash'] ?? null;
$flashError = $_SESSION['billing_flash_error'] ?? null;
unset($_SESSION['billing_flash'], $_SESSION['billing_flash_error']);

// One balance summary per child: the family total, whether anything is past
// due, and the per-child cards below all read from these.
$summaries = [];
$totalDue = 0;
$behind = false;
foreach ($children as $child) {
    $summary = Billing::balanceSummaryForStudent((int)$child['id']);
    $summaries[(int)$child['id']] = $summary;
    $totalDue += $summary['due_cents'];
    $behind = $behind || $summary['behind'];
}

header_html('Billing');
?>

<h2>Balance &amp; Payments</h2>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <?php if ($totalDue <= 0): ?>
    <h3>You are paid in full. Thank you!</h3>
  <?php else: ?>
    <h3>You have a balance of <?=h(Billing::formatCents($totalDue))?>.</h3>
    <?php if ($behind): ?>
      <p class="balance-behind">Some of this is past due. If that is a problem right now,
        call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> — we will
        work it out together.</p>
    <?php endif; ?>

    <?php if (StripeCheckout::isConfigured()): ?>
      <form method="get" action="/parent/pay.php" class="stack" style="max-width:320px;">
        <label>Amount to pay
          <input type="number" name="amount" step="0.01" min="1" required
                 max="<?=h(number_format($totalDue / 100, 2, '.', ''))?>"
                 value="<?=h(number_format($totalDue / 100, 2, '.', ''))?>">
        </label>
        <div class="actions">
          <button type="submit" class="btn-cta">Pay by card</button>
        </div>
      </form>
      <p class="small" style="margin-top:8px;">You'll enter your card on the next page —
        it goes straight to Stripe and the conservatory never sees it. Paying by check or
        cash instead? Call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
    <?php else: ?>
      <p class="small">To pay, call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($children as $child): ?>
<?php
  $childId = (int)$child['id'];
  $summary = $summaries[$childId];
  $entries = Billing::ledgerForStudent($childId);
?>
<div class="card" id="child-<?=$childId?>">
  <h3><?=h($child['first_name'] . ' ' . $child['last_name'])?>
    <span class="small">
      <?php if ($summary['due_cents'] > 0): ?>· <?=h(Billing::formatCents($summary['due_cents']))?> due
      <?php elseif ($summary['balance_cents'] < 0): ?>· <?=h(Billing::formatCents(-$summary['balance_cents']))?> credit
      <?php else: ?>· paid in full<?php endif; ?>
    </span>
  </h3>

  <?php if ($summary['semesters']): ?>
    <table class="list">
      <thead><tr><th>Term</th><th style="text-align:right;">Charges</th>
        <th style="text-align:right;">Paid</th><th style="text-align:right;">Balance</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($summary['semesters'] as $term): ?>
        <tr>
          <td><?=h($term['label'])?></td>
          <td style="text-align:right;"><?=h(Billing::formatCents($term['charged_cents']))?></td>
          <td style="text-align:right;"><?=h(Billing::formatCents($term['paid_cents']))?></td>
          <td style="text-align:right;"><?=h(Billing::formatCents($term['balance_cents']))?></td>
          <td class="small">
            <?php if ($term['balance_cents'] <= 0): ?>Paid
            <?php elseif ($term['behind']): ?><span class="balance-behind">Past due</span>
            <?php else: ?>Not due yet<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!$entries): ?>
    <p class="small">No charges or payments yet.</p>
  <?php else: ?>
    <details>
      <summary>Every charge and payment</summary>
      <table class="list">
        <thead><tr><th>Date</th><th>Description</th>
          <th style="text-align:right;">Charge</th><th style="text-align:right;">Payment / Credit</th></tr></thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
          <tr>
            <td class="small"><?=h($entry['entry_date'])?></td>
            <td><?=h((string)($entry['description'] ?: str_replace('_', ' ', (string)$entry['entry_type'])))?>
              <?php if (!empty($entry['season'])): ?>
                <span class="small">(<?=h(ucfirst((string)$entry['season']) . ' ' . $entry['year'])?>)</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;"><?=$entry['accounting_type'] === 'debit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
            <td style="text-align:right;"><?=$entry['accounting_type'] === 'credit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php footer_html(); ?>
