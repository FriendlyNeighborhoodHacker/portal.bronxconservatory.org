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
require_once __DIR__ . '/lib/LessonManagement.php';
require_once __DIR__ . '/lib/LessonUIHelper.php';

/**
 * @param array    $entries  from ScheduleTimeline::forTeacher/forStudents
 * @param callable $whoFn    fn(array $lesson): string — "" to omit the line
 * @param bool     $withDetailLinks  add a compact notes-and-materials link to
 *   each lesson row — the latest note's text in a shaded box where there is
 *   one (plus "2 notes · 1 material" when there is more than that note),
 *   the counts alone for materials with no notes, "Add Note" where it
 *   has none yet. The links open the lesson detail modal, so a page passing
 *   true must also render LessonDetailUIManager::renderModal().
 */
function semester_timeline_html(array $entries, callable $whoFn, bool $withDetailLinks = false): string {
    if (!$entries) {
        return '<div class="card"><p>Nothing scheduled yet.</p></div>';
    }

    $timelineLessons = array_column(
        array_filter($entries, fn($e) => $e['kind'] === 'lesson'), 'lesson');
    $counts = $withDetailLinks ? LessonUIHelper::countsForLessons($timelineLessons) : [];
    $latestNotes = $withDetailLinks ? LessonUIHelper::latestNotesForLessons($timelineLessons) : [];

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
        // A cancelled lesson stays on this list on purpose: the family and the
        // teacher planned around it, so they are told it is off rather than
        // finding it quietly gone.
        $cancelled = LessonManagement::isCancelled($lesson);
        $start = strtotime((string)$lesson['start_datetime']);
        $end = $start + ((int)$lesson['duration_minutes']) * 60;
        // This week only: a time that no longer matches the standing slot, and
        // whoever is actually covering it.
        $timeMoved = LessonManagement::isTimeMoved($lesson);
        $substituteNote = LessonManagement::substituteNote($lesson);

        $html .= '<div class="sem-date sem-date-active'
            . ($cancelled ? ' sem-date-cancelled' : ($missed ? ' sem-date-missed' : '')) . '">'
            . '<div class="sem-date-head"><strong>' . h(date('D M j, Y', $start)) . '</strong>'
            . ($cancelled ? ' <span class="badge">Cancelled</span>' : '')
            . (!$cancelled && $missed ? ' <span class="badge">Missed</span>' : '')
            . (!$cancelled && $timeMoved ? ' <span class="badge">Time moved</span>' : '') . $tag
            . '<span class="sem-date-time">' . h(date('g:i a', $start) . '–' . date('g:i a', $end)) . '</span></div>';

        $detail = array_filter([
            trim((string)$whoFn($lesson)),
            $substituteNote,
            (string)$lesson['location_name'],
        ]);
        if ($detail) {
            $html .= '<div class="sem-date-locations">' . h(implode(' · ', $detail)) . '</div>';
        }
        if ($withDetailLinks) {
            $lessonCounts = $counts[(int)$lesson['id']] ?? ['notes' => 0, 'materials' => 0];
            $countLabel = LessonUIHelper::countsLabel($lessonCounts['notes'], $lessonCounts['materials']);
            $latestNote = $latestNotes[(int)$lesson['id']] ?? null;
            if ($latestNote) {
                // The note itself, not just "1 note": a family reads what the
                // teacher said right on the calendar, and taps for the rest.
                $html .= '<a href="#" class="sem-date-note-preview" data-lesson-detail="'
                    . (int)$lesson['id'] . '">' . h(LessonUIHelper::notePreviewText((string)$latestNote['body'])) . '</a>';
                // The count only adds something when the preview isn't the
                // whole story: more notes, or materials too.
                if ($lessonCounts['notes'] > 1 || $lessonCounts['materials'] > 0) {
                    $html .= '<div class="small" style="margin-top:2px;"><a href="#" data-lesson-detail="'
                        . (int)$lesson['id'] . '">' . h($countLabel) . '</a></div>';
                }
            } elseif ($countLabel !== '') {
                $html .= '<div class="small" style="margin-top:2px;"><a href="#" data-lesson-detail="'
                    . (int)$lesson['id'] . '">' . h($countLabel) . '</a></div>';
            } elseif (!$cancelled) {
                // Nothing on the lesson yet: still a way in, quietly, to
                // leave the first note.
                $html .= '<div class="small" style="margin-top:2px;"><a href="#" data-lesson-detail="'
                    . (int)$lesson['id'] . '">Add Note</a></div>';
            }
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}
