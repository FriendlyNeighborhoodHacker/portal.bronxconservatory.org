<?php
// POST: upload a lesson material (multipart).
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
$ctx = UserContext::getLoggedInUserContext();

try {
    if (empty($_FILES['resource'])) {
        throw new InvalidArgumentException('Choose a file to upload.');
    }
    ResourceManagement::addResource($ctx, $lessonId, null, (string)($_POST['title'] ?? ''), $_FILES['resource']);
    $_SESSION['teacher_flash'] = 'Material uploaded.';
} catch (\Throwable $e) {
    $_SESSION['teacher_flash_error'] = $e->getMessage();
}
header('Location: /teacher/lesson.php?id=' . $lessonId);
exit;
