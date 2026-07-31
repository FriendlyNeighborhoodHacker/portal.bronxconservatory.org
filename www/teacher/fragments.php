<?php
// Shared HTML fragments for the teacher dashboard. The ajax endpoints
// (lesson_note_save.php, lesson_attendance_save.php) return these same
// fragments so a save swaps in exactly what the page would have rendered.
require_once __DIR__ . '/../partials.php';

// The note save-state line under a lesson's note box.
function teacher_note_state_html(?array $note): string {
    if (!$note || trim((string)($note['body'] ?? '')) === '') {
        return '<span class="note-save-state">Not logged yet — notes save automatically as you type.</span>';
    }
    return '<span class="note-save-state">Saved ' . h(date('g:i A', strtotime($note['updated_at']))) . '</span>';
}

// Attendance controls for one student of a lesson ($studentUserId null for
// individual lessons). $attended: null (unmarked) | 0 | 1.
function teacher_attendance_html(int $lessonId, ?int $studentUserId, $attended): string {
    $mark = $attended === null ? null : (int)$attended;
    $btn = function (int $value, string $label) use ($lessonId, $studentUserId, $mark) {
        $active = $mark === $value;
        return '<button type="button" class="button attendance-btn' . ($active ? ' active' : '') . '"'
            . ' data-lesson-id="' . $lessonId . '"'
            . ($studentUserId !== null ? ' data-student-id="' . $studentUserId . '"' : '')
            . ' data-attended="' . $value . '"'
            . ($active ? ' disabled' : '')
            . '>' . h($label) . ($active ? ' ✓' : '') . '</button>';
    };
    return '<span class="attendance-controls">' . $btn(1, 'Present') . ' ' . $btn(0, 'Absent') . '</span>';
}
