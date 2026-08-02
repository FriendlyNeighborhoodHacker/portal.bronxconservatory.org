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

$rows = [];
foreach (LessonManagement::upcomingLessonsForStudent($childId, date('Y-m-d')) as $lesson) {
    $rows[] = ['type' => 'lesson', 'date' => date('Y-m-d', strtotime($lesson['start_datetime'])), 'lesson' => $lesson];
}
if ($semesterId !== null) {
    foreach (ReservationManagement::reservationsForStudent($childId, $semesterId) as $reservation) {
        foreach (SemesterManagement::inactiveDatesForLocationWeekday(
            $semesterId, (int)$reservation['location_id'], (int)$reservation['day_of_week']
        ) as $holiday) {
            if ($holiday['date'] >= date('Y-m-d')) {
                $rows[] = ['type' => 'holiday', 'date' => $holiday['date'],
                    'title' => (string)($holiday['title'] ?: 'No lessons'),
                    'location' => (string)$reservation['location_name']];
            }
        }
    }
}
usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

$pastLessons = LessonManagement::recentLessonsForStudent($childId, date('Y-m-d'), 4);
$notes = NotesManagement::recentLessonNotesForStudent($childId);
$resources = $semesterId !== null
    ? ResourceManagement::resourcesForStudentInSemester($childId, $semesterId)
    : [];

header_html($child['first_name'] . "'s lessons");
?>

<h2><?=h($child['first_name'] . ' ' . $child['last_name'])?></h2>

<h3>Upcoming schedule</h3>
<div class="card">
  <?php if (!$rows): ?>
    <p class="small">No upcoming lessons. Questions? Call
      <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
  <?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <?php if ($row['type'] === 'holiday'): ?>
    <div class="lesson-row lesson-cancelled">
      <span class="lesson-row-time"><?=h(date('D, M j', strtotime($row['date'])))?></span>
      <span>No lesson — <?=h($row['title'])?></span>
      <span class="small"><?=h($row['location'])?></span>
    </div>
    <?php else: $lesson = $row['lesson']; $cancelled = LessonManagement::isCancelled($lesson); ?>
    <div class="lesson-row<?=$cancelled ? ' lesson-cancelled' : ''?>">
      <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
      <span>Lesson with <?=h(trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
        . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])))?></span>
      <span><?=h($lesson['location_name'])?></span>
      <?php if ($cancelled): ?><span class="badge">Cancelled</span><?php endif; ?>
      <span class="small"><a href="#" data-lesson-detail="<?=(int)$lesson['id']?>">Notes &amp; materials</a></span>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

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
