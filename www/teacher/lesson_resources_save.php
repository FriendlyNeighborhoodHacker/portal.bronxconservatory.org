<?php
// Ajax POST: one save of a lesson's materials — remove the ones ticked in the
// Edit resources modal, then attach an optional new file or link. Permission
// is enforced in ResourceManagement (the lesson's teacher and admins may add;
// removing is limited to whoever added the material, or an admin).
//
// Removals run before the addition so that swapping one material for another
// in a single save can never leave both behind if the upload is rejected.
// Returns the lesson's materials list as an HTML fragment, which the caller
// swaps in.
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
$removeIds = array_values(array_filter(array_map('intval', (array)($_POST['remove'] ?? []))));

try {
    foreach ($removeIds as $resourceId) {
        $resource = ResourceManagement::find($resourceId);
        // Only materials on the lesson being edited, so a stale or tampered
        // form can't reach across lessons.
        if (!$resource || (int)$resource['lesson_id'] !== $lessonId) {
            throw new InvalidArgumentException('That material is no longer on this lesson.');
        }
        ResourceManagement::deleteResource($ctx, $resourceId);
    }

    // Adding is optional: a save may be removals only.
    if ((string)($_POST['resource_type'] ?? '') === 'link') {
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '') {
            ResourceManagement::addLinkResource($ctx, $lessonId, $title, $url);
        }
    } elseif (isset($_FILES['resource']) && (int)($_FILES['resource']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        ResourceManagement::addFileResource($ctx, $lessonId, $title, $_FILES['resource']);
    }

    echo LessonDetailUIManager::resourcesListHtml($lessonId);
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
