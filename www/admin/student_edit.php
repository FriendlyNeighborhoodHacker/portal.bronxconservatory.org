<?php
// Edit Student: photo, basic information, parents (+Add Parent modal),
// instruments, charges & payments, and soft delete.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_parent_modal.php';
require_once __DIR__ . '/../partials_confirm_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/LedgerUIManager.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/students.php');
    exit;
}

$parents = StudentTeacherManagement::parentsOfStudent($userId);
$allInstruments = InstrumentCatalog::all();
$studentInstruments = InstrumentCatalog::namesForStudent($userId);
$semesterId = Application::adminSelectedSemesterId();
$reservations = ReservationManagement::reservationsForStudent($userId, $semesterId);
$returnTo = '/admin/student_edit.php?id=' . $userId;

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error']);
if (isset($_GET['uploaded'])) { $flash = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $flash = 'Photo removed.'; }
if (isset($_GET['photo_err'])) { $flashError = 'Photo upload failed.'; }

$name = trim($user['first_name'] . ' ' . $user['last_name']);
header_html('Edit ' . $name);
?>

<div class="page-head">
  <h2>Edit Student — <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?></h2>
  <a class="button" href="/admin/students.php">Back to Students</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?=person_photo_card_html($user, $returnTo)?>

<div class="card">
  <form method="post" action="/admin/student_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <?php person_basic_info_fields_html($user); ?>
    <div class="actions">
      <button type="submit" class="button primary">Save Student</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="page-head">
    <h3>Parents</h3>
    <button type="button" class="button" data-modal-open="addParentModal">Add Parent</button>
  </div>
  <?php if (!$parents): ?><p class="small">No parents linked yet.</p><?php endif; ?>
  <?php foreach ($parents as $parent): ?>
    <div class="lesson-row">
      <span>
        <a href="/admin/parent_edit.php?id=<?=(int)$parent['id']?>"><strong><?=h($parent['first_name'] . ' ' . $parent['last_name'])?></strong></a>
        <?php if ($parent['role']): ?><span class="small">(<?=h($parent['role'])?>)</span><?php endif; ?>
        <div class="small"><?=h(trim(($parent['cell_phone'] ?? '') . '  ' . ($parent['email'] ?? '')))?></div>
      </span>
      <form method="post" action="/admin/parent_unlink_eval.php" style="margin-left:auto;"
            onsubmit="return confirm('Unlink this parent from <?=h($name)?>?');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="parent_user_id" value="<?=(int)$parent['id']?>">
        <input type="hidden" name="child_user_id" value="<?=$userId?>">
        <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
        <button type="submit" class="button">Unlink</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>Instruments</h3>
  <form method="post" action="/admin/student_instruments_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <div class="choice-grid">
      <?php foreach ($allInstruments as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="instrument_ids[]" value="<?=(int)$instrument['id']?>"
            <?=in_array($instrument['name'], $studentInstruments, true) ? 'checked' : ''?>>
          <?=h($instrument['name'])?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="actions"><button type="submit" class="button">Save Instruments</button></div>
  </form>
</div>

<?php if ($reservations): ?>
<div class="card">
  <h3>This Semester</h3>
  <?php foreach ($reservations as $reservation): ?>
    <div class="lesson-row">
      <span class="lesson-row-time"><?=h(['SU','MO','TU','WE','TH','FR','SA'][(int)$reservation['day_of_week']] . ' ' . date('g:i a', strtotime((string)$reservation['start_time'])))?></span>
      <span><?=h($reservation['teacher_first_name'] . ' ' . $reservation['teacher_last_name'])?> · <?=h($reservation['location_name'])?></span>
      <span class="small"><?=h(str_replace('_', ' ', (string)$reservation['status']))?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php LedgerUIManager::renderSection($userId, $semesterId, $returnTo); ?>

<div class="card">
  <button type="button" class="button danger" data-modal-open="deleteStudentModal">Delete this student</button>
</div>

<?php render_parent_modal($userId); ?>
<?php render_confirm_modal(
    'deleteStudentModal',
    'Delete ' . $name . '?',
    '<p>The student is flagged as deleted (nothing is erased): they disappear from lists and can no longer sign in.</p>',
    '/admin/student_delete_eval.php',
    ['id' => $userId]
); ?>

<?php footer_html(); ?>
