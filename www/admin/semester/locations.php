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

// Prefill each location's day grid: declared weekdays first, else the
// weekdays (and widest hours) of its already-imported class dates, else
// nothing checked (Saturday when the location is entirely blank). This
// ordering matters — re-saving this page must never silently drop a day the
// semester actually uses.
$dayPrefill = []; // [locationId => [dow => ['start' => 'HH:MM', 'end' => 'HH:MM']]]
$dayPrefillFromDates = [];
foreach (SemesterManagement::locationWeekdays($semesterId) as $weekdayRow) {
    $dayPrefill[(int)$weekdayRow['location_id']][(int)$weekdayRow['day_of_week']] = [
        'start' => substr((string)$weekdayRow['start_time'], 0, 5),
        'end' => substr((string)$weekdayRow['end_time'], 0, 5),
    ];
}
foreach (SemesterManagement::locationDates($semesterId) as $dateRow) {
    $locationId = (int)$dateRow['location_id'];
    if (isset($dayPrefill[$locationId])) {
        continue; // declared rows win for the whole location
    }
    $dow = (int)date('w', strtotime((string)$dateRow['date']));
    $start = substr((string)$dateRow['start_time'], 0, 5);
    $end = substr((string)$dateRow['end_time'], 0, 5);
    $existingRow = $dayPrefillFromDates[$locationId][$dow] ?? null;
    $dayPrefillFromDates[$locationId][$dow] = [
        'start' => $existingRow ? min($existingRow['start'], $start) : $start,
        'end' => $existingRow ? max($existingRow['end'], $end) : $end,
    ];
}
foreach ($dayPrefillFromDates as $locationId => $days) {
    if (!isset($dayPrefill[$locationId])) {
        $dayPrefill[$locationId] = $days;
    }
}

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$err = $_SESSION['semester_flash_error'] ?? null;
unset($_SESSION['semester_flash_error']);

header_html('Semester Locations');
?>

<h2><?=h(SemesterManagement::label($semester))?> — Locations</h2>
<div class="wizard-steps">
  <span class="wizard-step done"><span class="wizard-step-num">1</span> Semester</span>
  <span class="wizard-step active"><span class="wizard-step-num">2</span> Locations</span>
  <span class="wizard-step"><span class="wizard-step-num">3</span> Class Dates</span>
  <span class="wizard-step"><span class="wizard-step-num">4</span> Teachers per Location</span>
  <span class="wizard-step"><span class="wizard-step-num">5</span> Hold Blocks</span>
  <span class="wizard-step"><span class="wizard-step-num">6</span> Schedule</span>
  <span class="wizard-step"><span class="wizard-step-num">7</span> Charges &amp; Payments</span>
</div>

<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p class="small">Which locations hold lessons this semester, on which days, and over what hours?
  Class dates imported in the next step must fall on these days, and rows with blank times inherit
  these hours. (<a href="/admin/locations.php">manage the location list</a>)</p>
  <form method="post" action="/admin/semester/locations_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="semester_id" value="<?=$semesterId?>">
    <?php foreach ($allLocations as $location): ?>
      <?php
        $locationId = (int)$location['id'];
        $days = $dayPrefill[$locationId] ?? [];
      ?>
      <fieldset class="loc-days">
        <label class="inline">
          <input type="checkbox" name="location_ids[]" value="<?=$locationId?>"
                 <?=in_array($locationId, $activeIds, true) || !$activeIds ? 'checked' : ''?>>
          <strong><?=h($location['name'])?></strong>
        </label>
        <div class="loc-day-grid">
          <?php foreach ($dayNames as $dow => $dayName): ?>
            <?php
              // A location with no history: pre-check Saturday 9-5 so the
              // common case is one click.
              $checked = isset($days[$dow]) || (!$days && $dow === SemesterManagement::DEFAULT_TEACHING_DAY);
              $start = $days[$dow]['start'] ?? '09:00';
              $end = $days[$dow]['end'] ?? '17:00';
            ?>
            <div class="loc-day-row">
              <label class="inline">
                <input type="checkbox" name="location_days[<?=$locationId?>][<?=$dow?>][on]" value="1"
                       <?=$checked ? 'checked' : ''?>>
                <?=h($dayName)?>
              </label>
              <span class="loc-day-times">
                <input type="time" name="location_days[<?=$locationId?>][<?=$dow?>][start]" value="<?=h($start)?>">
                &ndash;
                <input type="time" name="location_days[<?=$locationId?>][<?=$dow?>][end]" value="<?=h($end)?>">
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <div class="actions">
      <button type="submit" class="button primary">Save &amp; Continue to Class Dates</button>
      <a class="button" href="/admin/semesters.php">Finish later</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
