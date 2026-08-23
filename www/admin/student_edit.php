<?php
// Edit Profile for a student: the basic-information fields (with demographic
// as one more field) and the soft delete. Everything else about the student —
// parents, reservations, charges, notes, photo, instruments — lives on the
// student page (student.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_confirm_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/students.php');
    exit;
}

$demographic = StudentTeacherManagement::demographicForStudent(UserContext::getLoggedInUserContext(), $userId);

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error']);

$name = trim($user['first_name'] . ' ' . $user['last_name']);
header_html('Edit ' . $name);
?>

<div class="page-head">
  <h2>Edit Student — <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?></h2>
  <a class="button" href="/admin/student.php?id=<?=$userId?>">Back to <?=h($user['first_name'])?></a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/student_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <?php person_basic_info_fields_html($user); ?>
    <h3>Other</h3>
    <div class="grid-2">
      <label>Demographic
        <select name="demographic">
          <option value="">Not recorded</option>
          <?php foreach (StudentTeacherManagement::DEMOGRAPHIC_LABELS as $code => $label): ?>
            <option value="<?=h($code)?>" <?=$demographic === $code ? 'selected' : ''?>>
              <?=h($code . ' — ' . $label)?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="small">For the conservatory's own reporting. Shown on admin screens only —
          never to the family, the student, or the teacher.</span>
      </label>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Save Student</button>
    </div>
  </form>
</div>

<div class="card">
  <button type="button" class="button danger" data-modal-open="deleteStudentModal">Delete this student</button>
</div>

<?php render_confirm_modal(
    'deleteStudentModal',
    'Delete ' . $name . '?',
    '<p>The student is flagged as deleted (nothing is erased): they disappear from lists and can no longer sign in.</p>',
    '/admin/student_delete_eval.php',
    ['id' => $userId]
); ?>

<?php footer_html(); ?>
