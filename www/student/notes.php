<?php
// Notes from every one of the student's classes — upcoming and past, latest
// class first — the "See all notes" page linked from the student home. Each
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

// Every class, future and past alike — a parent may write on next week's
// lesson before it happens, and that note belongs here too. Latest first,
// so upcoming classes lead and history trails off below.
$allLessons = LessonManagement::recentLessonsForStudent((int)$me['id'], '9999-12-31', 100);
$notesByLesson = [];
foreach (NotesManagement::notesForLessons(array_column($allLessons, 'id')) as $note) {
    $notesByLesson[(int)$note['lesson_id']][] = $note;
}
$resourcesByLesson = [];
foreach (ResourceManagement::resourcesForLessons(array_column($allLessons, 'id')) as $resource) {
    $resourcesByLesson[(int)$resource['lesson_id']][] = $resource;
}

header_html('Notes from All Classes');
?>

<h2>Notes from All Classes</h2>
<p class="small"><a href="/student/">&larr; Back to My Schedule</a></p>

<?php
// One continuous chat: every note and material the student has, in class
// order (latest class first), each class marked only by a slim date
// separator the way messaging apps mark days. Classes with nothing written
// on them don't appear at all — this page is the notes, not the schedule.
$anyBubbles = false;
?>
<div class="card">
<?php foreach ($allLessons as $lesson): ?>
<?php
    // The batch queries return newest-first; a conversation reads oldest-first.
    $notes = array_reverse($notesByLesson[(int)$lesson['id']] ?? []);
    $resources = array_reverse($resourcesByLesson[(int)$lesson['id']] ?? []);
    if (!$notes && !$resources) {
        continue;
    }
    $anyBubbles = true;
    $cancelled = LessonManagement::isCancelled($lesson);
    $upcoming = strtotime($lesson['start_datetime']) > time();
?>
  <div class="small" style="text-align:center;color:var(--color-text-muted);margin:18px 0 8px;">
    <?=h(date('l, F j', strtotime($lesson['start_datetime'])))?><?=$cancelled ? ' · cancelled' : ($upcoming ? ' · upcoming' : '')?>
  </div>
  <div class="lesson-notes">
    <?=LessonDetailUIManager::threadBubblesHtml($notes, $resources)?>
  </div>
<?php endforeach; ?>
<?php if (!$anyBubbles): ?>
  <p class="small" style="margin:0;">No notes yet — what you and your teachers write about your lessons will appear here.</p>
<?php endif; ?>
</div>

<?php footer_html(); ?>
