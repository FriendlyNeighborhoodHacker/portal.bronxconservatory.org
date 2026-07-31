<?php
// Lesson detail for teachers: upload materials (recordings, sheet music) and
// review the note. Attendance and notes live on the dashboard rows.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
Application::init();
require_login();

$me = current_user();
$lessonId = (int)($_GET['id'] ?? 0);
$lesson = LessonManagement::getLesson($lessonId);
if (!$lesson) {
    http_response_code(404);
    die('Lesson not found');
}
if (empty($me['is_admin']) && !LessonManagement::isEffectiveTeacher((int)$me['id'], $lesson)) {
    http_response_code(403);
    die('This is not your lesson');
}

$resources = ResourceManagement::resourcesForLesson($lessonId);
$note = NotesManagement::lessonNoteFor($lessonId, (int)$me['id']);

$flash = $_SESSION['teacher_flash'] ?? null;
$flashError = $_SESSION['teacher_flash_error'] ?? null;
unset($_SESSION['teacher_flash'], $_SESSION['teacher_flash_error']);

header_html(lesson_name_label($lesson));
?>

<h2><?=h(lesson_name_label($lesson))?></h2>
<p class="small">
  <?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?> · <?=lesson_place_html($lesson)?>
  <?php if ($lesson['lesson_type'] === 'individual'): ?>
    · <?=h(trim(($lesson['student_first_name'] ?? '') . ' ' . ($lesson['student_last_name'] ?? '')))?>
  <?php endif; ?>
</p>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <h3>Your note</h3>
  <?php if ($note && trim($note['body']) !== ''): ?>
    <div><?=nl2br(h($note['body']))?></div>
    <div class="small" style="margin-top:6px;">Log and edit notes from <a href="/teacher/index.php?date=<?=h(date('Y-m-d', strtotime($lesson['start_datetime'])))?>">the day view</a>.</div>
  <?php else: ?>
    <p class="small">No note yet — log one from <a href="/teacher/index.php?date=<?=h(date('Y-m-d', strtotime($lesson['start_datetime'])))?>">the day view</a>.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Materials</h3>
  <?php foreach ($resources as $resource): ?>
    <div class="lesson-row">
      <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
      <span class="small"><?=h($resource['original_filename'])?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$resources): ?><p class="small">Nothing shared for this lesson yet.</p><?php endif; ?>

  <form method="post" action="/teacher/resource_upload_eval.php" enctype="multipart/form-data" class="stack" style="margin-top:10px;">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="lesson_id" value="<?=$lessonId?>">
    <div class="grid-2">
      <label>Title
        <input type="text" name="title" placeholder="Week 3 recording">
      </label>
      <label>File (audio, PDF, image, or video)
        <input type="file" name="resource" required>
      </label>
    </div>
    <button type="submit" class="button">Upload Material</button>
  </form>
</div>

<?php footer_html(); ?>
