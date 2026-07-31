<?php
// Assign a family's schedule: one row per student with teacher, instrument,
// location, first lesson date/time, and an optional weekly-recurring end
// date. Evaluates to family_assign_schedule_eval.php, which creates the
// lessons (materializing weekly occurrences) and offers the "Great news"
// email from the family page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_admin();

$familyId = (int)($_GET['id'] ?? 0);
$family = FamilyManagement::getFamilyDetail($familyId);
if (!$family) {
    http_response_code(404);
    die('Family not found');
}

$teachers = StudentTeacherManagement::listTeachers();
$instruments = InstrumentCatalog::all();
$locations = LocationManagement::all(true);

$flashError = $_SESSION['family_flash_error'] ?? null;
unset($_SESSION['family_flash_error']);

header_html('Assign Schedule — ' . $family['family_name']);
?>

<h2>Assign Schedule — <?=h($family['family_name'])?> family</h2>
<p class="small"><?=h(FamilyManagement::familySummaryLine($family))?></p>
<?php if (!$teachers): ?>
  <p class="error">No teachers exist yet — create one under <a href="/admin/users.php">Users</a>
  (edit a user and check "Is a teacher") before assigning a schedule.</p>
<?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<form method="post" action="/admin/family_assign_schedule_eval.php" class="stack">
  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
  <input type="hidden" name="family_id" value="<?=$familyId?>">

  <?php foreach ($family['students'] as $i => $student): ?>
  <fieldset class="register-section">
    <legend><?=h($student['first_name'] . ' ' . $student['last_name'])?>
      <span class="small">(<?=h(implode(', ', $student['instruments'] ?: ['no instrument chosen']))?>)</span></legend>
    <input type="hidden" name="rows[<?=$i?>][student_user_id]" value="<?=(int)$student['id']?>">
    <div class="grid-3">
      <label>Teacher
        <select name="rows[<?=$i?>][teacher_user_id]">
          <option value="">— skip this student —</option>
          <?php foreach ($teachers as $t): ?>
          <option value="<?=(int)$t['id']?>"><?=h($t['first_name'] . ' ' . $t['last_name'] . ' (' . implode('/', $t['instruments'] ?: ['any']) . ')')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Instrument
        <select name="rows[<?=$i?>][instrument_id]">
          <option value="">—</option>
          <?php foreach ($instruments as $inst): ?>
          <option value="<?=(int)$inst['id']?>"<?=in_array($inst['name'], $student['instruments'], true) ? ' selected' : ''?>><?=h($inst['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Location
        <select name="rows[<?=$i?>][location_id]">
          <option value="">—</option>
          <?php foreach ($locations as $loc): ?>
          <option value="<?=(int)$loc['id']?>"<?=(int)($family['submission']['preferred_location_id'] ?? 0) === (int)$loc['id'] ? ' selected' : ''?>><?=h($loc['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Room
        <input type="text" name="rows[<?=$i?>][room]" placeholder="12">
      </label>
      <label>First lesson date
        <input type="date" name="rows[<?=$i?>][first_date]">
      </label>
      <label>Time
        <input type="time" name="rows[<?=$i?>][start_time]" step="300">
      </label>
      <label>Length (minutes)
        <input type="number" name="rows[<?=$i?>][duration_minutes]" value="30" min="15" step="15">
      </label>
      <label>Repeats weekly until
        <input type="date" name="rows[<?=$i?>][repeat_until]">
      </label>
      <label class="inline" style="align-self:end;">
        <input type="checkbox" name="rows[<?=$i?>][is_online]" value="1"> Online lesson
      </label>
    </div>
    <p class="small">Leave "Repeats weekly until" empty for a single lesson. With a date set,
    a weekly recurring lesson is created and its occurrences are generated through that date.</p>
  </fieldset>
  <?php endforeach; ?>

  <div class="register-actions">
    <button type="submit" class="btn-cta">Create Schedule</button>
    <a class="btn-outline" href="/admin/family.php?id=<?=$familyId?>">Cancel</a>
  </div>
</form>

<?php footer_html(); ?>
