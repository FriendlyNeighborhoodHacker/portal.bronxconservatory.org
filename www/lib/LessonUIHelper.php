<?php
declare(strict_types=1);

class LessonUIHelper {
    /**
     * Render lesson rows for "Other Upcoming Lessons".
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

        $html = '';
        if ($includeWrapper) {
            $html .= '<div class="card">' . "\n";
            $html .= '  <h3 style="margin-top:0;">Other Upcoming Lessons</h3>' . "\n";
        }

        foreach (array_slice($lessons, 0, $maxLessons) as $lesson) {
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

            $html .= '  <div style="padding:12px 0;border-bottom:1px solid var(--color-border);' . $opacityStyle . '">' . "\n";
            $html .= '    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">' . "\n";
            $html .= '      <div>' . "\n";
            $html .= '        <div style="font-weight:500;margin-bottom:4px;">' . h($timeDisplay) . '</div>' . "\n";
            $html .= '        <div style="font-size:14px;color:var(--color-text-secondary);margin-bottom:4px;">' . h($locationDisplay) . '</div>' . "\n";
            $html .= '        <div style="font-size:14px;">' . h($studentDisplay . $teacherDisplay) . '</div>' . "\n";
            $html .= '      </div>' . "\n";
            $html .= '      <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="button" style="white-space:nowrap;margin-top:4px;">Notes & Materials</a>' . "\n";
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";
        }

        if ($includeWrapper) {
            $html .= '</div>' . "\n";
        }
        return $html;
    }
}
