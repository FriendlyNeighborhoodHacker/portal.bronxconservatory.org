<?php
// Teacher home: today's lessons in chronological order. Each row shows time,
// student name, location (online lessons get an icon), attendance buttons,
// and a note box that auto-saves as the teacher types. The arrows jump to
// the teacher's previous/next day that actually has lessons.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('teacher', $roles, true) && !in_array('admin', $roles, true)) {
    http_response_code(403);
    die('Teachers only');
}

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$lessons = LessonManagement::lessonsForTeacherOnDate((int)$me['id'], $date);
$prevDay = LessonManagement::previousTeachingDateForTeacher((int)$me['id'], $date);
$nextDay = LessonManagement::nextTeachingDateForTeacher((int)$me['id'], $date);

header_html("Today's Lessons");
?>

<div class="page-head">
  <h2><?=$date === date('Y-m-d') ? "Today's Lessons" : 'Lessons for ' . h(date('D, M j', strtotime($date)))?></h2>
  <span class="actions">
    <?php if ($prevDay): ?>
      <a class="button" href="/teacher/index.php?date=<?=h($prevDay)?>">&larr; <?=h(date('M j', strtotime($prevDay)))?></a>
    <?php endif; ?>
    <form method="get" class="inline">
      <input type="date" name="date" value="<?=h($date)?>" onchange="this.form.submit()">
    </form>
    <?php if ($nextDay): ?>
      <a class="button" href="/teacher/index.php?date=<?=h($nextDay)?>"><?=h(date('M j', strtotime($nextDay)))?> &rarr;</a>
    <?php endif; ?>
  </span>
</div>

<?php if (!$lessons): ?>
  <p class="small">No lessons on this day.
  <?php if ($nextDay): ?>Your next teaching day is
    <a href="/teacher/index.php?date=<?=h($nextDay)?>"><?=h(date('l, M j', strtotime($nextDay)))?></a>.<?php endif; ?>
  </p>
<?php endif; ?>

<?php foreach ($lessons as $lesson): ?>
  <?php
    $note = NotesManagement::lessonNoteFor((int)$lesson['id'], (int)$me['id']);
    $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0;
    // Still listed when cancelled — you planned your day around it.
    $cancelled = LessonManagement::isCancelled($lesson);
    $struck = $cancelled || $missed;
  ?>
  <div class="card" style="margin-bottom:12px;">
    <div class="lesson-row" style="border-bottom:0;">
      <span class="lesson-row-time<?=$struck ? ' lesson-cancelled' : ''?>">
        <?=h(date('g:i A', strtotime($lesson['start_datetime'])))?>
      </span>
      <span class="<?=$struck ? 'lesson-cancelled' : ''?>">
        <strong><a href="/teacher/lesson.php?id=<?=(int)$lesson['id']?>"><?=h(trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']))?></a></strong>
        <span class="small">Lesson #<?=(int)$lesson['lesson_number']?></span>
      </span>
      <span><?=h($lesson['location_name'])?></span>
      <?php if ($cancelled): ?><span class="badge">Cancelled</span><?php endif; ?>
      <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
        <span class="small">covered by <?=h(trim(($lesson['substitute_first_name'] ?? '') . ' ' . ($lesson['substitute_last_name'] ?? '')))?></span>
      <?php endif; ?>
    </div>

    <div id="attendance-<?=(int)$lesson['id']?>-solo">
      <?=teacher_attendance_html((int)$lesson['id'], null, $lesson['attended'])?>
    </div>

    <div class="lesson-note-box" style="margin-top:8px;">
      <textarea data-lesson-id="<?=(int)$lesson['id']?>" class="lesson-note-input"
        placeholder="Log a note for this lesson — what you covered, what to practice…"><?=h($note['body'] ?? '')?></textarea>
      <div id="note-state-<?=(int)$lesson['id']?>"><?=teacher_note_state_html($note)?></div>
    </div>
  </div>
<?php endforeach; ?>

<script>
(function () {
  var csrf = <?=json_encode(csrf_token())?>;

  // Auto-save lesson notes, debounced per textarea.
  document.querySelectorAll('.lesson-note-input').forEach(function (box) {
    var timer = null;
    box.addEventListener('input', function () {
      var stateEl = document.getElementById('note-state-' + box.dataset.lessonId);
      if (stateEl) stateEl.innerHTML = '<span class="note-save-state">Saving…</span>';
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        var body = new URLSearchParams({csrf: csrf, lesson_id: box.dataset.lessonId, body: box.value});
        fetch('/teacher/lesson_note_save.php', {method: 'POST', body: body})
          .then(function (r) { return r.text().then(function (t) { return {ok: r.ok, text: t}; }); })
          .then(function (res) {
            if (stateEl) stateEl.innerHTML = res.ok ? res.text
              : '<span class="error">' + res.text + '</span>';
          })
          .catch(function () {
            if (stateEl) stateEl.innerHTML = '<span class="error">Could not save — check your connection.</span>';
          });
      }, 700);
    });
  });

  // Attendance buttons (event delegation: fragments get re-swapped).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.attendance-btn');
    if (!btn || btn.disabled) return;
    var lessonId = btn.dataset.lessonId;
    var target = document.getElementById('attendance-' + lessonId + '-solo');
    var body = new URLSearchParams({csrf: csrf, lesson_id: lessonId, attended: btn.dataset.attended});
    fetch('/teacher/lesson_attendance_save.php', {method: 'POST', body: body})
      .then(function (r) { return r.text().then(function (t) { return {ok: r.ok, text: t}; }); })
      .then(function (res) {
        if (target) target.innerHTML = res.ok ? res.text : '<span class="error">' + res.text + '</span>';
      })
      .catch(function () {
        if (target) target.innerHTML = '<span class="error">Could not save — try again.</span>';
      });
  });
})();
</script>

<?php footer_html(); ?>
