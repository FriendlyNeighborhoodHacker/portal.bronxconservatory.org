<?php
declare(strict_types=1);

require_once __DIR__ . '/NotesManagement.php';
require_once __DIR__ . '/ResourceManagement.php';

class LessonUIHelper {
    /**
     * Note and material counts for a set of lesson rows, as
     * [lesson_id => ['notes' => n, 'materials' => n]] — two batched queries
     * however many lessons a page lists.
     */
    public static function countsForLessons(array $lessons): array {
        $ids = array_map(fn($lesson) => (int)$lesson['id'], $lessons);
        $noteCounts = NotesManagement::noteCountsForLessons($ids);
        $resourceCounts = ResourceManagement::resourceCountsForLessons($ids);
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = [
                'notes' => $noteCounts[$id] ?? 0,
                'materials' => $resourceCounts[$id] ?? 0,
            ];
        }
        return $counts;
    }

    /** "2 notes · 1 material" — empty string when there is nothing to count. */
    public static function countsLabel(int $notes, int $materials): string {
        $parts = [];
        if ($notes > 0) {
            $parts[] = $notes . ($notes === 1 ? ' note' : ' notes');
        }
        if ($materials > 0) {
            $parts[] = $materials . ($materials === 1 ? ' material' : ' materials');
        }
        return implode(' · ', $parts);
    }

    /**
     * Render lesson rows for "Other Upcoming Lessons".
     *
     * Each row opens the lesson's notes-and-materials modal. A lesson that
     * already has notes or materials gets the full button plus a count, so a
     * family can see whether it is worth the tap; a lesson with nothing yet
     * gets a quiet "Add Note" link instead of a button that would open on an
     * empty room.
     *
     * @param array $lessons Array of lesson rows from LessonManagement
     * @param bool $showStudentName Whether to show the student's name (for parent views)
     * @param int $maxLessons Maximum number of lessons to display
     * @param bool $includeWrapper Whether to include the card wrapper and heading
     * @return string HTML for the lesson rows
     */
    public static function renderOtherUpcomingLessons(
        array $lessons,
        bool $showStudentName = false,
        int $maxLessons = 5,
        bool $includeWrapper = true
    ): string {
        if (!$lessons) {
            return '';
        }

        $shown = array_slice($lessons, 0, $maxLessons);
        $counts = self::countsForLessons($shown);

        $html = '';
        if ($includeWrapper) {
            $html .= '<div class="card">' . "\n";
            $html .= '  <h3 style="margin-top:0;">Other Upcoming Lessons</h3>' . "\n";
        }

        foreach ($shown as $lesson) {
            $cancelled = LessonManagement::isCancelled($lesson);
            $opacityStyle = $cancelled ? 'opacity:0.6;' : '';

            $start = strtotime($lesson['start_datetime']);
            $end = $start + ((int)$lesson['duration_minutes'] * 60);
            $timeDisplay = date('D, M j · g:i', $start) . '–' . date('g:i A', $end);
            $locationDisplay = $lesson['location_name'] ?? '';
            $teacherDisplay = trim(
                ($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' ' .
                ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])
            );
            $studentDisplay = $showStudentName
                ? ($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' · '
                : '';

            $lessonCounts = $counts[(int)$lesson['id']] ?? ['notes' => 0, 'materials' => 0];
            $countLabel = self::countsLabel($lessonCounts['notes'], $lessonCounts['materials']);

            $html .= '  <div style="padding:12px 0;border-bottom:1px solid var(--color-border);' . $opacityStyle . '">' . "\n";
            $html .= '    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">' . "\n";
            $html .= '      <div>' . "\n";
            $html .= '        <div style="font-weight:500;margin-bottom:4px;">' . h($timeDisplay) . '</div>' . "\n";
            $html .= '        <div style="font-size:14px;color:var(--color-text-secondary);margin-bottom:4px;">' . h($locationDisplay) . '</div>' . "\n";
            $html .= '        <div style="font-size:14px;">' . h($studentDisplay . $teacherDisplay) . '</div>' . "\n";
            $html .= '      </div>' . "\n";
            if ($countLabel !== '') {
                $html .= '      <div style="text-align:right;flex-shrink:0;margin-top:4px;">' . "\n";
                $html .= '        <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="button" style="white-space:nowrap;">Notes &amp; Materials</a>' . "\n";
                $html .= '        <div class="small" style="margin-top:4px;">' . h($countLabel) . '</div>' . "\n";
                $html .= '      </div>' . "\n";
            } else {
                $html .= '      <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="small" style="white-space:nowrap;margin-top:8px;">Add Note</a>' . "\n";
            }
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";
        }

        if ($includeWrapper) {
            $html .= '</div>' . "\n";
        }
        return $html;
    }
}
