<?php
// Ajax GET: the lesson's materials as the editable rows of the Edit resources
// modal (each with a Remove tickbox). Same permission as saving them — the
// lesson's effective teacher, or an admin — because the rows reveal who
// uploaded what.
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
if (!$ctx->admin && !LessonManagement::isEffectiveTeacher($ctx->id, $lesson)) {
    http_response_code(403);
    exit('This is not your lesson.');
}

echo LessonDetailUIManager::resourcesEditRowsHtml($lessonId, $ctx);
