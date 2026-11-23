<?php
declare(strict_types=1);

require_once __DIR__ . '/NotesManagement.php';
require_once __DIR__ . '/ResourceManagement.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/LessonDetailUIManager.php';

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

    /**
     * The most recent note on each of these lessons, as [lesson_id => note
     * row] — one query however many lessons a page lists. Lessons with no
     * notes are absent from the map.
     */
    public static function latestNotesForLessons(array $lessons): array {
        $ids = array_map(fn($lesson) => (int)$lesson['id'], $lessons);
        $latest = [];
        // notesForLessons returns each lesson's notes newest first, so the
        // first one seen for a lesson is its latest.
        foreach (NotesManagement::notesForLessons($ids) as $note) {
            $latest[(int)$note['lesson_id']] ??= $note;
        }
        return $latest;
    }

    /**
     * A note's text squeezed onto one line for a preview: whitespace
     * collapsed, and cut at a word boundary with "…" past $maxChars.
     */
    public static function notePreviewText(string $body, int $maxChars = 100): string {
        $text = trim((string)preg_replace('/\s+/u', ' ', $body));
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $maxChars * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " \t.,;:") . '…';
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
     * @param bool $inlineNotes Show each lesson's notes and materials right
     *        in the row (the student's own home page) instead of behind the
     *        "Notes & Materials" button; a small link still opens the modal
     *        to add to them.
     * @return string HTML for the lesson rows
     */
    public static function renderOtherUpcomingLessons(
        array $lessons,
        bool $showStudentName = false,
        int $maxLessons = 5,
        bool $includeWrapper = true,
        bool $inlineNotes = false
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
            $locationDisplay = lesson_place_html($lesson);
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
            $html .= '        <div style="font-size:14px;color:var(--color-text-secondary);margin-bottom:4px;">' . $locationDisplay . '</div>' . "\n";
            $html .= '        <div style="font-size:14px;">' . h($studentDisplay . $teacherDisplay) . '</div>' . "\n";
            $html .= '      </div>' . "\n";
            if ($inlineNotes) {
                $html .= '      <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="small" style="white-space:nowrap;margin-top:8px;">'
                    . ($countLabel !== '' ? 'Add to these notes' : 'Add Note') . '</a>' . "\n";
            } elseif ($countLabel !== '') {
                $html .= '      <div style="text-align:right;flex-shrink:0;margin-top:4px;">' . "\n";
                $html .= '        <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="button notes" style="white-space:nowrap;">Notes &amp; Materials</a>' . "\n";
                $html .= '        <div class="small" style="margin-top:4px;">' . h($countLabel) . '</div>' . "\n";
                $html .= '      </div>' . "\n";
            } else {
                $html .= '      <a href="#" data-lesson-detail="' . (int)$lesson['id'] . '" class="small" style="white-space:nowrap;margin-top:8px;">Add Note</a>' . "\n";
            }
            $html .= '    </div>' . "\n";
            if ($inlineNotes && $countLabel !== '') {
                $html .= '    <div class="lesson-notes" style="margin-top:8px;">'
                    . LessonDetailUIManager::threadHtml((int)$lesson['id']) . '</div>' . "\n";
            }
            $html .= '  </div>' . "\n";
        }

        if ($includeWrapper) {
            $html .= '</div>' . "\n";
        }
        return $html;
    }

    /**
     * A student's lessons as the admin sees them on the student page: one row
     * per lesson with when, who and where, what became of it (attended,
     * missed, cancelled and why, a one-off and why), and its notes and
     * materials inline — read-only. $lessons are LessonManagement rows, in
     * the order to show.
     */
    public static function renderAdminLessonList(array $lessons): string {
        if (!$lessons) {
            return '<p class="small">No lessons to show.</p>';
        }
        $counts = self::countsForLessons($lessons);
        $now = time();
        $html = '';
        foreach ($lessons as $lesson) {
            $start = strtotime((string)$lesson['start_datetime']);
            $end = $start + ((int)$lesson['duration_minutes'] * 60);
            $cancelled = LessonManagement::isCancelled($lesson);
            $teacher = trim(
                ($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' ' .
                ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])
            );

            $tags = [];
            if ($cancelled) {
                $tags[] = 'Cancelled — ' . LessonManagement::cancelReasonLabel($lesson['cancel_reason'] ?? null)
                    . (($lesson['cancel_rebookable'] ?? null) === null ? ''
                        : ((int)$lesson['cancel_rebookable'] === 1 ? ' (rebookable)' : ' (not rebookable)'));
            } elseif (($lesson['attended'] ?? null) !== null) {
                $tags[] = (int)$lesson['attended'] === 1 ? 'Attended' : 'Missed';
            } elseif ($start > $now) {
                $tags[] = 'Upcoming';
            } else {
                $tags[] = 'Attendance not marked';
            }
            if (!empty($lesson['substitute_teacher_user_id'])) {
                $tags[] = 'Substitute';
            }
            if (($lesson['semester_lesson_reservation_id'] ?? null) === null) {
                $tags[] = 'One-off — ' . LessonManagement::adhocReasonLabel($lesson['adhoc_reason'] ?? null)
                    . (trim((string)($lesson['adhoc_note'] ?? '')) !== '' ? ': ' . trim((string)$lesson['adhoc_note']) : '');
            }

            $lessonCounts = $counts[(int)$lesson['id']] ?? ['notes' => 0, 'materials' => 0];
            $html .= '<div style="padding:12px 0;border-bottom:1px solid var(--color-border);' . ($cancelled ? 'opacity:0.6;' : '') . '">'
                . '<div style="font-weight:500;">' . h(date('D, M j, Y · g:i', $start) . '–' . date('g:i A', $end)) . '</div>'
                . '<div class="small">' . h($teacher) . ' · ' . lesson_place_html($lesson) . '</div>'
                . '<div class="small">' . h(implode(' · ', $tags)) . '</div>';
            if ($lessonCounts['notes'] > 0 || $lessonCounts['materials'] > 0) {
                $html .= '<div class="lesson-notes" style="margin-top:8px;">'
                    . LessonDetailUIManager::threadHtml((int)$lesson['id']) . '</div>';
            }
            $html .= '</div>';
        }
        return $html;
    }
}
