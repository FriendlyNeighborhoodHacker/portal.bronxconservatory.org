<?php
// All of a student's lesson notes on one page, newest lesson first — linked
// from the notes summary on the student page. Notes are written by teachers
// from their own day view; this page is read-only.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/students.php');
    exit;
}

$notes = NotesManagement::allLessonNotesForStudent($userId);
$name = trim($user['first_name'] . ' ' . $user['last_name']);
header_html('Lesson Notes — ' . $name);
?>

<div class="page-head">
  <h2>Lesson Notes — <?=h($name)?></h2>
  <a class="button" href="/admin/student.php?id=<?=$userId?>">Back to <?=h($user['first_name'])?></a>
</div>

<div class="card">
  <?php if (!$notes): ?>
    <p class="small">No lesson notes yet. Teachers write these from their own day view after a lesson.</p>
  <?php else: ?>
    <?php foreach ($notes as $note): ?>
      <div class="lesson-row" style="align-items:flex-start;">
        <span class="lesson-row-time"><?=h(date('D, M j, Y', strtotime((string)$note['start_datetime'])))?></span>
        <span><?=nl2br(h($note['body']))?></span>
        <span class="small"><?=h(trim($note['author_first_name'] . ' ' . $note['author_last_name']))?></span>
      </div>
    <?php endforeach; ?>
    <p class="small" style="margin-top:8px;"><?=count($notes)?> note<?=count($notes) === 1 ? '' : 's'?>, newest lesson first, across all semesters.</p>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
