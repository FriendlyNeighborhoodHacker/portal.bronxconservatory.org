<?php
// Family Calendar — the whole semester as one chronological list: every
// child's lessons with date, time and location, plus the holiday weeks when
// their location is closed and there is no lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_semester_timeline.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/ScheduleTimeline.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$childIds = array_map(fn($c) => (int)$c['id'], $children);

$semester = SemesterManagement::resolveDefaultSemester();
$entries = $semester
    ? ScheduleTimeline::forStudents($childIds, (int)$semester['id'])
    : [];

header_html('Family Calendar');
?>

<div class="page-head">
  <h2>Family Calendar<?php if ($semester): ?> — <?=h(SemesterManagement::label($semester))?><?php endif; ?></h2>
</div>

<?=semester_timeline_html($entries, function (array $lesson): string {
    $child = trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name'])
        . ' ' . $lesson['student_last_name']);
    $first = $lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name'];
    $last = $lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'];
    return $child . ' with ' . trim($first . ' ' . $last);
})?>

<?php footer_html(); ?>
