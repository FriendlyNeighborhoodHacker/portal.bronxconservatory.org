<?php
// POST: materialize a recurring template's occurrences through a date.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/recurring_lessons.php');
    exit;
}
require_csrf();

$through = (string)($_POST['through'] ?? '');
try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)) {
        throw new InvalidArgumentException('Pick a date to generate through.');
    }
    $created = LessonManagement::generateOccurrencesThrough(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['id'] ?? 0),
        $through
    );
    $_SESSION['recurring_flash'] = $created . ' lesson' . ($created === 1 ? '' : 's') . ' generated.';
} catch (\Throwable $e) {
    $_SESSION['recurring_flash_error'] = $e->getMessage();
}
header('Location: /admin/recurring_lessons.php');
exit;
