<?php
// A child's detail for their parent: upcoming schedule, recent teacher
// notes, and materials.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

$me = current_user();
$childId = (int)($_GET['id'] ?? 0);
if (!StudentTeacherManagement::isParentOf((int)$me['id'], $childId) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Not your student');
}
$child = UserManagement::findById($childId);
if (!$child) {
    http_response_code(404);
    die('Student not found');
}

$lessons = LessonManagement::upcomingLessonsForStudent($childId, date('Y-m-d'));
$notes = NotesManagement::recentLessonNotesForStudent($childId);
$resources = ResourceManagement::resourcesForStudent($childId);

header_html($child['first_name'] . "'s lessons");
?>

<h2><?=h($child['first_name'] . ' ' . $child['last_name'])?></h2>

<h3>Upcoming schedule</h3>
<div class="card">
  <?php if (!$lessons): ?>
    <p class="small">No upcoming lessons. Questions? Call
      <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
  <?php endif; ?>
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

<h3>Recent teacher notes</h3>
<div class="card">
  <?php if (!$notes): ?><p class="small">No notes yet — they appear after lessons.</p><?php endif; ?>
  <?php foreach ($notes as $note): ?>
  <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
    <div class="small"><?=h(date('M j, Y', strtotime($note['start_datetime'])))?>
      · <?=h($note['instrument_name'] ?? '')?>
      · <?=h(trim($note['teacher_first_name'] . ' ' . $note['teacher_last_name']))?></div>
    <div><?=nl2br(h($note['body']))?></div>
  </div>
  <?php endforeach; ?>
</div>

<h3>Materials</h3>
<div class="card">
  <?php if (!$resources): ?><p class="small">No materials shared yet.</p><?php endif; ?>
  <?php foreach ($resources as $resource): ?>
  <div class="lesson-row">
    <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
    <span class="small"><?=h($resource['original_filename'])?>
      · <?=h(date('M j', strtotime($resource['created_at'])))?></span>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
