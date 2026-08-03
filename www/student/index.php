<?php
// Student home (docs/app_spec.md): My Schedule (upcoming lessons interleaved
// with the semester's holiday weeks on their lesson weekday), Teacher Notes
// newest first, My Materials. Three cards, phone-sized.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$semester = SemesterManagement::resolveDefaultSemester();
$semesterId = $semester ? (int)$semester['id'] : null;

// Upcoming lessons + the semester's inactive dates (holidays) on the same
// weekday as the student's reservations, merged chronologically.
$rows = [];
foreach (LessonManagement::upcomingLessonsForStudent((int)$me['id'], date('Y-m-d')) as $lesson) {
    $rows[] = ['type' => 'lesson', 'date' => date('Y-m-d', strtotime($lesson['start_datetime'])), 'lesson' => $lesson];
}
if ($semesterId !== null) {
    foreach (ReservationManagement::reservationsForStudent((int)$me['id'], $semesterId) as $reservation) {
        $holidays = SemesterManagement::inactiveDatesForLocationWeekday(
            $semesterId, (int)$reservation['location_id'], (int)$reservation['day_of_week']
        );
        foreach ($holidays as $holiday) {
            if ($holiday['date'] >= date('Y-m-d')) {
                $rows[] = ['type' => 'holiday', 'date' => $holiday['date'],
                    'title' => (string)($holiday['title'] ?: 'No lessons'),
                    'location' => (string)$reservation['location_name']];
            }
        }
    }
}
usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

$pastLessons = LessonManagement::recentLessonsForStudent((int)$me['id'], date('Y-m-d'), 4);
$notes = NotesManagement::recentLessonNotesForStudent((int)$me['id']);
$resources = $semesterId !== null
    ? ResourceManagement::resourcesForStudentInSemester((int)$me['id'], $semesterId)
    : [];

header_html('My Schedule');
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <h2 style="margin:0;">Hi, <?=h($me['preferred_name'] ?: $me['first_name'])?>! 🎵</h2>
  <a href="/student/billing.php" class="button">Billing</a>
</div>

<h3>My Schedule</h3>
<div class="card">
  <?php if (!$rows): ?><p class="small">No upcoming lessons scheduled.</p><?php endif; ?>
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
<h3>Recent Lessons</h3>
<div class="card">
  <p class="small">Open a lesson to read its notes and materials — or to leave a note for your teacher.</p>
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

<h3>Notes</h3>
<div class="card">
  <?php if (!$notes): ?><p class="small">No notes yet — they appear after your lessons.</p><?php endif; ?>
  <?php foreach ($notes as $note): ?>
  <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
    <div class="small"><?=h(date('M j, Y', strtotime($note['start_datetime'])))?>
      · <?=h(trim($note['author_first_name'] . ' ' . $note['author_last_name']))?></div>
    <div><?=nl2br(h($note['body']))?></div>
  </div>
  <?php endforeach; ?>
</div>

<h3>My Materials</h3>
<div class="card">
  <?php if (!$resources): ?><p class="small">Nothing shared yet.</p><?php endif; ?>
  <?php foreach (array_slice($resources, 0, 8) as $resource): ?>
  <div class="lesson-row">
    <?php if ($resource['resource_type'] === 'link'): ?>
      <span><a href="<?=h($resource['url'])?>" target="_blank" rel="noopener">🔗 <?=h($resource['title'])?></a></span>
    <?php else: ?>
      <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
    <?php endif; ?>
    <span class="small"><?=h(date('M j', strtotime((string)$resource['lesson_datetime'])))?></span>
  </div>
  <?php endforeach; ?>
  <?php if (count($resources) > 8): ?>
    <p class="small" style="margin-bottom:0;"><a href="/student/materials.php">All materials</a></p>
  <?php endif; ?>
</div>

<?php LessonDetailUIManager::renderModal(); ?>
<?php footer_html(); ?>
