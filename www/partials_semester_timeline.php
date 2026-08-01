<?php
// Renders a ScheduleTimeline as a chronological list, sharing the semester
// date list's visual language: a green rule for a lesson, purple for a
// holiday week. Each caller supplies how to name the other party on a lesson
// row — a student sees their teacher, a parent sees which child, a teacher
// sees the student.
//
// The list can span several semesters (this one plus any already planned).
// It always says which semesters it covers, and once more than one is in
// play each row is tagged so a date is never ambiguous.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ScheduleTimeline.php';

/**
 * @param array    $entries  from ScheduleTimeline::forTeacher/forStudents
 * @param callable $whoFn    fn(array $lesson): string — "" to omit the line
 */
function semester_timeline_html(array $entries, callable $whoFn): string {
    if (!$entries) {
        return '<div class="card"><p>Nothing scheduled yet.</p></div>';
    }

    $labels = ScheduleTimeline::semesterLabels($entries);
    $tagRows = count($labels) > 1;

    $html = '<p class="small timeline-note">Showing lessons from: '
        . h(implode(', ', $labels)) . '</p>';
    $html .= '<div class="card sem-dates">';

    foreach ($entries as $entry) {
        $tag = $tagRows && $entry['semester_label'] !== ''
            ? '<span class="sem-date-tag">' . h($entry['semester_label']) . '</span>'
            : '';

        if ($entry['kind'] === 'holiday') {
            $html .= '<div class="sem-date sem-date-inactive">'
                . '<div class="sem-date-head"><strong>' . h(date('D M j, Y', strtotime($entry['date']))) . '</strong>'
                . ': ' . h($entry['title']) . $tag
                . '<span class="sem-date-time">no lesson</span></div>';
            if ($entry['location_name'] !== '') {
                $html .= '<div class="sem-date-locations">' . h($entry['location_name']) . '</div>';
            }
            $html .= '</div>';
            continue;
        }

        $lesson = $entry['lesson'];
        $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0;
        $start = strtotime((string)$lesson['start_datetime']);
        $end = $start + ((int)$lesson['duration_minutes']) * 60;

        $html .= '<div class="sem-date sem-date-active' . ($missed ? ' sem-date-missed' : '') . '">'
            . '<div class="sem-date-head"><strong>' . h(date('D M j, Y', $start)) . '</strong>'
            . ($missed ? ' <span class="badge">Missed</span>' : '') . $tag
            . '<span class="sem-date-time">' . h(date('g:i a', $start) . '–' . date('g:i a', $end)) . '</span></div>';

        $detail = array_filter([trim((string)$whoFn($lesson)), (string)$lesson['location_name']]);
        if ($detail) {
            $html .= '<div class="sem-date-locations">' . h(implode(' · ', $detail)) . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}
