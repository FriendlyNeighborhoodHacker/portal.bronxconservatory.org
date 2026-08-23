<?php
// Registration wizard step 6: checkout. The itemized cost table, then the
// billing form — card fields and billing address are Stripe Elements, so
// they sit inside this page but the card details go straight from the
// browser to Stripe and never touch this server.
//
// The lead already exists by now (created on the review step), so a family
// who abandons payment is still recorded and can be followed up by phone.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
registration_require_open();


$leadId = (int)($_SESSION['registration_lead_id'] ?? 0);
$lead = $leadId ? LeadManagement::findLead($leadId) : null;
if (!$lead) {
    header('Location: /register/');
    exit;
}
// Already paid, nothing owed, or Stripe not set up — nothing to collect.
if ((int)$lead['amount_paid_cents'] > 0 || (int)$lead['amount_due_now_cents'] <= 0 || !StripeCheckout::isConfigured()) {
    header('Location: /register/done.php');
    exit;
}

$students = LeadManagement::studentsForLead($leadId);
$quoteLines = json_decode((string)$lead['quote_json'], true) ?: [];
$dueNow = (int)$lead['amount_due_now_cents'];
$installment = !empty($lead['installment_plan']);
$leadSemester = SemesterManagement::find((int)$lead['semester_id']);
$secondDueDate = $leadSemester ? SemesterManagement::secondInstallmentDueDate($leadSemester) : null;

$error = registration_flash_error();

// One PaymentIntent per lead, reused if the family reloads or a card is
// declined (Stripe keeps the same intent usable until it succeeds).
$clientSecret = (string)($_SESSION['registration_payment_client_secret'] ?? '');
$intentLeadId = (int)($_SESSION['registration_payment_lead_id'] ?? 0);
if ($clientSecret === '' || $intentLeadId !== $leadId) {
    try {
        $intent = StripeCheckout::createLeadPaymentIntent(
            null,
            $leadId,
            $dueNow,
            (string)$lead['email'],
            'BCM registration — ' . $lead['parent_first_name'] . ' ' . $lead['parent_last_name']
        );
        LeadManagement::attachPaymentIntent(null, $leadId, $intent['id']);
        $clientSecret = $intent['client_secret'];
        $_SESSION['registration_payment_client_secret'] = $clientSecret;
        $_SESSION['registration_payment_lead_id'] = $leadId;
    } catch (\Throwable $e) {
        // Payment can always be arranged by phone — never strand the family.
        $_SESSION['registration_error'] = 'We could not start the payment just now: '
            . $e->getMessage() . ' You can try again, or call us at ' . Settings::contactPhone() . '.';
        header('Location: /register/done.php?pay=error');
        exit;
    }
}

$publishableKey = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';

