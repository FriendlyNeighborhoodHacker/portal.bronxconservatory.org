<?php
// Convert a lead into live data: a validation-style preview (what will be
// created vs. adopted vs. already done) over a form for the admin's
// judgment calls — real instrument per student, optional date of birth,
// optional reservation placement, and where the Stripe payment lands.
// Commits via lead_convert_eval.php -> LeadManagement::convertLead.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_admin();

$leadId = (int)($_GET['id'] ?? 0);
$lead = LeadManagement::findLead($leadId);
if (!$lead) {
    http_response_code(404);
    die('Lead not found');
}
$students = LeadManagement::studentsForLead($leadId);
$instruments = InstrumentCatalog::all();

$existingParent = UserManagement::findByEmailAnyState((string)$lead['email']);

// The semester the lead registered for (falls back to the admin's working
// semester so conversion still works if the lead predates a semester).
$semesterId = !empty($lead['semester_id']) ? (int)$lead['semester_id'] : Application::adminSelectedSemesterId();
$semester = $semesterId ? SemesterManagement::find($semesterId) : null;
$columns = $semester ? SemesterManagement::locationTeachers((int)$semester['id']) : [];

// Location -> teachers map for the placement selects.
$locationTeachers = [];
foreach ($columns as $column) {
    $locationTeachers[(int)$column['location_id']]['name'] = (string)$column['location_name'];
    $locationTeachers[(int)$column['location_id']]['teachers'][(int)$column['teacher_user_id']] =
        (string)$column['teacher_first_name'] . ' ' . (string)$column['teacher_last_name'];
}

$flashError = $_SESSION['lead_flash_error'] ?? null;
unset($_SESSION['lead_flash_error']);
$old = $_SESSION['lead_convert_old'] ?? [];
unset($_SESSION['lead_convert_old']);

$paid = (int)$lead['amount_paid_cents'] > 0;

header_html('Convert lead — ' . $lead['parent_first_name'] . ' ' . $lead['parent_last_name'], ['wide' => true]);
?>

<h2>Convert — <?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?> family
  <?=lead_paid_badge_html($lead)?></h2>
<p class="small"><?=h(lead_students_summary($students))?> ·
  wants <?=h($lead['location_preference'] ?: 'any location')?> ·
  <?=h(implode(', ', json_decode((string)$lead['preferred_days'], true) ?: []) ?: 'any day')?></p>

