<?php
// Edit a lesson. Evaluates to lesson_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/lesson_form_fields.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

$lessonId = (int)($_GET['id'] ?? 0);
$lesson = LessonManagement::getLesson($lessonId);
if (!$lesson) {
    http_response_code(404);
    die('Lesson not found');
}

$error = $_SESSION['lesson_flash_error'] ?? null;
$old = $_SESSION['lesson_old'] ?? [];
unset($_SESSION['lesson_flash_error'], $_SESSION['lesson_old']);

$values = $old ?: $lesson;
if (!$old && $lesson['lesson_type'] === 'group') {
    $values['student_user_ids'] = array_map(fn($s) => (int)$s['student_user_id'], $lesson['group_students'] ?? []);
}

header_html('Edit Lesson');
?>

<h2>Edit Lesson</h2>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/lesson_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$lessonId?>">
    <?php
    if (!isset($values['status'])) {
        $values['status'] = 'scheduled';
    }
    render_lesson_form_fields($values);
    ?>
    <div class="actions">
      <button type="submit" class="primary">Save Lesson</button>
      <a class="button" href="/admin/lesson.php?id=<?=$lessonId?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
