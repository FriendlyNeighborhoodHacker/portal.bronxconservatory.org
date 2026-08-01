<?php
// Lesson detail for teachers: upload materials (recordings, sheet music) or
// share links, and review the note. Attendance and notes live on the
// dashboard rows.
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
$studentName = trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']);

$flash = $_SESSION['teacher_flash'] ?? null;
$flashError = $_SESSION['teacher_flash_error'] ?? null;
unset($_SESSION['teacher_flash'], $_SESSION['teacher_flash_error']);

header_html('Lesson — ' . $studentName);
?>

<h2>Lesson #<?=(int)$lesson['lesson_number']?> — <?=h($studentName)?></h2>
<p class="small">
  <?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?>
  · <?=h($lesson['location_name'])?>
  <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
    · covered by <?=h(trim(($lesson['substitute_first_name'] ?? '') . ' ' . ($lesson['substitute_last_name'] ?? '')))?>
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
      <?php if ($resource['resource_type'] === 'link'): ?>
        <span><a href="<?=h($resource['url'])?>" target="_blank" rel="noopener">🔗 <?=h($resource['title'])?></a></span>
        <span class="small"><?=h($resource['url'])?></span>
      <?php else: ?>
        <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
        <span class="small"><?=h((string)($resource['original_filename'] ?? ''))?></span>
      <?php endif; ?>
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

  <form method="post" action="/teacher/resource_link_add_eval.php" class="stack" style="margin-top:14px;">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="lesson_id" value="<?=$lessonId?>">
    <div class="grid-2">
      <label>Link title
        <input type="text" name="title" placeholder="Practice video">
      </label>
      <label>Link (http/https)
        <input type="url" name="url" placeholder="https://..." required>
      </label>
    </div>
    <button type="submit" class="button">Share Link</button>
  </form>
</div>

<?php footer_html(); ?>
