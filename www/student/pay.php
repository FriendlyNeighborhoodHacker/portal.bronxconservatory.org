<?php
// Pay a student's balance: the card fields are Stripe Elements, so they sit on
// this page but the card details go straight from the browser to Stripe and
// never touch this server (the same arrangement as the registration form).
//
// The amount comes from the form on Billing and is applied to the student's
// balance, with the payment metadata routed to the correct ledger via
// StripeCheckout::handlePaymentIntentSucceeded.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

if (!StripeCheckout::isConfigured()) {
    $_SESSION['billing_flash_error'] = 'Online payment is not set up yet — please call us and we will take your payment over the phone.';
    header('Location: /student/billing.php');
    exit;
}

$summary = Billing::balanceSummaryForStudent((int)$me['id']);
$totalDue = $summary['due_cents'];

if ($totalDue <= 0) {
    $_SESSION['billing_flash'] = 'You are paid in full — there is nothing to pay.';
    header('Location: /student/billing.php');
    exit;
}

// Default to the whole balance; a smaller payment is welcome, a larger one is
// capped at what is owed (prepaying a term that has not been billed yet is a
// phone call, not a form).
$requested = trim((string)($_GET['amount'] ?? ''));
$amountCents = $requested === '' ? $totalDue : (Billing::parseAmountCents($requested) ?? 0);
if ($amountCents <= 0) {
    $_SESSION['billing_flash_error'] = 'Please enter the amount you would like to pay.';
    header('Location: /student/billing.php');
    exit;
}
$amountCents = min($amountCents, $totalDue);

// One PaymentIntent per (amount, balance), reused if the page is reloaded or
// a card is declined — Stripe keeps the same intent usable until it succeeds.
// The balance is part of the key so that a payment which already landed can
// never hand its spent intent to the next one.
$paymentKey = $amountCents . ':' . $totalDue;
$clientSecret = (string)($_SESSION['student_payment_client_secret'] ?? '');
if ($clientSecret === '' || (string)($_SESSION['student_payment_key'] ?? '') !== $paymentKey) {
    try {
        $intent = StripeCheckout::createStudentPaymentIntent(
            UserContext::getLoggedInUserContext(),
            (int)$me['id'],
            $amountCents,
            (int)($summary['semesters'][0]['semester_id'] ?? 0),
            (string)($me['email'] ?? ''),
            'BCM tuition — ' . trim($me['first_name'] . ' ' . $me['last_name'])
        );
        $clientSecret = $intent['client_secret'];
        $_SESSION['student_payment_client_secret'] = $clientSecret;
        $_SESSION['student_payment_key'] = $paymentKey;
    } catch (\Throwable $e) {
        $_SESSION['billing_flash_error'] = 'We could not start the payment just now: ' . $e->getMessage()
            . ' You can try again, or call us at ' . Settings::contactPhone() . '.';
        header('Location: /student/billing.php');
        exit;
    }
}

$publishableKey = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
$amountLabel = Billing::formatCents($amountCents);

header_html('Pay your balance');
?>

<h2>Pay <?=h($amountLabel)?></h2>

<div class="card">
  <p class="small">Payment applied to your tuition balance.
    <a href="/student/billing.php">Change the amount</a></p>
</div>

<div class="card">
  <h3 style="margin-top:0;">Card details
    <span class="secure-badge" title="Card details go directly to Stripe over an encrypted connection">&#128274; Secure</span>
  </h3>
  <form id="payment-form" class="stack">
    <div id="payment-element"></div>
    <?php if (trim((string)($me['email'] ?? '')) !== ''): ?>
      <p class="small">A copy of your receipt will be emailed to <strong><?=h((string)$me['email'])?></strong>.</p>
    <?php endif; ?>

    <p id="payment-message" class="error hidden" role="alert"></p>

    <div class="actions">
      <button id="payment-submit" type="submit" class="btn-cta">Pay <?=h($amountLabel)?></button>
      <a class="btn-outline" href="/student/billing.php">Cancel</a>
    </div>
  </form>
  <p class="small">Prefer to pay by phone, check, or cash? Call us at
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var stripe = Stripe(<?=json_encode($publishableKey)?>);
  var elements = stripe.elements({
    clientSecret: <?=json_encode($clientSecret)?>
  });
  elements.create('payment').mount('#payment-element');

  var form = document.getElementById('payment-form');
  var button = document.getElementById('payment-submit');
  var message = document.getElementById('payment-message');
  var buttonLabel = button.textContent;

  function showError(text) {
    message.textContent = text;
    message.classList.remove('hidden');
    button.disabled = false;
    button.textContent = buttonLabel;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    button.disabled = true;
    button.textContent = 'Processing…';
    message.classList.add('hidden');

    stripe.confirmPayment({
      elements: elements,
      confirmParams: {
        return_url: <?=json_encode(Settings::absoluteUrl('/student/pay_return.php'))?>
      }
    }).then(function (result) {
      // Only card errors and validation problems land here; on success the
      // browser is redirected to return_url.
      if (result.error) {
        showError(result.error.message || 'We could not process that card. Please check the details and try again.');
      }
    }).catch(function () {
      showError('Something went wrong reaching our payment processor. Please try again, or call us.');
    });
  });
})();
</script>

<?php footer_html(); ?>
