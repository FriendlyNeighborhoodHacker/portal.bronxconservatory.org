<?php
// POST: attach an external link to a lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teacher/index.php');
    exit;
}
require_csrf();

$lessonId = (int)($_POST['lesson_id'] ?? 0);

try {
    ResourceManagement::addLinkResource(
        UserContext::getLoggedInUserContext(),
        $lessonId,
        (string)($_POST['title'] ?? ''),
        (string)($_POST['url'] ?? '')
    );
    $_SESSION['teacher_flash'] = 'Link shared.';
} catch (\Throwable $e) {
    $_SESSION['teacher_flash_error'] = $e->getMessage();
}
header('Location: /teacher/lesson.php?id=' . $lessonId);
exit;
