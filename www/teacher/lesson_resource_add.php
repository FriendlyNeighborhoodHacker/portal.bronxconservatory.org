<?php
// Ajax POST: attach a resource to a lesson — an uploaded file or a link,
// each with a title. Only the lesson's teacher and admins may (enforced in
// ResourceManagement). Returns the lesson's materials list as an HTML
// fragment, which the caller swaps in.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$title = (string)($_POST['title'] ?? '');

try {
    if ((string)($_POST['resource_type'] ?? '') === 'link') {
        ResourceManagement::addLinkResource($ctx, $lessonId, $title, (string)($_POST['url'] ?? ''));
    } else {
        if (!isset($_FILES['resource'])) {
            throw new InvalidArgumentException('Choose a file to upload.');
        }
        ResourceManagement::addFileResource($ctx, $lessonId, $title, $_FILES['resource']);
    }
    echo LessonDetailUIManager::resourcesListHtml($lessonId);
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
