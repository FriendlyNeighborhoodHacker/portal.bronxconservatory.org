<?php
// Lesson detail (admin): who/when/where, substitute teacher, group roster
// with attendance, teacher note, and materials.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
Application::init();
require_admin();

$lessonId = (int)($_GET['id'] ?? 0);
$lesson = LessonManagement::getLesson($lessonId);
if (!$lesson) {
    http_response_code(404);
    die('Lesson not found');
}

$teachers = StudentTeacherManagement::listTeachers();
$resources = ResourceManagement::resourcesForLesson($lessonId);
$note = NotesManagement::lessonNoteFor($lessonId, (int)$lesson['teacher_user_id']);

$flash = $_SESSION['lesson_flash'] ?? null;
$flashError = $_SESSION['lesson_flash_error'] ?? null;
unset($_SESSION['lesson_flash'], $_SESSION['lesson_flash_error']);

header_html(lesson_name_label($lesson));
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <h2><?=h(lesson_name_label($lesson))?><?=$lesson['status'] === 'cancelled' ? ' (cancelled)' : ''?></h2>
  <a class="button" href="/admin/lesson_edit.php?id=<?=$lessonId?>">Edit Lesson</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card-grid">
  <div class="card">
    <h3>When &amp; where</h3>
    <div><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></div>
    <div style="margin-top:6px;"><?=lesson_place_html($lesson)?></div>
    <div class="small" style="margin-top:6px;">Status: <?=h($lesson['status'])?></div>
  </div>

  <div class="card">
    <h3>Teacher</h3>
    <div><?=person_chip_html($lesson['teacher_first_name'], $lesson['teacher_last_name'])?></div>
    <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
      <div class="small" style="margin-top:6px;">Substitute:
        <?=person_chip_html($lesson['sub_first_name'], $lesson['sub_last_name'])?></div>
    <?php endif; ?>
    <form method="post" action="/admin/lesson_substitute_eval.php" class="stack" style="margin-top:10px;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="lesson_id" value="<?=$lessonId?>">
      <label>Substitute teacher
        <select name="substitute_teacher_user_id">
          <option value="">— none —</option>
          <?php foreach ($teachers as $t): ?>
          <option value="<?=(int)$t['id']?>"<?=(int)($lesson['substitute_teacher_user_id'] ?? 0) === (int)$t['id'] ? ' selected' : ''?>><?=h($t['first_name'] . ' ' . $t['last_name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="button">Save Substitute</button>
    </form>
  </div>

  <div class="card">
    <h3><?=$lesson['lesson_type'] === 'group' ? 'Roster & attendance' : 'Student & attendance'?></h3>
    <?php if ($lesson['lesson_type'] === 'individual'): ?>
      <div><?=person_chip_html($lesson['student_first_name'] ?? '', $lesson['student_last_name'] ?? '', 'No student')?></div>
      <div class="small" style="margin-top:6px;">Attended:
        <?=$lesson['attended'] === null ? 'not marked' : ($lesson['attended'] ? 'Yes' : 'No')?></div>
    <?php else: ?>
      <?php foreach ($lesson['group_students'] ?? [] as $gs): ?>
        <div class="small"><?=h($gs['first_name'] . ' ' . $gs['last_name'])?> —
          <?=$gs['attended'] === null ? 'not marked' : ($gs['attended'] ? 'attended' : 'absent')?></div>
      <?php endforeach; ?>
      <?php if (empty($lesson['group_students'])): ?><p class="small">Empty roster — edit the lesson to add students.</p><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<h3>Teacher's lesson note</h3>
<div class="card">
  <?php if ($note && trim($note['body']) !== ''): ?>
    <div><?=nl2br(h($note['body']))?></div>
    <div class="small" style="margin-top:6px;">Updated <?=h(date('M j, Y g:i A', strtotime($note['updated_at'])))?></div>
  <?php else: ?>
    <p class="small">No note yet — the teacher logs it from their dashboard.</p>
  <?php endif; ?>
</div>

<h3>Materials</h3>
<div class="card">
  <?php foreach ($resources as $resource): ?>
    <div class="lesson-row">
      <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a></span>
      <span class="small"><?=h($resource['original_filename'])?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$resources): ?><p class="small">No materials attached.</p><?php endif; ?>
</div>

<?php footer_html(); ?>
