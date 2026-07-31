<?php
// Add a one-off lesson (individual or group). Evaluates to
// lesson_add_eval.php. Weekly lessons are created under Recurring Lessons.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/lesson_form_fields.php';
Application::init();
require_admin();

$error = $_SESSION['lesson_flash_error'] ?? null;
$old = $_SESSION['lesson_old'] ?? [];
unset($_SESSION['lesson_flash_error'], $_SESSION['lesson_old']);

header_html('Add Lesson');
?>

<h2>Add Lesson</h2>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/lesson_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php render_lesson_form_fields($old); ?>
    <div class="actions">
      <button type="submit" class="primary">Create Lesson</button>
      <a class="button" href="/admin/lessons.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
