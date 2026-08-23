<?php
// Ajax GET: the lesson's materials as the editable rows of the Edit resources
// modal (each with a Remove tickbox). Same permission as saving them —
// anyone who may see the lesson (its teacher, the student, their parents, an
// admin), because the same circle may add materials; Remove tickboxes still
// appear only on one's own uploads.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();
$lessonId = (int)($_GET['lesson_id'] ?? 0);

$lesson = LessonManagement::getLesson($lessonId);
if (!$lesson) {
    http_response_code(404);
    exit('Lesson not found.');
}
if (!$ctx->admin && !LessonManagement::canUserViewLesson($ctx->id, $lessonId)) {
    http_response_code(403);
    exit('This is not your lesson.');
}

echo LessonDetailUIManager::resourcesEditRowsHtml($lessonId, $ctx);