<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card" style="overflow-x:auto;">
  <table class="list">
    <thead><tr><th>Who</th><th>What will happen</th></tr></thead>
    <tbody>
      <tr>
        <td><strong><?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?></strong> (parent)</td>
        <td class="small">
          <?php if ($existingParent): ?>
            <span class="status-pending">Adopts the existing account of
              <?=h($existingParent['first_name'] . ' ' . $existingParent['last_name'])?>
              (<?=h($lead['email'])?>)</span> — phone/address are filled in, nothing is overwritten with blanks.
          <?php else: ?>
            <span class="status-verified">Creates a new parent account</span> for <?=h($lead['email'])?> (no login until invited).
          <?php endif; ?>
        </td>
      </tr>
      <?php foreach ($students as $student): ?>
      <tr>
        <td><?=h($student['first_name'] . ' ' . $student['last_name'])?> (student)</td>
        <td class="small">
          <?php if (!empty($student['converted_student_user_id'])): ?>
            <span class="small">Already converted — no change.</span>
          <?php else: ?>
            <span class="status-verified">Creates the student</span> with profile, instrument, and parent link.
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($paid): ?>
      <tr>
        <td>Payment (<?=h(lead_dollars((int)$lead['amount_paid_cents']))?>)</td>
        <td class="small"><span class="status-verified">Moves onto the chosen student's ledger</span>
          as a Stripe payment credit (never recorded twice).</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<form method="post" action="/admin/lead_convert_eval.php" class="stack">
  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
  <input type="hidden" name="lead_id" value="<?=$leadId?>">

  <?php foreach ($students as $student): ?>
    <?php
      $lsId = (int)$student['id'];
      $alreadyConverted = !empty($student['converted_student_user_id']);
      $defaultInstrumentId = LeadManagement::instrumentIdForChoice((string)$student['instrument']);
      $oldRow = (array)($old['students'][$lsId] ?? []);
      $isCelloBass = $student['instrument'] === 'Cello/Bass';
    ?>
    <fieldset class="card stack">
      <legend style="font-weight:700;"><?=h($student['first_name'] . ' ' . $student['last_name'])?>
        <span class="small">(asked for <?=h($student['instrument'])?>, <?=(int)$student['lesson_length_minutes']?> min<?=!empty($student['guitar_ensemble']) ? ' + Ensemble' : ''?>)</span></legend>
      <?php if ($alreadyConverted): ?>
        <p class="small">Already converted — nothing more to choose here.</p>
      <?php else: ?>
      <div class="grid-2">
        <label>Instrument<?=$isCelloBass ? ' — they chose "Cello/Bass", pick which' : ''?>
          <select name="students[<?=$lsId?>][instrument_id]">
            <?php foreach ($instruments as $instrument): ?>
            <option value="<?=(int)$instrument['id']?>"<?=
                (int)($oldRow['instrument_id'] ?? $defaultInstrumentId) === (int)$instrument['id'] ? ' selected' : ''
            ?>><?=h($instrument['name'])?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Date of birth (optional<?=$student['class_of'] ? '; they gave Class of ' . h($student['class_of']) : ''?>)
          <input type="date" name="students[<?=$lsId?>][date_of_birth]" value="<?=h($oldRow['date_of_birth'] ?? '')?>">
        </label>
      </div>

      <?php if ($semester && $locationTeachers): ?>
      <div class="grid-3">
        <label>Place a reservation (optional) — location
          <select name="students[<?=$lsId?>][res_location_id]">
            <option value="">— skip placement —</option>
            <?php foreach ($locationTeachers as $locationId => $info): ?>
            <option value="<?=$locationId?>"<?=(int)($oldRow['res_location_id'] ?? 0) === $locationId ? ' selected' : ''?>><?=h($info['name'])?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Teacher
          <select name="students[<?=$lsId?>][res_teacher_user_id]">
            <option value="">—</option>
            <?php foreach ($locationTeachers as $locationId => $info): ?>
              <?php foreach ($info['teachers'] as $teacherId => $teacherName): ?>
              <option value="<?=$teacherId?>"<?=(int)($oldRow['res_teacher_user_id'] ?? 0) === $teacherId ? ' selected' : ''?>><?=h($teacherName . ' @ ' . $info['name'])?></option>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Day
          <select name="students[<?=$lsId?>][res_day]">
            <?php foreach (ReservationManagement::DAY_LABELS as $dayNum => $dayLabel): ?>
            <option value="<?=$dayNum?>"<?=(int)($oldRow['res_day'] ?? 6) === $dayNum ? ' selected' : ''?>><?=h($dayLabel)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Time
          <input type="time" name="students[<?=$lsId?>][res_start_time]" step="300" value="<?=h($oldRow['res_start_time'] ?? '')?>">
        </label>
        <label>Minutes
          <input type="number" name="students[<?=$lsId?>][res_duration]" min="15" step="15"
            value="<?=h($oldRow['res_duration'] ?? (int)$student['lesson_length_minutes'])?>">
        </label>
      </div>
      <p class="small">Placed reservations start as <strong>pending reach out</strong> —
        no lessons are generated and nothing is charged until you confirm them on the
        Schedule.</p>
      <?php else: ?>
      <p class="small">No semester schedule to place into<?=$semester ? ' (no teachers are assigned to locations yet)' : ''?> —
        convert now and place them from the Schedule grid later.</p>
      <?php endif; ?>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>

  <?php if ($paid): ?>
  <div class="card">
    <label>Record the <?=h(lead_dollars((int)$lead['amount_paid_cents']))?> payment on
      <select name="payment_target_lead_student_id">
        <?php foreach ($students as $i => $student): ?>
        <option value="<?=(int)$student['id']?>"<?=$i === 0 ? ' selected' : ''?>><?=h($student['first_name'] . ' ' . $student['last_name'])?>'s ledger</option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <?php endif; ?>

  <div class="actions">
    <button type="submit" class="btn-cta">Convert Lead</button>
    <a class="button" href="/admin/lead.php?id=<?=$leadId?>">Cancel</a>
  </div>
</form>

<?php footer_html(); ?>
