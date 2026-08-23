<?php
// Registration wizard step 1: family information. Evaluates to family_eval.php.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
$semester = registration_require_open();
$state = registration_require_step(1);

$error = registration_flash_error();
$family = (array)$state['family'];

ApplicationUI::minimalHeaderHtml('Register — Family');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>BCM <?=h(registration_semester_label($semester))?> Registration</h1>
  <?=registration_steps_html(1)?>

  <p>Please use this form to register and pay tuition if you are a BRONX RESIDENT studying IN PERSON<?php if (StripeCheckout::isConfigured()): ?> and pay tuition<?php endif; ?>.
    It will take only about 5 minutes to complete. If you would like to discuss scheduling,
    have any questions, prefer to pay over the phone, or would like to be considered for
    scholarship, please still fill out this form and we will call you to follow up.</p>

  <p class="contact-callout">Rather do this with a person? <span>Call us at
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> and we'll walk you through it.</span></p>

  <?=registration_tuition_intro_html($semester)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/register/family_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <h3>Parent / Guardian</h3>
    <div class="grid-2">
      <label>First name
        <input type="text" name="first_name" required value="<?=h($family['first_name'] ?? '')?>">
      </label>
      <label>Last name
        <input type="text" name="last_name" required value="<?=h($family['last_name'] ?? '')?>">
      </label>
      <label>Best phone number to reach you (home or cell)
        <input type="tel" name="phone" required value="<?=h($family['phone'] ?? '')?>" placeholder="718-555-0123">
      </label>
      <label>Email
        <input type="email" name="email" required value="<?=h($family['email'] ?? '')?>">
      </label>
    </div>

    <p class="small">By submitting your cell phone number you are agreeing to receive text
      messages from Bronx Conservatory of Music. If you have consented to receive text
      messages from BCM, you may receive text messages related to announcements, promotional
      offers, alerts, confirmations, surveys, and/or other general communication. Message and
      data rates may apply. Text HELP for more information, text STOP to stop receiving
      messages or visit our website to read our Privacy Policy and Terms and Conditions.</p>
    <label class="inline">
      <input type="checkbox" name="sms_consent" value="1"<?=!empty($family['sms_consent']) ? ' checked' : ''?>>
      I consent to receive SMS from Bronx Conservatory of Music. Reply STOP to end; Reply HELP
      for help; Msg&amp;Data rates apply; Messaging frequency may vary.
    </label>

    <h3>Address</h3>
    <label>Street address
      <input type="text" name="address_street_1" required value="<?=h($family['address_street_1'] ?? '')?>">
    </label>
    <label>Address line 2 (enter apartment number/letter here)
      <input type="text" name="address_street_2" value="<?=h($family['address_street_2'] ?? '')?>">
    </label>
    <div class="grid-3">
      <label>City
        <input type="text" name="address_city" required value="<?=h($family['address_city'] ?? 'Bronx')?>">
      </label>
      <label>State
        <input type="text" name="address_state" required value="<?=h($family['address_state'] ?? 'NY')?>">
      </label>
      <label>Postal / ZIP code
        <input type="text" name="address_zip" required value="<?=h($family['address_zip'] ?? '')?>">
      </label>
    </div>
    <p class="small">Country: United States</p>

    <div class="actions">
      <button type="submit" class="btn-cta">Continue</button>
    </div>
  </form>
</div>

<script>
// The moment a plausible email is on the form, save a drop-off draft in the
// background — a family who types their email and walks away is already
// worth a phone call. Fires on leaving any contact field once the email
// looks real; repeats update the same draft (session-keyed server side).
// Entirely silent: nothing here may interrupt someone still typing.
(function () {
  var form = document.querySelector('form[action="/register/family_eval.php"]');
  if (!form) return;
  var fields = ['first_name', 'last_name', 'phone', 'email'].map(function (name) {
    return form.querySelector('[name="' + name + '"]');
  });
  var lastSent = '';

  function maybeCapture() {
    var email = (fields[3].value || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return;
    var payload = fields.map(function (f) { return f.value.trim(); }).join('|');
    if (payload === lastSent) return; // nothing new to save
    lastSent = payload;
    var data = new FormData();
    data.append('csrf', form.querySelector('input[name=csrf]').value);
    data.append('first_name', fields[0].value);
    data.append('last_name', fields[1].value);
    data.append('phone', fields[2].value);
    data.append('email', email);
    fetch('/register/draft_eval.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .catch(function () {});
  }

  fields.forEach(function (field) {
    field.addEventListener('blur', maybeCapture);
  });
})();
</script>
<?php ApplicationUI::minimalFooterHtml(); ?>
