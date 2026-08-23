<?php
// Teacher home: one card per lesson for a single day — attendance, the
// lesson's notes, and its materials, all where the teacher already is.
//
// The day shown is today when there are lessons today; otherwise the next day
// that has any, because lessons here are sparse and an empty page is no use
// to anybody. There is no date picker for the same reason — most dates have
// nothing on them. Jumping around is done by teaching day, here with the
// arrows and in full on Teaching Days.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('teacher', $roles, true) && !in_array('admin', $roles, true)) {
    http_response_code(403);
    die('Teachers only');
}

$today = date('Y-m-d');
$requestedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : null;

$date = $requestedDate ?? $today;
$lessons = LessonManagement::lessonsForTeacherOnDate((int)$me['id'], $date);

// Nothing today (and no date asked for): show the next day that has lessons.
$showingNextDay = false;
if (!$lessons && $requestedDate === null) {
    $nextDays = LessonManagement::upcomingTeachingDaysForTeacher((int)$me['id'], $today, 1);
    if ($nextDays) {
        $date = (string)$nextDays[0]['lesson_date'];
        $lessons = LessonManagement::lessonsForTeacherOnDate((int)$me['id'], $date);
        $showingNextDay = true;
    }
}

// The family behind each lesson, looked up once for the whole day: a teacher
// reading their day wants to know who they will be handing the student back to.
$parentNames = StudentTeacherManagement::parentNamesByStudent(
    array_map(fn($l) => (int)$l['student_user_id'], $lessons)
);

$prevDay = LessonManagement::previousTeachingDateForTeacher((int)$me['id'], $date);
$nextDay = LessonManagement::nextTeachingDateForTeacher((int)$me['id'], $date);

if ($requestedDate !== null) {
    $title = 'Lessons for ' . date('D, M j', strtotime($date));
} elseif ($date === $today && $lessons) {
    $title = "Today's Lessons";
} else {
    // The next teaching day, or nothing scheduled at all — either way this
    // page is about what is coming rather than about today.
    $title = 'Upcoming Lessons';
}

header_html($title);
?>

<div class="page-head">
  <h2><?=h($title)?></h2>
  <span class="actions">
    <?php if ($prevDay): ?>
      <a class="button" href="/teacher/index.php?date=<?=h($prevDay)?>">&larr; <?=h(date('M j', strtotime($prevDay)))?></a>
    <?php endif; ?>
    <?php if ($nextDay): ?>
      <a class="button" href="/teacher/index.php?date=<?=h($nextDay)?>"><?=h(date('M j', strtotime($nextDay)))?> &rarr;</a>
    <?php endif; ?>
    <a class="button" href="/teacher/days.php">Teaching Days</a>
  </span>
</div>

<?php if ($lessons && $date !== $today): ?>
  <p class="small"><?=h(date('l, F j, Y', strtotime($date)))?><?=$showingNextDay ? ' — your next teaching day' : ''?></p>
<?php endif; ?>

<?php if (!$lessons): ?>
  <p class="small">
    <?php if ($requestedDate !== null): ?>No lessons on this day.<?php else: ?>You have no lessons scheduled yet.<?php endif; ?>
    <?php if ($nextDay): ?>Your next teaching day is
      <a href="/teacher/index.php?date=<?=h($nextDay)?>"><?=h(date('l, M j', strtotime($nextDay)))?></a>.<?php endif; ?>
  </p>
<?php endif; ?>

<?php foreach ($lessons as $lesson): ?>
  <?php
    $lessonId = (int)$lesson['id'];
    $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0;
    // Still listed when cancelled — you planned your day around it.
    $cancelled = LessonManagement::isCancelled($lesson);
    $struck = $cancelled || $missed;
  ?>
  <div class="card" style="margin-bottom:12px;">
    <div class="<?=$struck ? 'lesson-cancelled' : ''?>" style="font-size:16px;">
      <strong><a href="/teacher/lesson.php?id=<?=$lessonId?>"><?=h(trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']))?></a></strong>
      <?php if ($cancelled): ?><span class="badge">Cancelled</span><?php endif; ?>
      <?php if (!empty($lesson['substitute_teacher_user_id'])): ?>
        <span class="small">covered by <?=h(trim(($lesson['substitute_first_name'] ?? '') . ' ' . ($lesson['substitute_last_name'] ?? '')))?></span>
      <?php endif; ?>
    </div>
    <div class="<?=$struck ? 'lesson-cancelled' : ''?>" style="margin-top:2px;">
      <?=h(date('g:i A', strtotime($lesson['start_datetime'])))?>, <?=h(date('M j', strtotime($lesson['start_datetime'])))?>
    </div>
    <div class="small" style="color:var(--color-text-muted);margin-top:2px;">
      <?=h($lesson['location_name'])?>
    </div>

    <?php $lessonParents = $parentNames[(int)$lesson['student_user_id']] ?? []; ?>
    <?php if ($lessonParents): ?>
      <div class="lesson-row-parents"><?=h(implode(', ', $lessonParents))?></div>
    <?php endif; ?>

    <div id="attendance-<?=$lessonId?>-solo">
      <?=teacher_attendance_html($lessonId, null, $lesson['attended'])?>
    </div>

    <h4>Notes &amp; Materials</h4>
    <?=LessonDetailUIManager::notesBlockHtml($lessonId, true, 'What you covered, what to practice…')?>
    <?=LessonDetailUIManager::resourcesEditButtonHtml($lessonId)?>
  </div>
<?php endforeach; ?>

<?php LessonDetailUIManager::renderNotesScript(); ?>
<?php LessonDetailUIManager::renderResourceModal(); ?>

<script>
(function () {
  var csrf = <?=json_encode(csrf_token())?>;

  // Attendance: mark, or take the mark back off. Delegated, because the
  // controls are replaced by the fragment each save returns.
  function saveAttendance(lessonId, attended) {
    var target = document.getElementById('attendance-' + lessonId + '-solo');
    var body = new URLSearchParams({csrf: csrf, lesson_id: lessonId, attended: attended});
    fetch('/teacher/lesson_attendance_save.php', {method: 'POST', body: body, credentials: 'same-origin'})
      .then(function (r) { return r.text().then(function (t) { return {ok: r.ok, text: t}; }); })
      .then(function (res) {
        if (target) target.innerHTML = res.ok ? res.text : '<span class="error">' + res.text + '</span>';
      })
      .catch(function () {
        if (target) target.innerHTML = '<span class="error">Could not save — try again.</span>';
      });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.attendance-btn') : null;
    if (btn && !btn.disabled) {
      saveAttendance(btn.dataset.lessonId, btn.dataset.attended);
      return;
    }
    var unmark = e.target.closest ? e.target.closest('.attendance-unmark') : null;
    if (unmark) {
      e.preventDefault();
      if (confirm('Clear the present/absent mark for this lesson?')) {
        saveAttendance(unmark.dataset.lessonId, '');
      }
    }
  });
})();
</script>

<?php footer_html(); ?>
