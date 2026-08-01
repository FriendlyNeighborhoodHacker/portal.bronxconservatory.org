<?php
// Edit my profile — form. Evaluates to profile/edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

$me = current_user();

// One-shot flash + form repopulation from edit_eval.php on error
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

// Repopulate from the rejected post where there is one, otherwise the saved row.
$values = $form ? array_merge($me, $form) : $me;

header_html('Edit Profile');
?>
<h2>Edit Profile</h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/profile/edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php person_basic_info_fields_html($values); ?>
    <?php person_other_fields_html($values); ?>

    <div class="actions">
      <button class="primary" type="submit">Save Profile</button>
      <a class="button" href="/profile/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
