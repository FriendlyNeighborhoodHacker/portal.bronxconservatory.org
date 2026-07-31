<?php
// Family detail: everything from the registration form, internal notes, the
// assigned schedule, status control, and the Assign Schedule / email actions.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

$familyId = (int)($_GET['id'] ?? 0);
$family = FamilyManagement::getFamilyDetail($familyId);
if (!$family) {
    http_response_code(404);
    die('Family not found');
}

$notes = NotesManagement::notesForFamily($familyId);
$lessons = LessonManagement::lessonsForFamily($familyId, date('Y-m-d'));
$submission = $family['submission'];

// One-shot flashes from the eval endpoints
$flash = $_SESSION['family_flash'] ?? null;
$flashError = $_SESSION['family_flash_error'] ?? null;
unset($_SESSION['family_flash'], $_SESSION['family_flash_error']);

header_html($family['family_name'] . ' family');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <h2><?=h($family['family_name'])?> family <?=family_status_html($family['status'])?></h2>
  <a class="btn-cta" href="/admin/family_assign_schedule.php?id=<?=$familyId?>">Assign Schedule</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card-grid">
  <div class="card">
    <h3>Parent / Guardian</h3>
    <div><?=person_chip_html($family['parent_first_name'] ?? '', $family['parent_last_name'] ?? '', 'No parent on file')?></div>
    <div class="small" style="margin-top:6px;">
      <?=h($family['parent_email'] ?? '')?><br>
      Cell: <?=h($family['parent_cell_phone'] ?? '—')?> · Home: <?=h($family['parent_home_phone'] ?? '—')?><br>
      Prefers: <?=h($family['preferred_contact_method'] ?? '—')?><br>
      <?=h(trim(($family['address_street_1'] ?? '') . ' ' . ($family['address_street_2'] ?? '')))?>
      <?=h(trim(($family['address_city'] ?? '') . ', ' . ($family['address_state'] ?? '') . ' ' . ($family['address_zip'] ?? '')))?><br>
      Emergency: <?=h($family['emergency_contact_name'] ?? '—')?> <?=h($family['emergency_contact_phone'] ?? '')?><br>
      <?php if (!empty($family['medical_notes'])): ?>Medical: <?=h($family['medical_notes'])?><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3>What they asked for</h3>
    <?php if ($submission): ?>
      <div class="small">
        Path: <strong><?=$submission['path'] === 'talk_first' ? 'Wanted to talk first' : 'Ready to enroll'?></strong><br>
        Days: <?=h($submission['preferred_days'] ?: '—')?> · Times: <?=h($submission['time_window'] ?: '—')?><br>
        Location: <?=h($submission['location_name'] ?? 'No preference')?><br>
        Teacher: <?=h($submission['teacher_gender_pref'] === 'none' ? 'No preference' : 'prefers ' . $submission['teacher_gender_pref'])?><br>
        <?php if (!empty($submission['constraints_text'])): ?>Notes: <?=h($submission['constraints_text'])?><br><?php endif; ?>
        Heard about us: <?=h($submission['how_heard'] ?: '—')?><br>
        Consents: terms <?=$submission['consent_terms'] ? '✓' : '✗'?>,
        liability <?=$submission['consent_liability'] ? '✓' : '✗'?>,
        photo <?=$submission['consent_photo_release'] ? '✓' : '✗'?>
      </div>
    <?php else: ?>
      <p class="small">No registration submission on file (family created manually).</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Status</h3>
    <form method="post" action="/admin/family_status_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="family_id" value="<?=$familyId?>">
      <label>Family status
        <select name="status">
          <?php foreach (FamilyManagement::STATUSES as $status): ?>
          <option value="<?=$status?>"<?=$status === $family['status'] ? ' selected' : ''?>><?=h(FamilyManagement::STATUS_LABELS[$status])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="button">Update Status</button>
    </form>
    <?php if ($lessons): ?>
      <form method="post" action="/admin/family_send_schedule_eval.php" style="margin-top:10px;">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="family_id" value="<?=$familyId?>">
        <button type="submit" class="button" data-confirm="Email this family their schedule with an enrollment link?">Send "Great News" Schedule Email</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<h3>Students</h3>
<div class="card-grid">
  <?php foreach ($family['students'] as $student): ?>
  <div class="card">
    <h3><?=h($student['first_name'] . ' ' . $student['last_name'])?></h3>
    <div class="card-sub"><?=h(implode(', ', $student['instruments'] ?: ['No instrument chosen']))?></div>
    <div class="small">
      <?php if (!empty($student['date_of_birth'])): ?>Born <?=h(date('M j, Y', strtotime($student['date_of_birth'])))?><br><?php endif; ?>
      Experience: <?=h($student['experience_level'] ?? '—')?><br>
      <?php if (!empty($student['school_name'])): ?><?=h($student['school_name'])?> (grade <?=h($student['grade'] ?? '?')?>)<?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<h3>Upcoming schedule</h3>
<?php if (!$lessons): ?>
  <p class="small">No lessons assigned yet — use <strong>Assign Schedule</strong> above.</p>
<?php else: ?>
  <div class="card">
    <?php foreach ($lessons as $lesson): ?>
    <div class="lesson-row">
      <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
      <span><?=h(LessonManagement::lessonSummaryLine($lesson))?></span>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3>Internal notes</h3>
<div class="card">
  <form method="post" action="/admin/family_note_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="family_id" value="<?=$familyId?>">
    <label>Add a note (visible to admins only)
      <textarea name="body" rows="2" placeholder="Spoke with mom Maria, very interested…"></textarea>
    </label>
    <button type="submit" class="button">Add Note</button>
  </form>

  <?php foreach ($notes as $note): ?>
  <div style="border-top:1px solid var(--color-border); padding:8px 0;">
    <div class="small"><?=h(trim(($note['author_first_name'] ?? '') . ' ' . ($note['author_last_name'] ?? '')) ?: 'Unknown')?>
      · <?=h(date('M j, Y g:i A', strtotime($note['created_at'])))?></div>
    <div><?=nl2br(h($note['body']))?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
