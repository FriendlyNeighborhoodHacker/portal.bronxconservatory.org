<?php
// Registration wizard step 5: review everything, see the itemized cost, pass
// the reCAPTCHA check, and submit (then straight to Stripe Checkout when
// configured). Evaluates to submit_eval.php.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
$semester = registration_require_open();
$state = registration_require_step(5);

$error = registration_flash_error();
$family = (array)$state['family'];
$students = registration_clean_students($state);
$scheduling = (array)$state['scheduling'];
$installment = (int)$state['installment'] === 1;
$quote = LeadManagement::priceQuote($students, $installment);
$stripeReady = StripeCheckout::isConfigured();

ApplicationUI::minimalHeaderHtml('Register — Review');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Review &amp; <?=$stripeReady ? 'Checkout' : 'Submit'?></h1>
  <?=registration_steps_html(5)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <div class="card">
    <h3 style="margin-top:0;">Tuition &amp; fees</h3>
    <table class="list">
      <thead><tr><th>Description</th><th style="text-align:right;">Item Price</th></tr></thead>
      <tbody>
        <?php foreach ($quote['lines'] as $line): ?>
        <tr>
          <td><?=h($line['label'])?></td>
          <td style="text-align:right;"><?=registration_dollars((int)$line['amount_cents'])?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td><strong>Total</strong></td>
          <td style="text-align:right;"><strong><?=registration_dollars($quote['total_cents'])?></strong></td>
        </tr>
        <?php if ($installment): ?>
        <tr>
          <td>Due today (installment plan: fees + 50% of tuition)</td>
          <td style="text-align:right;"><strong><?=registration_dollars($quote['due_now_cents'])?></strong></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Your answers
      <span class="small" style="font-weight:400;">(<a href="/register/family.php">edit family</a> ·
        <a href="/register/students.php">edit students</a> ·
        <a href="/register/payment_plan.php">edit payment plan</a>)</span></h3>
    <p class="small">
      <strong><?=h(($family['first_name'] ?? '') . ' ' . ($family['last_name'] ?? ''))?></strong><br>
      <?=h($family['email'] ?? '')?> · <?=h($family['phone'] ?? '')?><?=!empty($family['sms_consent']) ? ' · SMS ok' : ''?><br>
      <?=h(trim(($family['address_street_1'] ?? '') . ' ' . ($family['address_street_2'] ?? '')))?>,
      <?=h(($family['address_city'] ?? '') . ', ' . ($family['address_state'] ?? '') . ' ' . ($family['address_zip'] ?? ''))?>
    </p>
    <?php foreach ($students as $student): ?>
    <p class="small">
      <strong><?=h($student['first_name'] . ' ' . $student['last_name'])?></strong><?php
        if (trim((string)($student['class_of'] ?? '')) !== ''): ?> (Class of <?=h($student['class_of'])?>)<?php endif; ?>
      — <?=h($student['instrument'])?>,
      <?=(int)$student['lesson_length_minutes']?>-minute lessons<?=!empty($student['guitar_ensemble']) ? ' + Guitar Ensemble' : ''?><?php
        if (trim((string)($student['shirt_size'] ?? '')) !== ''): ?>, shirt <?=h($student['shirt_size'])?><?php endif; ?>
    </p>
    <?php endforeach; ?>
    <p class="small">
      Location: <?=h($scheduling['location_preference'] ?? '—')?><br>
      Days: <?=h(implode(', ', (array)($scheduling['preferred_days'] ?? [])) ?: '—')?> ·
      Times: <?=h(implode(', ', (array)($scheduling['availability_blocks'] ?? [])) ?: '—')?>
      <?php if (trim((string)($scheduling['notes'] ?? '')) !== ''): ?><br>Notes: <?=h($scheduling['notes'])?><?php endif; ?>
    </p>
    <p class="small">Payment: <?=$installment ? 'installment plan' : 'pay in full'?> ·
      Policies agreed ✓</p>
  </div>

  <form method="post" action="/register/submit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?=recaptcha_widget_html()?>
    <div class="actions">
      <a class="btn-outline" href="/register/payment_plan.php">Back</a>
      <button type="submit" class="btn-cta">
        <?=$stripeReady ? 'Continue to Checkout' : 'Submit Registration'?>
      </button>
    </div>
    <?php if (!$stripeReady): ?>
      <p class="small">We'll follow up within 5 business days to arrange payment and scheduling.</p>
    <?php endif; ?>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
