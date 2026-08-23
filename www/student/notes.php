<?php
// The "See all notes" page linked from the student home: every note and
// material from every one of the student's classes as one chronological
// chat — previous lessons collapsed at the top, the upcoming lesson's notes
// front and center.
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
// lesson before it happens, and that note belongs here too.
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
// One continuous chat, chronological like a message history: older classes
// above, the nearest upcoming class's notes on top of what is visible.
// Notes from previous lessons start collapsed behind a "View notes from
// previous lessons" button at the top, so the first thing a student sees is
// the most relevant thing — what has been written about the lesson coming
// up. Classes with nothing written on them don't appear at all — this page
// is the notes, not the schedule.
$previousBlocks = [];
$relevantBlocks = [];
foreach (array_reverse($allLessons) as $lesson) { // oldest class first
    // The batch queries return newest-first; a conversation reads oldest-first.
    $notes = array_reverse($notesByLesson[(int)$lesson['id']] ?? []);
    $resources = array_reverse($resourcesByLesson[(int)$lesson['id']] ?? []);
    if (!$notes && !$resources) {
        continue;
    }
    $cancelled = LessonManagement::isCancelled($lesson);
    $upcoming = strtotime($lesson['start_datetime']) > time();
    $block = '<div class="small" style="text-align:center;color:var(--color-text-muted);margin:18px 0 8px;">'
        . h(date('l, F j', strtotime($lesson['start_datetime'])))
        . ($cancelled ? ' · cancelled' : ($upcoming ? ' · upcoming' : ''))
        . '</div>'
        . '<div class="lesson-notes">' . LessonDetailUIManager::threadBubblesHtml($notes, $resources) . '</div>';
    if ($upcoming) {
        $relevantBlocks[] = $block;
    } else {
        $previousBlocks[] = $block;
    }
}
// Nothing coming up: the most recent past class is the most relevant thing
// there is, so it stays visible and only the rest collapses.
if (!$relevantBlocks && $previousBlocks) {
    $relevantBlocks[] = array_pop($previousBlocks);
}
?>
<div class="card">
<?php if ($previousBlocks): ?>
  <div class="actions" style="justify-content:center;">
    <button type="button" class="button" id="showPreviousNotes">View notes from previous lessons</button>
  </div>
  <div id="previousNotes" hidden>
    <?=implode('', $previousBlocks)?>
  </div>
<?php endif; ?>
<?=implode('', $relevantBlocks)?>
<?php if (!$relevantBlocks): ?>
  <p class="small" style="margin:0;">No notes yet — what you and your teachers write about your lessons will appear here.</p>
<?php endif; ?>
</div>

<?php if ($previousBlocks): ?>
<script>
document.getElementById('showPreviousNotes').addEventListener('click', function () {
  document.getElementById('previousNotes').removeAttribute('hidden');
  this.closest('.actions').remove();
});
</script>
<?php endif; ?>

<?php footer_html(); ?>
