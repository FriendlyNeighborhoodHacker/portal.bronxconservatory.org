<?php
// Ajax POST: add a note to a lesson. Anyone who may see the lesson may write
// on it — the teacher, an admin, the student, their parents — so this lives
// at the top level rather than under one role's directory. Returns the
// lesson's note list as an HTML fragment (the same one the page renders).
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/NotesManagement.php';
require_once __DIR__ . '/lib/LessonDetailUIManager.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
require_csrf();

$lessonId = (int)($_POST['lesson_id'] ?? 0);

try {
    NotesManagement::addLessonNote(
        UserContext::getLoggedInUserContext(),
        $lessonId,
        (string)($_POST['body'] ?? '')
    );
    echo LessonDetailUIManager::notesHtml($lessonId);
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
