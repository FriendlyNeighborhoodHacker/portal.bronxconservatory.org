<?php
// Student Calendar — the whole semester as one chronological list: every
// lesson with its date, time and location, plus the holiday weeks when the
// student's location is closed and there is no lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_semester_timeline.php';
require_once __DIR__ . '/../lib/ScheduleTimeline.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$semester = SemesterManagement::resolveDefaultSemester();
$entries = $semester
    ? ScheduleTimeline::forStudents([(int)$me['id']], (int)$semester['id'])
    : [];

header_html('My Calendar');
?>

<div class="page-head">
  <h2>My Calendar<?php if ($semester): ?> — <?=h(SemesterManagement::label($semester))?><?php endif; ?></h2>
</div>

<?=semester_timeline_html($entries, function (array $lesson): string {
    // The substitute is who the student will actually see that week.
    $first = $lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name'];
    $last = $lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'];
    return 'with ' . trim($first . ' ' . $last);
})?>

<?php footer_html(); ?>
