<?php
// Edit Parent: photo, basic information, children (+Add Child modal with
// Add New / Link Existing tabs), and soft delete.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_child_modal.php';
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

$children = StudentTeacherManagement::childrenOfParent($userId);
$returnTo = '/admin/parent_edit.php?id=' . $userId;

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
  <h2>Edit Parent — <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?></h2>
  <a class="button" href="/admin/students.php">Back to Students</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?=person_photo_card_html($user, $returnTo)?>

<div class="card">
  <form method="post" action="/admin/parent_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <?php person_basic_info_fields_html($user); ?>
    <div class="actions">
      <button type="submit" class="button primary">Save Parent</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="page-head">
    <h3>Children</h3>
    <button type="button" class="button" data-modal-open="addChildModal">Add Child</button>
  </div>
  <?php if (!$children): ?><p class="small">No children linked yet.</p><?php endif; ?>
  <?php foreach ($children as $child): ?>
    <div class="lesson-row">
      <span>
        <a href="/admin/student_edit.php?id=<?=(int)$child['id']?>">
          <strong><?=h($child['first_name'] . ' ' . $child['last_name'])?></strong></a>
        <div class="small"><?=h(implode(', ', $child['instruments']))?></div>
      </span>
      <form method="post" action="/admin/parent_unlink_eval.php" style="margin-left:auto;"
            onsubmit="return confirm('Unlink this child from <?=h($name)?>?');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="parent_user_id" value="<?=$userId?>">
        <input type="hidden" name="child_user_id" value="<?=(int)$child['id']?>">
        <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
        <button type="submit" class="button">Unlink</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <button type="button" class="button danger" data-modal-open="deleteParentModal">Delete this parent</button>
</div>

<?php render_child_modal($userId); ?>
<?php render_confirm_modal(
    'deleteParentModal',
    'Delete ' . $name . '?',
    '<p>The parent is flagged as deleted (nothing is erased): they disappear from lists and can no longer sign in.</p>',
    '/admin/parent_delete_eval.php',
    ['id' => $userId]
); ?>

<?php footer_html(); ?>
