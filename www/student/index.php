<?php
// Student home (docs/app_spec.md): My Schedule (breaks noted), Teacher Notes
// newest first, My Materials. Three cards, phone-sized.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$lessons = LessonManagement::upcomingLessonsForStudent((int)$me['id'], date('Y-m-d'));
$notes = NotesManagement::recentLessonNotesForStudent((int)$me['id']);
$resources = ResourceManagement::resourcesForStudent((int)$me['id']);

header_html('My Schedule');
?>

<h2>Hi, <?=h($me['preferred_name'] ?: $me['first_name'])?>! 🎵</h2>

<h3>My Schedule</h3>
<div class="card">
  <?php if (!$lessons): ?><p class="small">No upcoming lessons scheduled.</p><?php endif; ?>
  <?php foreach ($lessons as $lesson): ?>
  <div class="lesson-row<?=$lesson['status'] === 'cancelled' ? ' lesson-cancelled' : ''?>">
    <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
    <span><?=h(lesson_name_label($lesson))?>
      with <?=h(trim(($lesson['sub_first_name'] ?? $lesson['teacher_first_name']) . ' ' . ($lesson['sub_last_name'] ?? $lesson['teacher_last_name'])))?></span>
    <span><?=lesson_place_html($lesson)?></span>
    <?php if ($lesson['status'] === 'cancelled'): ?><span class="small">no lesson (break)</span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<h3>Teacher Notes</h3>
<div class="card">
  <?php if (!$notes): ?><p class="small">No notes yet — they appear after your lessons.</p><?php endif; ?>
  <?php foreach ($notes as $note): ?>
  <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
    <div class="small"><?=h(date('M j, Y', strtotime($note['start_datetime'])))?>
      · <?=h(trim($note['teacher_first_name'] . ' ' . $note['teacher_last_name']))?></div>
    <div><?=nl2br(h($note['body']))?></div>
  </div>
  <?php endforeach; ?>
</div>

<h3>My Materials</h3>
<div class="card">
  <?php if (!$resources): ?><p class="small">Nothing shared yet.</p><?php endif; ?>
  <?php foreach ($resources as $resource): ?>
  <div class="lesson-row">
    <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
    <span class="small"><?=h($resource['original_filename'])?></span>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
