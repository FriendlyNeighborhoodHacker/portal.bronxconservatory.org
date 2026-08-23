<?php
// Parent Billing: what the family owes, term by term and line by line, and
// the form that starts a payment. The amount is editable because families are
// asked for half the term up front and the rest by its half-way lesson — a
// parent paying the half they were asked for should not have to call.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
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
$currentSemester = SemesterManagement::resolveDefaultSemester();
$hasPaidCurrentSemester = false;

$behindReasons = []; // "Term (child) — which deadline was missed", one per past-due term
foreach ($children as $child) {
    $summary = Billing::balanceSummaryForStudent((int)$child['id']);
    $summaries[(int)$child['id']] = $summary;
    $totalDue += $summary['due_cents'];
    $behind = $behind || $summary['behind'];
    foreach ($summary['behind_reasons'] as $behindReason) {
        $behindReasons[] = [
            'child' => (string)$child['first_name'],
            'label' => $behindReason['label'],
            'reason' => $behindReason['reason'],
        ];
    }

    // Check if they've paid anything toward the current/upcoming semester
    if ($currentSemester && isset($summary['semesters'])) {
        foreach ($summary['semesters'] as $term) {
            if ($term['semester_id'] === $currentSemester['id'] && $term['paid_cents'] > 0) {
                $hasPaidCurrentSemester = true;
            }
        }
    }
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
      <p class="balance-behind" style="margin-bottom:4px;">Some of this is past due:</p>
      <ul class="small" style="margin-top:0;">
        <?php foreach ($behindReasons as $behindReason): ?>
          <li><strong><?=h($behindReason['label'])?><?php if (count($children) > 1): ?> (<?=h($behindReason['child'])?>)<?php endif; ?></strong>
            — <?=h($behindReason['reason'])?>.</li>
        <?php endforeach; ?>
      </ul>
      <p class="small">Please call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>
        to discuss your account.</p>
    <?php endif; ?>

    <?php if (StripeCheckout::isConfigured()): ?>
      <form id="payment-amount-form" method="get" action="/parent/pay.php" class="stack" style="max-width:320px;">
        <fieldset>
          <legend>Amount to pay</legend>
          <?php
            $fullAmount = $totalDue / 100;
            $halfAmount = round($totalDue / 200, 2);
          ?>
          <?php if (!$hasPaidCurrentSemester): ?>
            <label>
              <input type="radio" name="payment_option" value="full" checked>
              Pay balance in full: <?=h(Billing::formatCents($totalDue))?>
            </label>
            <label>
              <input type="radio" name="payment_option" value="half">
              Pay half: <?=h(Billing::formatCents((int)($totalDue / 2)))?>
            </label>
            <label>
              <input type="radio" name="payment_option" value="custom">
              Pay another amount:
              <input type="number" id="custom_amount" name="custom_amount" step="0.01" min="1"
                     max="<?=h(number_format($fullAmount, 2, '.', ''))?>"
                     style="width:100px;margin-left:8px;">
            </label>
          <?php else: ?>
            <label>
              <input type="radio" name="payment_option" value="full" checked>
              Pay balance in full: <?=h(Billing::formatCents($totalDue))?>
            </label>
            <label>
              <input type="radio" name="payment_option" value="custom">
              Pay another amount:
              <input type="number" id="custom_amount" name="custom_amount" step="0.01" min="1"
                     max="<?=h(number_format($fullAmount, 2, '.', ''))?>"
                     style="width:100px;margin-left:8px;">
            </label>
          <?php endif; ?>
          <input type="hidden" id="amount" name="amount" value="<?=h(number_format($fullAmount, 2, '.', ''))?>">
        </fieldset>
        <div class="actions" style="margin-top:16px;">
          <button type="submit" class="btn-cta">Pay by card</button>
        </div>
      </form>
      <script>
        (function() {
          const form = document.getElementById('payment-amount-form');
          const amountInput = document.getElementById('amount');
          const customAmountInput = document.getElementById('custom_amount');
          const fullAmount = <?=json_encode($fullAmount)?>;
          const halfAmount = <?=json_encode($halfAmount)?>;

          function updateAmount() {
            const checked = form.querySelector('input[name="payment_option"]:checked');
            if (checked.value === 'full') {
              amountInput.value = fullAmount.toString();
            } else if (checked.value === 'half') {
              amountInput.value = halfAmount.toString();
            } else if (checked.value === 'custom') {
              amountInput.value = customAmountInput.value || '';
            }
          }

          form.querySelectorAll('input[name="payment_option"]').forEach(radio => {
            radio.addEventListener('change', updateAmount);
          });

          if (customAmountInput) {
            customAmountInput.addEventListener('input', function() {
              if (form.querySelector('input[name="payment_option"][value="custom"]').checked) {
                updateAmount();
              }
            });
          }

          // Set initial value
          updateAmount();
        })();
      </script>
      <div class="small" style="margin-top:12px;">
        <p style="margin-top:0;">You'll enter your card on the next page — it goes straight to Stripe and the conservatory never sees it. Paying by check or cash instead? Call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
        <?php if (!$hasPaidCurrentSemester): ?>
          <p style="margin-bottom:0;"><strong>Remember:</strong> At least half of the balance must be paid before two weeks before the first lesson to reserve your place. Accounts not paid in full before the first class will incur a $20 installment fee.</p>
        <?php endif; ?>
      </div>
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
            <?php elseif ($term['behind']): ?><span class="balance-behind" title="<?=h(ucfirst((string)$term['behind_reason']))?>.">Past due</span>
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
    <h4 style="margin-bottom:4px;">Every charge and payment</h4>
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
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php footer_html(); ?>
