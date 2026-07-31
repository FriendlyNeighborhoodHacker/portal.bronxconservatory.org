<?php
// Teacher home: today's lessons in chronological order. Each row shows time,
// student/class name, room/location (online lessons get an icon), attendance
// buttons, and a note box that auto-saves as the teacher types.
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

header_html("Today's Lessons");
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <h2><?=$date === date('Y-m-d') ? "Today's Lessons" : 'Lessons for ' . h(date('D, M j', strtotime($date)))?></h2>
  <form method="get">
    <input type="date" name="date" value="<?=h($date)?>" onchange="this.form.submit()">
  </form>
</div>

<?php if (!$lessons): ?>
  <p class="small">No lessons on this day. Enjoy the quiet — or check another date above.</p>
<?php endif; ?>

<?php foreach ($lessons as $lesson): ?>
  <?php
    $note = NotesManagement::lessonNoteFor((int)$lesson['id'], (int)$me['id']);
    $cancelled = $lesson['status'] === 'cancelled';
  ?>
  <div class="card" style="margin-bottom:12px;">
    <div class="lesson-row" style="border-bottom:0;<?=$cancelled ? '' : ''?>">
      <span class="lesson-row-time<?=$cancelled ? ' lesson-cancelled' : ''?>">
        <?=h(date('g:i A', strtotime($lesson['start_datetime'])))?>
      </span>
      <span class="<?=$cancelled ? 'lesson-cancelled' : ''?>">
        <strong><a href="/teacher/lesson.php?id=<?=(int)$lesson['id']?>"><?php
          if ($lesson['lesson_type'] === 'group') {
              echo h(lesson_name_label($lesson));
          } else {
              echo h(trim(($lesson['student_first_name'] ?? '') . ' ' . ($lesson['student_last_name'] ?? '')));
          }
        ?></a></strong>
        <span class="small"><?=h(lesson_name_label($lesson))?></span>
      </span>
      <span><?=lesson_place_html($lesson)?></span>
      <?php if ($cancelled): ?><span class="small">cancelled</span><?php endif; ?>
      <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
        <span class="small">covered by <?=h(trim(($lesson['sub_first_name'] ?? '') . ' ' . ($lesson['sub_last_name'] ?? '')))?></span>
      <?php endif; ?>
    </div>

    <?php if (!$cancelled): ?>
      <?php if ($lesson['lesson_type'] === 'individual'): ?>
        <div id="attendance-<?=(int)$lesson['id']?>-solo">
          <?=teacher_attendance_html((int)$lesson['id'], null, $lesson['attended'])?>
        </div>
      <?php else: ?>
        <?php foreach ($lesson['group_students'] ?? [] as $gs): ?>
          <div class="lesson-row" style="border-bottom:0;padding:4px 0;">
            <span><?=h($gs['first_name'] . ' ' . $gs['last_name'])?></span>
            <span id="attendance-<?=(int)$lesson['id']?>-<?=(int)$gs['student_user_id']?>">
              <?=teacher_attendance_html((int)$lesson['id'], (int)$gs['student_user_id'], $gs['attended'])?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="lesson-note-box" style="margin-top:8px;">
        <textarea data-lesson-id="<?=(int)$lesson['id']?>" class="lesson-note-input"
          placeholder="Log a note for this lesson — what you covered, what to practice…"><?=h($note['body'] ?? '')?></textarea>
        <div id="note-state-<?=(int)$lesson['id']?>"><?=teacher_note_state_html($note)?></div>
      </div>
    <?php endif; ?>
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
    var studentId = btn.dataset.studentId || '';
    var target = document.getElementById('attendance-' + lessonId + '-' + (studentId || 'solo'));
    var body = new URLSearchParams({csrf: csrf, lesson_id: lessonId, student_user_id: studentId, attended: btn.dataset.attended});
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
