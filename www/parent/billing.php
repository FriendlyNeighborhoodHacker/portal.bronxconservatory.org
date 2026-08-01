<?php
// Parent Billing: the family balance grouped by child with line items, and
// the gold Pay Now button (Stripe Checkout) when anything is owed.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$balances = Billing::balancesForParentChildren((int)$me['id']);
$totalDue = 0;
foreach ($balances as $cents) {
    $totalDue += max(0, $cents);
}

$flash = $_SESSION['billing_flash'] ?? null;
$flashError = $_SESSION['billing_flash_error'] ?? null;
unset($_SESSION['billing_flash'], $_SESSION['billing_flash_error']);

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
    <?php if (StripeCheckout::isConfigured()): ?>
      <form method="post" action="/parent/checkout_eval.php">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <button type="submit" class="btn-cta">Pay Now</button>
      </form>
      <p class="small" style="margin-top:8px;">You'll check out securely with Stripe.
      Prefer to pay by check or cash? Call us at
      <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
    <?php else: ?>
      <p class="small">To pay, call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($children as $child): ?>
<?php
  $childBalance = $balances[(int)$child['id']] ?? 0;
  $entries = Billing::ledgerForStudent((int)$child['id']);
?>
<div class="card">
  <h3><?=h($child['first_name'] . ' ' . $child['last_name'])?>
    <span class="small">
      <?php if ($childBalance > 0): ?>· <?=h(Billing::formatCents($childBalance))?> due
      <?php elseif ($childBalance < 0): ?>· <?=h(Billing::formatCents(-$childBalance))?> credit
      <?php else: ?>· paid in full<?php endif; ?>
    </span>
  </h3>
  <?php if (!$entries): ?>
    <p class="small">No charges or payments yet.</p>
  <?php else: ?>
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
