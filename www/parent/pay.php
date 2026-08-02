<?php
// Pay a family balance: the card fields are Stripe Elements, so they sit on
// this page but the card details go straight from the browser to Stripe and
// never touch this server (the same arrangement as the registration form).
//
// The amount comes from the form on Billing and is split across the children
// who owe, oldest debt first. That split travels in the PaymentIntent's
// metadata, which is what routes the money onto the right ledgers when the
// payment succeeds (StripeCheckout::handlePaymentIntentSucceeded).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$me = current_user();

if (!StripeCheckout::isConfigured()) {
    $_SESSION['billing_flash_error'] = 'Online payment is not set up yet — please call us and we will take your payment over the phone.';
    header('Location: /parent/billing.php');
    exit;
}

$outstanding = Billing::outstandingByChildForParent((int)$me['id']);
$totalDue = 0;
foreach ($outstanding as $row) {
    $totalDue += $row['due_cents'];
}
if ($totalDue <= 0) {
    $_SESSION['billing_flash'] = 'You are paid in full — there is nothing to pay.';
    header('Location: /parent/billing.php');
    exit;
}

// Default to the whole balance; a smaller payment is welcome, a larger one is
// capped at what is owed (prepaying a term that has not been billed yet is a
// phone call, not a form).
$requested = trim((string)($_GET['amount'] ?? ''));
$amountCents = $requested === '' ? $totalDue : (Billing::parseAmountCents($requested) ?? 0);
if ($amountCents <= 0) {
    $_SESSION['billing_flash_error'] = 'Please enter the amount you would like to pay.';
    header('Location: /parent/billing.php');
    exit;
}
$amountCents = min($amountCents, $totalDue);

$allocation = Billing::allocatePaymentAcrossStudents(
    array_column($outstanding, 'due_cents', 'student_user_id'),
    $amountCents
);
$semesters = array_column($outstanding, 'semester_id', 'student_user_id');
$namesById = [];
foreach ($outstanding as $row) {
    $namesById[$row['student_user_id']] = trim($row['first_name'] . ' ' . $row['last_name']);
}

// One PaymentIntent per (amount, balance), reused if the page is reloaded or
// a card is declined — Stripe keeps the same intent usable until it succeeds.
// The balance is part of the key so that a payment which already landed can
// never hand its spent intent to the next one.
$paymentKey = $amountCents . ':' . $totalDue;
$clientSecret = (string)($_SESSION['family_payment_client_secret'] ?? '');
if ($clientSecret === '' || (string)($_SESSION['family_payment_key'] ?? '') !== $paymentKey) {
    try {
        $intent = StripeCheckout::createFamilyPaymentIntent(
            UserContext::getLoggedInUserContext(),
            $allocation,
            $semesters,
            (int)$me['id'],
            (string)($me['email'] ?? ''),
            'BCM tuition — ' . trim($me['first_name'] . ' ' . $me['last_name'])
        );
        $clientSecret = $intent['client_secret'];
        $_SESSION['family_payment_client_secret'] = $clientSecret;
        $_SESSION['family_payment_key'] = $paymentKey;
    } catch (\Throwable $e) {
        $_SESSION['billing_flash_error'] = 'We could not start the payment just now: ' . $e->getMessage()
            . ' You can try again, or call us at ' . Settings::contactPhone() . '.';
        header('Location: /parent/billing.php');
        exit;
    }
}

$publishableKey = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
$amountLabel = Billing::formatCents($amountCents);

header_html('Pay your balance');
?>

<h2>Pay <?=h($amountLabel)?></h2>

<div class="card">
  <table class="list">
    <thead><tr><th>Applied to</th><th style="text-align:right;">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($allocation as $studentUserId => $cents): ?>
      <tr>
        <td><?=h($namesById[$studentUserId] ?? 'Tuition')?></td>
        <td style="text-align:right;"><?=h(Billing::formatCents($cents))?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small">Payments go to the oldest balance first.
    <a href="/parent/billing.php">Change the amount</a></p>
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
      <a class="btn-outline" href="/parent/billing.php">Cancel</a>
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
    clientSecret: <?=json_encode($clientSecret)?>,
    appearance: {
      theme: 'stripe',
      variables: {
        // Stripe's fields in the portal's palette (www/styles.css :root).
        colorPrimary: '#0062DC',
        colorText: '#1f2733',
        colorDanger: '#b91c1c',
        fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        borderRadius: '8px'
      }
    }
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
        return_url: <?=json_encode(Settings::absoluteUrl('/parent/pay_return.php'))?>
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
