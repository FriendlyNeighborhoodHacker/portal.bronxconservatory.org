<?php
// Edit one of my children's profiles: photo and the same person field set
// the admin screens use. Evaluates to parent/child_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_login();

$me = current_user();
$childId = (int)($_GET['id'] ?? 0);
if (!StudentTeacherManagement::isParentOf((int)$me['id'], $childId) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Not your student');
}
$child = UserManagement::findById($childId);
if (!$child) {
    http_response_code(404);
    die('Student not found');
}

// One-shot flash + form repopulation from child_edit_eval.php on error
$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }

$values = $form ? array_merge($child, $form) : $child;
$name = trim($child['first_name'] . ' ' . $child['last_name']);
$returnTo = '/parent/child_edit.php?id=' . $childId;

header_html('Edit ' . $name);
?>

<div class="page-head">
  <h2>Edit <?=h($name)?></h2>
  <a class="button" href="/profile/">Back to My Profile</a>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?=person_photo_card_html($child, $returnTo)?>

<div class="card">
  <form method="post" action="/parent/child_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$childId?>">
    <?php person_basic_info_fields_html($values); ?>
    <?php person_other_fields_html($values); ?>
    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/parent/child.php?id=<?=$childId?>">Schedule &amp; notes</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
