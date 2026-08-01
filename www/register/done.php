<?php
// Thank-you page after a registration submission. Shows paid/unpaid state
// from the lead the browser session created; offers a payment retry after a
// canceled or failed checkout.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();

$leadId = (int)($_SESSION['registration_lead_id'] ?? 0);
$lead = $leadId ? LeadManagement::findLead($leadId) : null;
if (!$lead) {
    // No lead in this browser session (link revisited later, session gone).
    header('Location: /welcome.php');
    exit;
}

$paid = (int)$lead['amount_paid_cents'] > 0;
$payFlag = (string)($_GET['pay'] ?? '');
$canRetry = !$paid && StripeCheckout::isConfigured() && (int)$lead['amount_due_now_cents'] > 0;

ApplicationUI::minimalHeaderHtml('Registration Received');
?>
<div style="max-width:640px;margin:0 auto;text-align:center;">
  <h1>Thank you for registering<?=$paid ? ' — payment received!' : '!'?> 🎵</h1>

  <?php if ($payFlag === 'canceled' && !$paid): ?>
    <p class="error" style="text-align:left;">Checkout was canceled — nothing was charged.
      Your registration is saved either way; you can try the payment again below or
      arrange it with us by phone.</p>
  <?php elseif ($payFlag === 'error' && !$paid): ?>
    <p class="error" style="text-align:left;">We couldn't start the online payment just
      now. Your registration is saved — you can try again below or pay over the phone.</p>
  <?php endif; ?>

  <p>Thank you for registering for the Bronx Conservatory of Music! If you have not
    heard from our team within <strong>5 business days</strong> of registering, please
    reach out via email or phone at
    <a href="mailto:info@bronxconservatory.org">info@bronxconservatory.org</a> or
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.
    Thank you and we can't wait to make music together!</p>

  <p class="small">A confirmation email with a review of your registration was sent to
    <strong><?=h($lead['email'])?></strong>.</p>

  <?php if ($paid): ?>
    <p class="small">Payment received: <strong><?=registration_dollars((int)$lead['amount_paid_cents'])?></strong>
      <?php if (!empty($lead['installment_plan'])): ?>(installment plan — the remaining tuition is due by week 4)<?php endif; ?></p>
  <?php elseif ($canRetry): ?>
    <form method="post" action="/register/pay_eval.php" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <button type="submit" class="btn-cta">Pay <?=registration_dollars((int)$lead['amount_due_now_cents'])?> Now</button>
    </form>
  <?php else: ?>
    <p class="small">We'll arrange payment with you when we call to confirm your schedule.</p>
  <?php endif; ?>

  <p style="margin-top:24px;"><a class="btn-outline" href="/welcome.php">Done</a></p>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