ApplicationUI::minimalHeaderHtml('Register — Checkout');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Checkout</h1>
  <?=registration_steps_html(6)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <div class="card">
    <table class="list checkout-table">
      <thead><tr><th>Description</th><th style="text-align:right;">Item Price</th></tr></thead>
      <tbody>
        <?php foreach ($quoteLines as $line): ?>
        <tr>
          <td><?=h($line['label'])?></td>
          <td style="text-align:right;"><?=registration_dollars((int)$line['amount_cents'])?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="checkout-total">
          <td><strong>Total</strong></td>
          <td style="text-align:right;"><strong><?=registration_dollars((int)$lead['amount_quoted_cents'])?></strong></td>
        </tr>
        <?php if ($installment): ?>
        <tr class="checkout-total">
          <td><strong>Due today</strong> <span class="small">(installment plan: all fees + 50% of tuition;
            the remaining tuition is due
            <?=$secondDueDate !== null ? 'by ' . h(date('M j, Y', strtotime($secondDueDate))) : 'by week 4 of the semester'?>)</span></td>
          <td style="text-align:right;"><strong><?=registration_dollars($dueNow)?></strong></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if ($leadSemester && ($feeNotice = SemesterManagement::installmentFeeNotice($leadSemester))): ?>
      <p class="small"><?=h($feeNotice)?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Billing Information
      <span class="secure-badge" title="Card details go directly to Stripe over an encrypted connection">&#128274; Secure</span>
    </h3>
    <p class="small">Enter your payment details below. Your card is processed by Stripe —
      the Bronx Conservatory of Music never sees or stores your card number.</p>

    <form id="payment-form" class="stack">
      <div id="payment-element"></div>
      <h3>Billing Address</h3>
      <div id="address-element"></div>
      <p class="small">A copy of your receipt will be emailed to
        <strong><?=h($lead['email'])?></strong>.</p>

      <p id="payment-message" class="error hidden" role="alert"></p>

      <div class="actions">
        <button id="payment-submit" type="submit" class="btn-cta">
          Pay <?=registration_dollars($dueNow)?>
        </button>
        <a class="btn-outline" href="/register/done.php?pay=later">I'll pay later</a>
      </div>
      <p class="small">Prefer to pay by phone or need to talk about cost? Call us at
        <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> — your registration
        is already saved.</p>
    </form>
  </div>
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
        // Keep Stripe's fields in the portal's palette (www/styles.css :root)
        // so the card form doesn't read as somebody else's page.
        colorPrimary: '#0062DC',
        colorText: '#1f2733',
        colorDanger: '#b91c1c',
        fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        borderRadius: '8px'
      }
    }
  });

  // 'never' is a promise to Stripe that we will supply these ourselves in
  // confirmParams.payment_method_data.billing_details — name and address from
  // the Address Element below, email from the lead. Break that promise and
  // confirmPayment throws an IntegrationError before it ever reaches Stripe,
  // which on screen is indistinguishable from a network fault.
  // Name is 'never' rather than 'auto' so the card form doesn't render a
  // second name field on top of the Address Element's own.
  elements.create('payment', {
    fields: { billingDetails: { address: 'never', email: 'never', name: 'never' } }
  }).mount('#payment-element');

  var addressElement = elements.create('address', {
    mode: 'billing',
    defaultValues: {
      name: <?=json_encode(trim($lead['parent_first_name'] . ' ' . $lead['parent_last_name']))?>,
      address: {
        line1: <?=json_encode((string)$lead['address_street_1'])?>,
        line2: <?=json_encode((string)($lead['address_street_2'] ?? ''))?>,
        city: <?=json_encode((string)$lead['address_city'])?>,
        state: <?=json_encode((string)$lead['address_state'])?>,
        postal_code: <?=json_encode((string)$lead['address_zip'])?>,
        country: 'US'
      }
    }
  });
  addressElement.mount('#address-element');

  var form = document.getElementById('payment-form');
  var button = document.getElementById('payment-submit');
  var message = document.getElementById('payment-message');

  function showError(text) {
    message.textContent = text;
    message.classList.remove('hidden');
    button.disabled = false;
    button.textContent = <?=json_encode('Pay ' . registration_dollars($dueNow))?>;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    button.disabled = true;
    button.textContent = 'Processing…';
    message.classList.add('hidden');

    // Hand the Address Element's value to confirmPayment, since the card
    // form was told not to collect it.
    addressElement.getValue().then(function (addr) {
      if (!addr.complete) {
        showError('Please complete your billing address.');
        return null;
      }
      return stripe.confirmPayment({
        elements: elements,
        confirmParams: {
          return_url: <?=json_encode(registration_absolute_url('/register/return.php'))?>,
          receipt_email: <?=json_encode((string)$lead['email'])?>,
          payment_method_data: {
            billing_details: {
              name: addr.value.name,
              email: <?=json_encode((string)$lead['email'])?>,
              address: addr.value.address
            }
          }
        }
      });
    }).then(function (result) {
      // Only card errors and validation problems land here; on success the
      // browser is redirected to return_url. null = we bailed out above.
      if (result && result.error) {
        showError(result.error.message || 'We could not process that card. Please check the details and try again.');
      }
    }).catch(function (err) {
      // An integration mistake rejects here rather than resolving with an
      // error, and used to read as "the processor is down" — say what it
      // actually was so the next one is diagnosable from the screen.
      console.error('Stripe confirmPayment failed:', err);
      var detail = err && err.message ? ' (' + err.message + ')' : '';
      showError('Something went wrong reaching our payment processor' + detail
        + '. Please try again, or call us.');
    });
  });
})();
</script>
<?php ApplicationUI::minimalFooterHtml(); ?>
