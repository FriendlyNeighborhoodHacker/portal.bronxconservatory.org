<?php
// Lesson detail for teachers: the lesson's notes and its materials, the same
// two blocks as the day view — this page is where you land from a calendar
// rather than a second way of doing anything.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
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

$studentName = trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']);

header_html('Lesson — ' . $studentName);
?>

<h2>Lesson #<?=(int)$lesson['lesson_number']?> — <?=h($studentName)?>
  <?php if (LessonManagement::isCancelled($lesson)): ?><span class="badge">Cancelled</span><?php endif; ?></h2>
<p class="small">
  <?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?>
  · <?=h($lesson['location_name'])?>
  <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
    · covered by <?=h(trim(($lesson['substitute_first_name'] ?? '') . ' ' . ($lesson['substitute_last_name'] ?? '')))?>
  <?php endif; ?>
  · <a href="/teacher/index.php?date=<?=h(date('Y-m-d', strtotime($lesson['start_datetime'])))?>">the whole day</a>
</p>
<?php if (LessonManagement::isCancelled($lesson)): ?>
  <p class="small">This lesson was cancelled. Nothing is expected of you for it.</p>
<?php endif; ?>

<div class="card">
  <h3>Notes</h3>
  <?=LessonDetailUIManager::notesBlockHtml($lessonId, true, 'What you covered, what to practice…')?>
</div>

<div class="card">
  <h3>Materials</h3>
  <?=LessonDetailUIManager::resourcesBlockHtml($lessonId, true)?>
</div>

<?php LessonDetailUIManager::renderNotesScript(); ?>
<?php LessonDetailUIManager::renderResourceModal(); ?>
<?php footer_html(); ?>
