<?php
// Ajax POST: auto-save a teacher's lesson note. Returns the note-state HTML
// fragment (same function the dashboard renders with).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
try {
    $note = NotesManagement::saveLessonNote($ctx, (int)($_POST['lesson_id'] ?? 0), (string)($_POST['body'] ?? ''));
    echo teacher_note_state_html($note);
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
