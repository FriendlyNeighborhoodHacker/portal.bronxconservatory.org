<?php
// Information request page 4: the last of it — term, experience, and how they
// found us. The lead already exists by now, so nothing on this page can lose a
// family. Evaluates to details_eval.php.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();
$state = inquiry_require_step(4);

$error = inquiry_flash_error();
$details = (array)$state['details'];
$semesterOptions = Settings::inquirySemesterOptions();

ApplicationUI::minimalHeaderHtml('Request Information — Details');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>A few final details</h1>
  <?=inquiry_steps_html(4)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/inquiry/details_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <div class="form-question">
      <div>Which term are you interested in? <span class="required">*</span></div>
      <div class="choice-list">
        <?php foreach ($semesterOptions as $option): ?>
        <label class="inline">
          <input type="radio" name="semester_label" value="<?=h($option)?>"<?=inquiry_radio_checked($details, 'semester_label', $option)?>>
          <?=h($option)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>Do you own any instruments?</div>
      <div class="choice-grid">
        <?php foreach (LeadManagement::OWNED_INSTRUMENT_CHOICES as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="owned_instruments[]" value="<?=h($instrument)?>"<?=inquiry_checked($details, 'owned_instruments', $instrument)?>>
          <?=h($instrument)?>
        </label>
        <?php endforeach; ?>
      </div>
      <label>If you chose Other, which instrument?
        <input type="text" name="owned_instruments_other" value="<?=h($details['owned_instruments_other'] ?? '')?>">
      </label>
    </div>

    <label>Have you or your child studied music?
      <textarea name="music_background" rows="4" placeholder="Please tell us about the level and length of study."><?=h($details['music_background'] ?? '')?></textarea>
    </label>

    <div class="form-question">
      <div>Are you interested in our free music theory program?</div>
      <div class="choice-list">
        <?php foreach (LeadManagement::THEORY_INTEREST_LABELS as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="theory_program_interest" value="<?=h($value)?>"<?=inquiry_radio_checked($details, 'theory_program_interest', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>What is your or your child's music theory knowledge?</div>
      <div class="choice-list">
        <?php foreach (LeadManagement::THEORY_KNOWLEDGE_LABELS as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="theory_knowledge" value="<?=h($value)?>"<?=inquiry_radio_checked($details, 'theory_knowledge', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <label>Comments
      <textarea name="comments" rows="4" placeholder="Please list any questions or concerns"><?=h($details['comments'] ?? '')?></textarea>
    </label>

    <label>How did you hear about Bronx Conservatory of Music?
      <select name="referral_source">
        <option value="">— choose —</option>
        <?php foreach (InquiryManagement::REFERRAL_SOURCES as $source): ?>
        <option value="<?=h($source)?>"<?=(string)($details['referral_source'] ?? '') === $source ? ' selected' : ''?>><?=h($source)?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="actions actions-split">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <span class="actions-right">
        <button type="submit" name="action" value="next" class="btn-cta">Submit</button>
      </span>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
