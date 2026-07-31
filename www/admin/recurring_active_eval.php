<?php
// POST: activate/deactivate a recurring lesson template.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/recurring_lessons.php');
    exit;
}
require_csrf();

try {
    LessonManagement::setRecurringActive(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['id'] ?? 0),
        !empty($_POST['active'])
    );
    $_SESSION['recurring_flash'] = 'Saved.';
} catch (\Throwable $e) {
    $_SESSION['recurring_flash_error'] = $e->getMessage();
}
header('Location: /admin/recurring_lessons.php');
exit;
