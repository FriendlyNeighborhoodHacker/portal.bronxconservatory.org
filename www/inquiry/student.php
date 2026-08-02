<?php
// Information request page 3: who would like to study. Submitting this page is
// what turns the uncompleted form into a real lead. Evaluates to
// student_eval.php.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();
$state = inquiry_require_step(3);

$error = inquiry_flash_error();
$student = (array)$state['student'];

ApplicationUI::minimalHeaderHtml('Request Information — Student');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Student information</h1>
  <?=inquiry_steps_html(3)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/inquiry/student_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <div class="grid-2">
      <label>Student first name
        <input type="text" name="first_name" required value="<?=h($student['first_name'] ?? '')?>">
      </label>
      <label>Student last name
        <input type="text" name="last_name" required value="<?=h($student['last_name'] ?? '')?>">
      </label>
    </div>

    <label>Student age
      <input type="number" name="age" min="1" max="120" required value="<?=h($student['age'] ?? '')?>">
    </label>

    <div class="form-question">
      <div>New student or continuing student? <span class="required">*</span></div>
      <div class="choice-list">
        <?php foreach (LeadManagement::ENROLLMENT_STATUSES as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="enrollment_status" value="<?=h($value)?>"<?=inquiry_radio_checked($student, 'enrollment_status', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>Instruments of interest <span class="required">*</span></div>
      <div class="choice-grid">
        <?php foreach (LeadManagement::INQUIRY_INSTRUMENT_INTERESTS as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="instruments_of_interest[]" value="<?=h($instrument)?>"<?=inquiry_checked($student, 'instruments_of_interest', $instrument)?>>
          <?=h($instrument)?>
        </label>
        <?php endforeach; ?>
      </div>
      <label>If you chose Other, which instrument?
        <input type="text" name="instruments_other" value="<?=h($student['instruments_other'] ?? '')?>">
      </label>
    </div>

    <div class="actions actions-split">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <span class="actions-right">
        <button type="submit" name="action" value="next" class="btn-cta">Continue</button>
      </span>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
