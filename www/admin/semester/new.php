<?php
// Semester wizard step 1 (spec 3a): season, year, start and end dates.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
Application::init();
require_admin();

$err = $_SESSION['semester_flash_error'] ?? null;
$old = $_SESSION['semester_form_old'] ?? [];
unset($_SESSION['semester_flash_error'], $_SESSION['semester_form_old']);

header_html('New Semester');
?>

<h2>New Semester</h2>
<div class="wizard-steps">
  <span class="wizard-step active"><span class="wizard-step-num">1</span> Semester</span>
  <span class="wizard-step"><span class="wizard-step-num">2</span> Locations</span>
  <span class="wizard-step"><span class="wizard-step-num">3</span> Class Dates</span>
  <span class="wizard-step"><span class="wizard-step-num">4</span> Teachers per Location</span>
</div>

<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/semester/new_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid-2">
      <label>Season
        <select name="season" required>
          <?php foreach (SemesterManagement::SEASONS as $season): ?>
            <option value="<?=h($season)?>" <?=($old['season'] ?? '') === $season ? 'selected' : ''?>>
              <?=h(ucfirst($season))?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Year
        <input type="number" name="year" min="2020" max="2100" required
               value="<?=h($old['year'] ?? date('Y'))?>">
      </label>
      <label>Start date
        <input type="date" name="start_date" required value="<?=h($old['start_date'] ?? '')?>">
      </label>
      <label>End date
        <input type="date" name="end_date" required value="<?=h($old['end_date'] ?? '')?>">
      </label>
      <label>Registration fee ($)
        <input type="text" name="pricing[registration_fee]" value="<?=h($old['pricing']['registration_fee'] ?? '')?>">
      </label>
      <label>30-minute lesson fee ($/semester)
        <input type="text" name="pricing[lesson_fee_30_minutes]" value="<?=h($old['pricing']['lesson_fee_30_minutes'] ?? '')?>">
      </label>
      <label>60-minute lesson fee ($/semester)
        <input type="text" name="pricing[lesson_fee_60_minutes]" value="<?=h($old['pricing']['lesson_fee_60_minutes'] ?? '')?>">
      </label>
      <label>Guitar Ensemble fee ($/semester)
        <input type="text" name="pricing[guitar_ensemble_fee]" value="<?=h($old['pricing']['guitar_ensemble_fee'] ?? '')?>">
      </label>
      <label>Recital fee ($)
        <input type="text" name="pricing[recital_fee]" value="<?=h($old['pricing']['recital_fee'] ?? '')?>">
      </label>
      <label>Installment plan fee ($)
        <input type="text" name="pricing[installment_plan_fee]" value="<?=h($old['pricing']['installment_plan_fee'] ?? '')?>">
      </label>
      <label>Lessons per semester
        <input type="number" name="pricing[lessons_per_semester]" min="1" value="<?=h($old['pricing']['lessons_per_semester'] ?? '15')?>">
      </label>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Create &amp; Continue</button>
      <a class="button" href="/admin/semesters.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
