<?php
// A child's detail for their parent: their schedule (holiday weeks noted),
// recent teacher notes, and materials. Every lesson on the page — the ones
// coming up and the ones just gone — opens its notes and materials in a
// modal, where a parent can add a note of their own.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
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

$semester = SemesterManagement::resolveDefaultSemester();
$semesterId = $semester ? (int)$semester['id'] : null;

$upcomingLessons = LessonManagement::upcomingLessonsForStudent($childId, date('Y-m-d'));

$pastLessons = LessonManagement::recentLessonsForStudent($childId, date('Y-m-d'), 4);
$notes = NotesManagement::recentLessonNotesForStudent($childId);
$resources = $semesterId !== null
    ? ResourceManagement::resourcesForStudentInSemester($childId, $semesterId)
    : [];

header_html($child['first_name'] . "'s lessons");
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;"><?=h($child['first_name'] . ' ' . $child['last_name'])?></h2>
  <a href="/parent/child_edit.php?id=<?=(int)$childId?>" class="button">Edit profile</a>
</div>

<?php if ($upcomingLessons): ?>
<?php $nextLesson = $upcomingLessons[0]; $cancelled = LessonManagement::isCancelled($nextLesson); ?>
<div class="card<?=$cancelled ? ' style="opacity:0.6;"' : ''?>">
  <h3 style="margin-top:0;">Next Lesson</h3>
  <div style="font-size:18px;font-weight:500;margin-bottom:4px;">
    <?=h(date('l F j', strtotime($nextLesson['start_datetime'])))?>
  </div>
  <div style="font-size:16px;margin-bottom:12px;">
    <?php
      $start = strtotime($nextLesson['start_datetime']);
      $end = $start + ($nextLesson['duration_minutes'] * 60);
      echo h(date('g:i', $start) . ' – ' . date('g:i A', $end));
    ?>
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
  <?php foreach (array_slice($upcomingLessons, 1, 5) as $lesson): ?>
  <?php $cancelled = LessonManagement::isCancelled($lesson); ?>
  <div style="padding:12px 0;border-bottom:1px solid var(--color-border);<?=$cancelled ? 'opacity:0.6;' : ''?>">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">
      <div>
        <div style="font-weight:500;margin-bottom:4px;">
          <?php
            $start = strtotime($lesson['start_datetime']);
            $end = $start + ((int)$lesson['duration_minutes'] * 60);
            echo h(date('D, M j · g:i', $start) . '–' . date('g:i A', $end));
          ?>
        </div>
        <div style="font-size:14px;color:var(--color-text-secondary);margin-bottom:4px;">
          <?=h($lesson['location_name'])?>
        </div>
        <div style="font-size:14px;">
          <?=h(trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
            . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])))?>
        </div>
      </div>
      <a href="#" data-lesson-detail="<?=(int)$lesson['id']?>" class="button" style="white-space:nowrap;margin-top:4px;">Notes & Materials</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="card">
  <p class="small">No upcoming lessons. Questions? Call
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
</div>
<?php endif; ?>

<?php if ($pastLessons): ?>
<h3>Recent lessons</h3>
<div class="card">
  <p class="small">Open a lesson to read its notes and materials — or to leave a note for the teacher.</p>
  <?php foreach ($pastLessons as $lesson): ?>
  <?php $cancelled = LessonManagement::isCancelled($lesson); ?>
  <div class="lesson-row<?=$cancelled ? ' lesson-cancelled' : ''?>">
    <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
    <span>Lesson with <?=h(trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
      . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])))?></span>
    <span><?=h($lesson['location_name'])?></span>
    <?php if ($cancelled): ?><span class="badge">Cancelled</span><?php endif; ?>
    <span class="small"><a href="#" data-lesson-detail="<?=(int)$lesson['id']?>">Notes &amp; materials</a></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h3>Recent notes</h3>
<div class="card">
  <?php if (!$notes): ?><p class="small">No notes yet — they appear after lessons.</p><?php endif; ?>
  <?php foreach ($notes as $note): ?>
  <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
    <div class="small"><?=h(date('M j, Y', strtotime($note['start_datetime'])))?>
      · <?=h(trim($note['author_first_name'] . ' ' . $note['author_last_name']))?></div>
    <div><?=nl2br(h($note['body']))?></div>
  </div>
  <?php endforeach; ?>
</div>

<h3>Materials</h3>
<div class="card">
  <?php if (!$resources): ?><p class="small">No materials shared yet.</p><?php endif; ?>
  <?php foreach ($resources as $resource): ?>
  <div class="lesson-row">
    <?php if ($resource['resource_type'] === 'link'): ?>
      <span><a href="<?=h($resource['url'])?>" target="_blank" rel="noopener">🔗 <?=h($resource['title'])?></a></span>
    <?php else: ?>
      <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
    <?php endif; ?>
    <span class="small"><?=h(date('M j', strtotime((string)$resource['lesson_datetime'])))?></span>
  </div>
  <?php endforeach; ?>
</div>

<?php LessonDetailUIManager::renderModal(); ?>
<?php footer_html(); ?>
