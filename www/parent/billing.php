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
$familyHasCharges = false; // nothing owed AND never charged is "no balance due", not "paid in full"

$behindReasons = []; // "Term (child) — which deadline was missed", one per past-due term
foreach ($children as $child) {
    $summary = Billing::balanceSummaryForStudent((int)$child['id']);
    $summaries[(int)$child['id']] = $summary;
    $totalDue += $summary['due_cents'];
    $familyHasCharges = $familyHasCharges || $summary['has_charges'];
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

// A heads-up only: credit held for the family, not yet used by any child.
$parentCreditCents = Billing::parentCreditBalanceCents((int)$me['id']);

header_html('Billing');
?>

<h2>Balance &amp; Payments</h2>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?php if ($parentCreditCents > 0): ?>
<?php
  // The credit is the family's, not yet set against any child. It is drawn on
  // automatically when a child is next charged; here the parent can also put
  // it toward a balance that is already owed.
  $creditTargets = [];
  foreach ($children as $child) {
      $childDue = $summaries[(int)$child['id']]['due_cents'];
      if ($childDue > 0) {
          $creditTargets[] = ['child' => $child, 'due' => $childDue, 'apply' => min($childDue, $parentCreditCents)];
      }
  }
?>
<div class="card" id="family-credit">
  <h3>You have a family credit of <?=h(Billing::formatCents($parentCreditCents))?></h3>
  <?php if (!$creditTargets): ?>
    <p class="small" style="margin-bottom:0;">Nothing is owed right now. The credit stays on your account and is used
      automatically toward your children&rsquo;s next charges.</p>
  <?php else: ?>
    <p class="small">This credit is <strong>not yet included</strong> in the balance<?=count($creditTargets) > 1 ? 's' : ''?> below.
      It is used automatically toward your children&rsquo;s next charges &mdash; or you can apply it to a balance now:</p>
    <?php foreach ($creditTargets as $target): ?>
      <form method="post" action="/parent/apply_credit_eval.php" class="lesson-row"
            onsubmit="return confirm(<?=h(json_encode('Apply ' . Billing::formatCents($target['apply']) . ' of your credit to '
                . $target['child']['first_name'] . "'s balance?"))?>);">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="student_user_id" value="<?=(int)$target['child']['id']?>">
        <span><strong><?=h($target['child']['first_name'] . ' ' . $target['child']['last_name'])?></strong>
          <span class="small">&middot; <?=h(Billing::formatCents($target['due']))?> balance<?=$target['apply'] < $target['due']
              ? ' &middot; ' . h(Billing::formatCents($target['due'] - $target['apply'])) . ' would remain' : ''?></span></span>
        <button type="submit" class="button primary" style="margin-left:auto;">Apply <?=h(Billing::formatCents($target['apply']))?> of my credit</button>
      </form>
    <?php endforeach; ?>
    <p class="small" style="margin-bottom:0;">With the credit applied you would owe
      <strong><?=h(Billing::formatCents(max(0, $totalDue - $parentCreditCents)))?></strong> in total.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
  <?php if ($totalDue <= 0): ?>
    <h3><?=$familyHasCharges ? 'You are paid in full. Thank you!' : 'No balance due.'?></h3>
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
          <?php $feeNotice = $currentSemester ? SemesterManagement::installmentFeeNotice($currentSemester) : null; ?>
          <p style="margin-bottom:0;"><strong>Remember:</strong> At least half of the balance must be paid two weeks before the first lesson to reserve your place.
            <?=$feeNotice !== null ? h($feeNotice) : 'Accounts not paid in full before the first class will incur an installment fee.'?></p>
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
      <?php if ($summary['due_cents'] > 0): ?>· <?=h(Billing::formatCents($summary['due_cents']))?> balance<?php
        if ($parentCreditCents > 0): ?> · <a href="#family-credit">you have a family credit of
        <?=h(Billing::formatCents($parentCreditCents))?></a><?php endif; ?>
      <?php elseif ($summary['balance_cents'] < 0): ?>· <?=h(Billing::formatCents(-$summary['balance_cents']))?> credit
      <?php else: ?>· <?=$summary['has_charges'] ? 'paid in full' : 'no balance due'?><?php endif; ?>
    </span>
  </h3>

  <?php if ($summary['semesters']): ?>
    <table class="list">
      <thead><tr><th>Term</th><th style="text-align:right;" title="Charges, less any that were reversed or covered by a scholarship or adjustment">Charges</th>
        <th style="text-align:right;" title="Payments received">Paid</th><th style="text-align:right;">Balance</th><th></th></tr></thead>
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
            <?php else: ?><?=h((string)$term['next_due'])?><?php endif; ?>
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
