<?php
// Edit Teacher: photo, basic information, instruments, students this
// semester (linked), and soft delete.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_confirm_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/teachers.php');
    exit;
}

$allInstruments = InstrumentCatalog::all();
$teacherInstruments = InstrumentCatalog::namesForTeacher($userId);
$semesterId = Application::adminSelectedSemesterId();
$students = $semesterId !== null
    ? StudentTeacherManagement::studentsForTeacherInSemester($userId, $semesterId)
    : [];
$returnTo = '/admin/teacher_edit.php?id=' . $userId;

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
  <h2>Edit Teacher — <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?></h2>
  <a class="button" href="/admin/teachers.php">Back to Teachers</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?=person_photo_card_html($user, $returnTo)?>

<div class="card">
  <form method="post" action="/admin/teacher_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <?php person_basic_info_fields_html($user); ?>
    <h3>Instruments</h3>
    <div class="choice-grid">
      <?php foreach ($allInstruments as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="instrument_ids[]" value="<?=(int)$instrument['id']?>"
            <?=in_array($instrument['name'], $teacherInstruments, true) ? 'checked' : ''?>>
          <?=h($instrument['name'])?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Save Teacher</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Students This Semester</h3>
  <?php if (!$students): ?><p class="small">No students this semester.</p><?php endif; ?>
  <?php foreach ($students as $student): ?>
    <div class="lesson-row">
      <a href="/admin/student_edit.php?id=<?=(int)$student['id']?>">
        <strong><?=h($student['first_name'] . ' ' . $student['last_name'])?></strong></a>
      <span class="small"><?=h(str_replace('_', ' ', (string)$student['reservation_status']))?></span>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <button type="button" class="button danger" data-modal-open="deleteTeacherModal">Delete this teacher</button>
</div>

<?php render_confirm_modal(
    'deleteTeacherModal',
    'Delete ' . $name . '?',
    '<p>The teacher is flagged as deleted (nothing is erased): they disappear from lists and can no longer sign in.</p>',
    '/admin/teacher_delete_eval.php',
    ['id' => $userId]
); ?>

<?php footer_html(); ?>
