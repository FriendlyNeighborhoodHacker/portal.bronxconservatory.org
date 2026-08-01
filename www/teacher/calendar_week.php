<?php
// Teacher Calendar — weekly view: that week's lessons and hold blocks in
// chronological order; each lesson row links to the lesson page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
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

// Lessons and hold blocks interleave into one chronological list.
$entries = [];
foreach (LessonManagement::lessonsBetweenForTeacher((int)$me['id'], $weekStart, $weekEnd) as $lesson) {
    $lesson['kind'] = 'lesson';
    $entries[] = $lesson;
}
foreach (HoldBlockManagement::holdBlocksBetweenForTeacher((int)$me['id'], $weekStart, $weekEnd) as $hold) {
    $hold['kind'] = 'hold';
    $entries[] = $hold;
}
usort($entries, fn(array $a, array $b) => strcmp((string)$a['start_datetime'], (string)$b['start_datetime']));

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
  <?php if (!$entries): ?><p class="small">Nothing scheduled this week.</p><?php endif; ?>
  <?php foreach ($entries as $entry): ?>

  <?php if ($entry['kind'] === 'hold'): ?>
  <div class="lesson-row lesson-hold">
    <span class="lesson-row-time"><?=lesson_time_html($entry['start_datetime'], (int)$entry['duration_minutes'])?></span>
    <span><strong><?=h((string)$entry['effective_title'])?></strong></span>
    <span><?=h($entry['location_name'])?></span>
    <span class="small">held</span>
  </div>

  <?php else: ?>
  <?php
    $missed = $entry['attended'] !== null && (int)$entry['attended'] === 0;
    // A week that was moved off the standing slot, and whose lesson this
    // really is — this list includes weeks you are covering for someone.
    $notes = array_filter([
        LessonManagement::isTimeMoved($entry) ? 'Time moved' : '',
        LessonManagement::substituteNote($entry),
    ]);
  ?>
  <div class="lesson-row<?=$missed ? ' lesson-cancelled' : ''?>">
    <span class="lesson-row-time"><?=lesson_time_html($entry['start_datetime'], (int)$entry['duration_minutes'])?></span>
    <span><a href="/teacher/lesson.php?id=<?=(int)$entry['id']?>">
      <strong><?=h(trim(($entry['student_preferred_name'] ?: $entry['student_first_name']) . ' ' . $entry['student_last_name']))?></strong></a></span>
    <span><?=h($entry['location_name'])?></span>
    <?php if ($missed): ?><span class="small">missed</span><?php endif; ?>
    <?php if ($notes): ?><span class="small"><?=h(implode(' · ', $notes))?></span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
