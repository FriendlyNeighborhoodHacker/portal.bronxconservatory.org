<?php
// Teacher Calendar — the whole semester as one chronological list: every
// lesson with its date, time and location, plus the holiday weeks when a
// location where they teach is closed and there is no lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_semester_timeline.php';
require_once __DIR__ . '/../lib/ScheduleTimeline.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('teacher', $roles, true) && !in_array('admin', $roles, true)) {
    http_response_code(403);
    die('Teachers only');
}

$semester = SemesterManagement::resolveDefaultSemester();
$entries = $semester
    ? ScheduleTimeline::forTeacher((int)$me['id'], (int)$semester['id'])
    : [];

header_html('My Calendar');
?>

<div class="page-head">
  <h2>My Calendar<?php if ($semester): ?> — <?=h(SemesterManagement::label($semester))?><?php endif; ?></h2>
</div>

<?=semester_timeline_html($entries, fn(array $lesson): string => trim(
    ($lesson['student_preferred_name'] ?: $lesson['student_first_name'])
    . ' ' . $lesson['student_last_name']
))?>

<p class="small">Open the <a href="/teacher/calendar_week.php">Week</a> view to mark attendance
and write lesson notes.</p>

<?php footer_html(); ?>
