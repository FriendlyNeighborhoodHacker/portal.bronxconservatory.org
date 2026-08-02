<?php
// Shared HTML fragments for the teacher dashboard. The ajax endpoint
// (lesson_attendance_save.php) returns this same fragment so a save swaps in
// exactly what the page would have rendered.
require_once __DIR__ . '/../partials.php';

// Attendance controls for one student of a lesson ($studentUserId null for
// individual lessons). $attended: null (unmarked) | 0 | 1.
//
// Once a lesson is marked, an Unmark link appears: a mis-tap on a phone
// between two lessons should not be permanent, and "no answer yet" is a real
// state that was otherwise unreachable.
function teacher_attendance_html(int $lessonId, ?int $studentUserId, $attended): string {
    $mark = $attended === null ? null : (int)$attended;
    $studentAttr = $studentUserId !== null ? ' data-student-id="' . $studentUserId . '"' : '';
    $btn = function (int $value, string $label) use ($lessonId, $studentAttr, $mark) {
        $active = $mark === $value;
        return '<button type="button" class="button attendance-btn' . ($active ? ' active' : '') . '"'
            . ' data-lesson-id="' . $lessonId . '"' . $studentAttr
            . ' data-attended="' . $value . '"'
            . ($active ? ' disabled' : '')
            . '>' . h($label) . ($active ? ' ✓' : '') . '</button>';
    };
    $html = '<span class="attendance-controls">' . $btn(1, 'Present') . ' ' . $btn(0, 'Absent');
    if ($mark !== null) {
        $html .= ' <a href="#" class="attendance-unmark small" data-lesson-id="' . $lessonId . '"' . $studentAttr . '>Unmark</a>';
    }
    return $html . '</span>';
}
