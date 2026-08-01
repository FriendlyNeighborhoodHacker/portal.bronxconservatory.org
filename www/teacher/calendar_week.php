<?php
// Teacher Calendar — weekly view: that week's lessons in chronological
// order; each row links to the lesson page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('teacher', $roles, true) && !in_array('admin', $roles, true)) {
    http_response_code(403);
    die('Teachers only');
}

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$anchorTs = strtotime($date);
$weekStartTs = strtotime('-' . date('w', $anchorTs) . ' days', $anchorTs);
$weekStart = date('Y-m-d', $weekStartTs);
$weekEnd = date('Y-m-d', strtotime('+6 days', $weekStartTs));

$lessons = LessonManagement::lessonsBetweenForTeacher((int)$me['id'], $weekStart, $weekEnd);

header_html('My Week');
?>

<div class="page-head">
  <h2>Week of <?=h(date('M j', $weekStartTs))?>–<?=h(date('M j, Y', strtotime($weekEnd)))?></h2>
  <span class="actions">
    <a class="button" href="/teacher/calendar_week.php?date=<?=h(date('Y-m-d', strtotime('-7 days', $weekStartTs)))?>">&larr; Previous</a>
    <a class="button" href="/teacher/calendar_week.php?date=<?=h(date('Y-m-d', strtotime('+7 days', $weekStartTs)))?>">Next &rarr;</a>
  </span>
</div>

<div class="card">
  <?php if (!$lessons): ?><p class="small">No lessons this week.</p><?php endif; ?>
  <?php foreach ($lessons as $lesson): ?>
  <?php $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0; ?>
  <div class="lesson-row<?=$missed ? ' lesson-cancelled' : ''?>">
    <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
    <span><a href="/teacher/lesson.php?id=<?=(int)$lesson['id']?>">
      <strong><?=h(trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']))?></strong></a></span>
    <span><?=h($lesson['location_name'])?></span>
    <?php if ($missed): ?><span class="small">missed</span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
