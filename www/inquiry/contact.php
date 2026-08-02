<?php
// Information request page 1: how to reach the parent or guardian. This is the
// page that matters most — submitting it saves an uncompleted form, so even a
// visitor who never finishes is someone we can call. Evaluates to
// contact_eval.php.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();
$state = inquiry_require_step(1);

$error = inquiry_flash_error();
$contact = (array)$state['contact'];

ApplicationUI::minimalHeaderHtml('Request Information — Contact');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Request information about our programs</h1>
  <?=inquiry_steps_html(1)?>

  <p>Tell us a little about who you are and who would like to study with us, and a member of
    our team will be in touch. It takes about three minutes, and there is nothing to pay.</p>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/inquiry/contact_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <h3>Parent / Guardian</h3>
    <div class="grid-2">
      <label>First name
        <input type="text" name="first_name" required value="<?=h($contact['first_name'] ?? '')?>">
      </label>
      <label>Last name
        <input type="text" name="last_name" required value="<?=h($contact['last_name'] ?? '')?>">
      </label>
    </div>

    <label>Email address
      <input type="email" name="email" required value="<?=h($contact['email'] ?? '')?>">
    </label>
    <label>Confirm email address
      <input type="email" name="confirm_email" required value="<?=h($contact['confirm_email'] ?? '')?>">
    </label>
    <label>Primary phone
      <input type="tel" name="phone" required value="<?=h($contact['phone'] ?? '')?>" placeholder="718-555-0123">
    </label>

    <label class="inline">
      <input type="checkbox" name="newsletter_opt_in" value="1"<?=!empty($contact['newsletter_opt_in']) ? ' checked' : ''?>>
      Subscribe to our newsletter?
    </label>
    <label class="inline">
      <input type="checkbox" name="sms_consent" value="1"<?=!empty($contact['sms_consent']) ? ' checked' : ''?>>
      May we text you with important updates from Bronx Conservatory of Music?
    </label>
    <p class="small">We send out messages three times a year. See
      <a href="https://www.bronxconservatory.org/privacy-policy/" target="_blank" rel="noopener">Privacy
      Policy and Terms of Service</a>.</p>

    <?=recaptcha_widget_html()?>

    <div class="actions">
      <button type="submit" class="btn-cta">Continue</button>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
