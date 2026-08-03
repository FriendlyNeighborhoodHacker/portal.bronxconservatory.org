<?php
// Student home: greeting, announcement (if any), next lesson, other upcoming lessons with a link to the full calendar.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
require_once __DIR__ . '/../lib/LessonUIHelper.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$upcomingLessons = LessonManagement::upcomingLessonsForStudent((int)$me['id'], date('Y-m-d'));
$announcement = Settings::announcement();

header_html('My Schedule');
?>

<h2><?=h('Hi, ' . ($me['preferred_name'] ?: $me['first_name'])) ?>!</h2>

<?php if ($announcement): ?>
<div class="card" style="background-color:var(--color-bg-highlight,#f5f5f5);border-left:4px solid var(--color-primary,#0062DC);">
  <p style="margin:0;"><?=nl2br(h($announcement))?></p>
</div>
<?php endif; ?>

<?php if ($upcomingLessons): ?>
<?php $nextLesson = $upcomingLessons[0]; $cancelled = LessonManagement::isCancelled($nextLesson); ?>
<div class="card<?=$cancelled ? ' style="opacity:0.6;"' : ''?>">
  <h3 style="margin-top:0;">Next Lesson</h3>
  <div style="font-size:18px;font-weight:500;margin-bottom:4px;">
    <?=h(date('l F j', strtotime($nextLesson['start_datetime'])))?>
  </div>
  <div style="font-size:16px;margin-bottom:12px;">
    <?=h(date('g:i', strtotime($nextLesson['start_datetime'])) . ' – ' .
         date('g:i A', strtotime($nextLesson['start_datetime']) + ($nextLesson['duration_minutes'] * 60)))?>
  </div>
  <div style="margin-bottom:4px;">
    <strong><?=h($nextLesson['location_name'])?></strong>
  </div>
  <div style="margin-bottom:12px;">
    Teacher: <?=h(trim(($nextLesson['substitute_first_name'] ?? null ?: $nextLesson['teacher_first_name']) . ' '
      . ($nextLesson['substitute_last_name'] ?? null ?: $nextLesson['teacher_last_name'])))?>
  </div>
  <?php if ($cancelled): ?>
    <div style="color:var(--color-text-secondary);">This lesson has been cancelled.</div>
  <?php else: ?>
    <a href="#" data-lesson-detail="<?=(int)$nextLesson['id']?>" class="button">Notes & Materials</a>
  <?php endif; ?>
</div>

<?php if (count($upcomingLessons) > 1): ?>
<div class="card">
  <h3 style="margin-top:0;">Other Upcoming Lessons</h3>
<div class="card">
  <h3 style="margin-top:0;">Other Upcoming Lessons</h3>
  <?php echo LessonUIHelper::renderOtherUpcomingLessons(array_slice($upcomingLessons, 1), showStudentName: false, includeWrapper: false); ?>
  <p class="small" style="margin-top:12px;margin-bottom:0;"><a href="/student/calendar.php">View full calendar</a></p>
</div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="card">
  <p class="small">No upcoming lessons scheduled.</p>
</div>
<?php endif; ?>

<?php LessonDetailUIManager::renderModal(); ?>
<?php footer_html(); ?>
