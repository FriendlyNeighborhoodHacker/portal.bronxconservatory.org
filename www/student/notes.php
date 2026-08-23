<?php
// Notes from every class the student has had, newest class first — the
// "See notes from all classes." page linked from the student home. Each
// class shows its whole note thread; the Notes & Materials button opens the
// lesson modal, where the student can add a note of their own.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
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

// Every class that has happened, today's included (recentLessonsForStudent
// cuts on the date, so ask up to tomorrow and drop what is still ahead).
$pastLessons = array_values(array_filter(
    LessonManagement::recentLessonsForStudent((int)$me['id'], date('Y-m-d', strtotime('+1 day')), 100),
    fn($lesson) => strtotime($lesson['start_datetime']) <= time()
));
$notesByLesson = [];
foreach (NotesManagement::notesForLessons(array_column($pastLessons, 'id')) as $note) {
    $notesByLesson[(int)$note['lesson_id']][] = $note;
}
$resourcesByLesson = [];
foreach (ResourceManagement::resourcesForLessons(array_column($pastLessons, 'id')) as $resource) {
    $resourcesByLesson[(int)$resource['lesson_id']][] = $resource;
}

header_html('Notes from All Classes');
?>

<h2>Notes from All Classes</h2>
<p class="small"><a href="/student/">&larr; Back to My Schedule</a></p>

<?php if (!$pastLessons): ?>
<div class="card">
  <p class="small" style="margin:0;">No classes yet — notes from your lessons will appear here after your first one.</p>
</div>
<?php endif; ?>

<?php foreach ($pastLessons as $lesson): ?>
<?php
    $cancelled = LessonManagement::isCancelled($lesson);
    // The batch queries return newest-first; a conversation reads oldest-first.
    $notes = array_reverse($notesByLesson[(int)$lesson['id']] ?? []);
    $resources = array_reverse($resourcesByLesson[(int)$lesson['id']] ?? []);
?>
<div class="card"<?=$cancelled ? ' style="opacity:0.7;"' : ''?>>
  <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="font-size:16px;font-weight:500;">
        <?=h(date('l F j, Y', strtotime($lesson['start_datetime'])))?><?=$cancelled ? ' · cancelled' : ''?>
      </div>
      <div class="small" style="color:var(--color-text-muted);">
        <?=h(date('g:i A', strtotime($lesson['start_datetime'])))?> ·
        <?=h($lesson['location_name'] ?? '')?> ·
        Teacher: <?=h(trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
          . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])))?>
      </div>
    </div>
    <a href="#" data-lesson-detail="<?=(int)$lesson['id']?>" class="button notes" style="white-space:nowrap;">Notes &amp; Materials</a>
  </div>
  <div class="lesson-notes" style="margin-top:12px;">
    <?=LessonDetailUIManager::threadBubblesHtml($notes, $resources)?>
  </div>
</div>
<?php endforeach; ?>

<?php LessonDetailUIManager::renderModal(); ?>
<?php footer_html(); ?>
