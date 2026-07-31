<?php
// Add a recurring weekly lesson template. Evaluates to recurring_add_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/lesson_form_fields.php';
Application::init();
require_admin();

$error = $_SESSION['recurring_flash_error'] ?? null;
$old = $_SESSION['recurring_old'] ?? [];
unset($_SESSION['recurring_flash_error'], $_SESSION['recurring_old']);

header_html('Add Recurring Lesson');
?>

<h2>Add Recurring Lesson</h2>
<p class="small">A weekly template. After creating it, use <strong>Generate</strong> on the
Recurring Lessons page to put its occurrences on the calendar.</p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/recurring_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php render_lesson_form_fields($old); ?>
    <div class="grid-2">
      <label>First date (sets the weekday)
        <input type="date" name="recurring_start_date" required value="<?=h($old['recurring_start_date'] ?? '')?>">
      </label>
      <label>Last date (optional)
        <input type="date" name="recurring_end_date" value="<?=h($old['recurring_end_date'] ?? '')?>">
      </label>
    </div>
    <div class="actions">
      <button type="submit" class="primary">Create Recurring Lesson</button>
      <a class="button" href="/admin/recurring_lessons.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
