<?php
// Information request page 2: mailing address. Evaluates to address_eval.php.
//
// The state field is rendered twice — a dropdown of US states and a free-text
// province — and both are submitted. A small script shows whichever the chosen
// country calls for; with scripting off both stay visible and the evaluator
// picks by country, so the page still works.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();
$state = inquiry_require_step(2);

$error = inquiry_flash_error();
$address = (array)$state['address'];
$country = inquiry_value($address, 'address_country', InquiryManagement::DEFAULT_COUNTRY);
$isUs = $country === InquiryManagement::DEFAULT_COUNTRY;

ApplicationUI::minimalHeaderHtml('Request Information — Address');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Mailing address</h1>
  <?=inquiry_steps_html(2)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/inquiry/address_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <label>Country
      <select name="address_country" id="inqCountry">
        <?php foreach (InquiryManagement::COUNTRIES as $option): ?>
        <option value="<?=h($option)?>"<?=$option === $country ? ' selected' : ''?>><?=h($option)?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <p class="small<?=$isUs ? ' hidden' : ''?>" id="inqAbroadNote">We use this address for mailings
      only. Lessons take place in person at our Bronx locations.</p>

    <label>Street address
      <input type="text" name="address_street_1" required value="<?=h($address['address_street_1'] ?? '')?>">
    </label>
    <label>Address line 2 (enter apartment number/letter here)
      <input type="text" name="address_street_2" value="<?=h($address['address_street_2'] ?? '')?>">
    </label>

    <div class="grid-2">
      <label>City
        <input type="text" name="address_city" required value="<?=h($address['address_city'] ?? 'Bronx')?>">
      </label>
      <label id="inqStateWrap"<?=$isUs ? '' : ' class="hidden"'?>>State
        <select name="address_state">
          <option value="">— choose —</option>
          <?php
            $savedState = strtoupper(inquiry_value($address, 'address_state', 'NY'));
            foreach (InquiryManagement::US_STATES as $code => $name):
          ?>
          <option value="<?=h($code)?>"<?=$code === $savedState ? ' selected' : ''?>><?=h($name)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label id="inqProvinceWrap"<?=$isUs ? ' class="hidden"' : ''?>>State / Province / Region (optional)
        <input type="text" name="address_province" value="<?=h($address['address_province'] ?? '')?>">
      </label>
    </div>

    <label>Postal code
      <input type="text" name="address_zip" required value="<?=h($address['address_zip'] ?? '')?>">
    </label>

    <div class="actions actions-split">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <span class="actions-right">
        <button type="submit" name="action" value="next" class="btn-cta">Continue</button>
      </span>
    </div>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var country = document.getElementById('inqCountry');
  function sync() {
    var isUs = country.value === <?=json_encode(InquiryManagement::DEFAULT_COUNTRY)?>;
    document.getElementById('inqStateWrap').classList.toggle('hidden', !isUs);
    document.getElementById('inqProvinceWrap').classList.toggle('hidden', isUs);
    document.getElementById('inqAbroadNote').classList.toggle('hidden', isUs);
  }
  country.addEventListener('change', sync);
  sync();
});
</script>
<?php ApplicationUI::minimalFooterHtml(); ?>
