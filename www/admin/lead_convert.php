<?php
// Convert a lead into live data: a validation-style preview (what will be
// created vs. adopted vs. already done) over a form for the admin's
// judgment calls — real instrument per student, optional date of birth,
// optional reservation placement, and where the Stripe payment lands.
// Commits via lead_convert_eval.php -> LeadManagement::convertLead.
//
// Placement is picked off the semester schedule rather than typed, so the
// admin sees the teacher's existing week before choosing and cannot book a
// slot that is already taken.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/reservation_picker_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ScheduleGridData.php';
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

// The same weekly grid the Semester Schedule draws, reused as a slot picker.
$grid = $semester ? ScheduleGridData::semesterWeeklyGrid((int)$semester['id']) : null;
$canPlace = $grid && $grid['columns'];

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
      $defaultInstrumentId = LeadManagement::defaultInstrumentIdForLeadStudent($student);
      $oldRow = (array)($old['students'][$lsId] ?? []);
      $isCelloBass = $student['instrument'] === 'Cello/Bass';
      // An inquiry lead student has no lesson length yet; 30 is the house default.
      $defaultDuration = (int)($student['lesson_length_minutes'] ?? 0) ?: 30;
    ?>
    <fieldset class="card stack">
      <legend style="font-weight:700;"><?=h($student['first_name'] . ' ' . $student['last_name'])?>
        <span class="small">(<?=h(lead_student_wants($student))?>)</span></legend>
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

      <?php if ($canPlace): ?>
      <?php
        // Repopulate the pick after a failed submit — the form must never
        // make an admin choose the same slot twice.
        $pickedColumn = !empty($oldRow['res_teacher_user_id']) && !empty($oldRow['res_location_id'])
            ? reservation_pick_column($grid['columns'], (int)$oldRow['res_location_id'], (int)$oldRow['res_teacher_user_id'])
            : null;
        $pickedSummary = ($pickedColumn && !empty($oldRow['res_start_time']))
            ? reservation_pick_summary(
                $pickedColumn,
                (int)($oldRow['res_day'] ?? 0),
                (string)$oldRow['res_start_time'],
                (int)($oldRow['res_duration'] ?? 0) ?: $defaultDuration
            )
            : '';
      ?>
      <input type="hidden" id="pick<?=$lsId?>_loc" name="students[<?=$lsId?>][res_location_id]" value="<?=h($oldRow['res_location_id'] ?? '')?>">
      <input type="hidden" id="pick<?=$lsId?>_teacher" name="students[<?=$lsId?>][res_teacher_user_id]" value="<?=h($oldRow['res_teacher_user_id'] ?? '')?>">
      <input type="hidden" id="pick<?=$lsId?>_day" name="students[<?=$lsId?>][res_day]" value="<?=h($oldRow['res_day'] ?? '')?>">
      <input type="hidden" id="pick<?=$lsId?>_time" name="students[<?=$lsId?>][res_start_time]" value="<?=h($oldRow['res_start_time'] ?? '')?>">
      <input type="hidden" id="pick<?=$lsId?>_dur" name="students[<?=$lsId?>][res_duration]" value="<?=h($oldRow['res_duration'] ?? '')?>">

      <div>
        <p class="small" style="margin-bottom:6px;">Reservation:
          <strong id="pick<?=$lsId?>_summary"><?=h($pickedSummary !== ''
              ? $pickedSummary
              : 'not placed — convert now and place them from the Schedule later')?></strong>
          <a href="#" data-pick-clear data-lead-student-id="<?=$lsId?>" id="pick<?=$lsId?>_clear"
             class="small<?=$pickedSummary !== '' ? '' : ' hidden'?>">Clear</a>
        </p>
        <button type="button" class="button" data-pick-slot
                data-lead-student-id="<?=$lsId?>"
                data-student-name="<?=h($student['first_name'] . ' ' . $student['last_name'])?>"
                data-default-duration="<?=$defaultDuration?>">Choose a time…</button>
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

<?php if ($canPlace) { render_reservation_picker_modal((int)$semester['id'], $grid); } ?>

<?php footer_html(); ?>
