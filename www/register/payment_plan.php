<?php
// Registration wizard step 4: installment plan choice.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();
$state = registration_require_step(4);

$error = registration_flash_error();
$installment = $state['installment']; // null | 0 | 1

ApplicationUI::minimalHeaderHtml('Register — Payment Plan');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Installment Plan</h1>
  <?=registration_steps_html(4)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <p><span class="prompt-em">Would you like to pay in two installments?</span> There is a
    <?=registration_dollars(Settings::installmentFeeCents())?> fee for this option. Check "No"
    if you intend to pay the full tuition upfront.</p>

  <form method="post" action="/register/payment_plan_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label class="inline">
      <input type="radio" name="installment" value="0"<?=$installment === 0 || $installment === '0' ? ' checked' : ''?>>
      <span><strong>No</strong> — pay the full tuition and fees today.</span>
    </label>
    <label class="inline">
      <input type="radio" name="installment" value="1"<?=$installment === 1 || $installment === '1' ? ' checked' : ''?>>
      <span><strong>Yes</strong> — installment plan (+<?=registration_dollars(Settings::installmentFeeCents())?>):
        fees and 50% of tuition today, the remaining tuition due by week 4 of the semester.</span>
    </label>
    <div class="actions">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <button type="submit" name="action" value="next" class="btn-cta">Continue</button>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
