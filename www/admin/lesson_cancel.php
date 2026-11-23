<?php
// POST JSON: cancel one lesson, with the reason (required) — which decides
// whether the student is owed a make-up. It leaves the admin calendar and
// stops holding the slot; the family and the teacher still see it, marked
// cancelled.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    $reason = (string)($_POST['cancel_reason'] ?? '');
    if (!isset(LessonManagement::CANCEL_REASONS[$reason])) {
        throw new InvalidArgumentException('Please choose why the lesson is being cancelled.');
    }
    // Only read where the reason leaves it to the admin (parent, 24 hours' notice).
    $rebookable = isset($_POST['rebookable']) && $_POST['rebookable'] !== ''
        ? (string)$_POST['rebookable'] === '1' : null;
    LessonManagement::cancelLesson(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        $reason,
        $rebookable
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
