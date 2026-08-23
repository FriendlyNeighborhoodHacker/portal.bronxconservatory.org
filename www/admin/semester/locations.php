<?php
// Semester wizard step 2 (spec 3b): pick the semester's active locations.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
require_once __DIR__ . '/../../lib/LocationManagement.php';
Application::init();
require_admin();

$semesterId = (int)($_GET['semester_id'] ?? 0);
$semester = SemesterManagement::find($semesterId);
if (!$semester) {
    header('Location: /admin/semesters.php');
    exit;
}

$allLocations = LocationManagement::all(true);
$activeIds = array_map('intval', array_column(SemesterManagement::activeLocations($semesterId), 'id'));

$err = $_SESSION['semester_flash_error'] ?? null;
unset($_SESSION['semester_flash_error']);

header_html('Semester Locations');
?>

<h2><?=h(SemesterManagement::label($semester))?> — Locations</h2>
<div class="wizard-steps">
  <span class="wizard-step done"><span class="wizard-step-num">1</span> Semester</span>
  <span class="wizard-step active"><span class="wizard-step-num">2</span> Locations</span>
  <span class="wizard-step"><span class="wizard-step-num">3</span> Class Days</span>
  <span class="wizard-step"><span class="wizard-step-num">4</span> Class Dates</span>
  <span class="wizard-step"><span class="wizard-step-num">5</span> Teachers per Location</span>
  <span class="wizard-step"><span class="wizard-step-num">6</span> Hold Blocks</span>
  <span class="wizard-step"><span class="wizard-step-num">7</span> Schedule</span>
  <span class="wizard-step"><span class="wizard-step-num">8</span> Charges &amp; Payments</span>
</div>

<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p class="small">Which locations hold lessons this semester? The next step imports each
  location's class days — which weekdays it meets, with that day's hours.
  (<a href="/admin/locations.php">manage the location list</a>)</p>
  <form method="post" action="/admin/semester/locations_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="semester_id" value="<?=$semesterId?>">
    <?php foreach ($allLocations as $location): ?>
      <label class="inline">
        <input type="checkbox" name="location_ids[]" value="<?=(int)$location['id']?>"
               <?=in_array((int)$location['id'], $activeIds, true) || !$activeIds ? 'checked' : ''?>>
        <?=h($location['name'])?>
      </label>
    <?php endforeach; ?>
    <div class="actions">
      <button type="submit" class="button primary">Save &amp; Continue to Class Days</button>
      <a class="button" href="/admin/semesters.php">Finish later</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
