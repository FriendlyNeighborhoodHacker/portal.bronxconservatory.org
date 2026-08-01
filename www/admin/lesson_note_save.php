<?php
// POST JSON: auto-save the admin's note on a lesson (upsert per author).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    $note = NotesManagement::saveLessonNote(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        (string)($_POST['body'] ?? '')
    );
    echo json_encode(['ok' => true, 'saved_at' => $note['updated_at'] ?? null]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
