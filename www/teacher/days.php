<?php
// Teaching Days: which days a teacher works and where, one row each. Lessons
// here are sparse and split across buildings, so this is the view that
// answers "am I at Bronx CC on Saturday?" without reading a whole calendar.
// Each day opens the hour-by-hour view.
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

$days = LessonManagement::upcomingTeachingDaysForTeacher((int)$me['id'], date('Y-m-d'), 60);

header_html('Teaching Days');
?>

<div class="page-head">
  <h2>Teaching Days</h2>
  <span class="actions">
    <a class="button" href="/teacher/calendar.php">Calendar</a>
  </span>
</div>

<div class="card">
  <?php if (!$days): ?>
    <p class="small">Nothing scheduled yet. Days appear here as soon as your lessons are set up.</p>
  <?php endif; ?>
  <?php foreach ($days as $day): ?>
  <?php $date = (string)$day['lesson_date']; ?>
  <div class="lesson-row">
    <span class="lesson-row-time">
      <a href="/teacher/index.php?date=<?=h($date)?>"><strong><?=h(date('D, M j', strtotime($date)))?></strong></a>
      <?php if ($date === date('Y-m-d')): ?><span class="badge">Today</span><?php endif; ?>
    </span>
    <span><?=h((string)$day['location_names'])?></span>
    <span class="small"><?=(int)$day['lesson_count']?> lesson<?=(int)$day['lesson_count'] === 1 ? '' : 's'?>
      · <?=h(date('g:i A', strtotime((string)$day['first_start'])))?>–<?=h(date('g:i A', strtotime((string)$day['last_end'])))?></span>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
