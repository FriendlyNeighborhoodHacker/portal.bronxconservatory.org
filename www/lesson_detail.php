<?php
// AJAX: the contents of the lesson-detail modal (its notes, a box to add
// one, and its materials) as an HTML fragment. Anyone who may see the lesson
// may see this — the student, their parents, the teacher who has it, and
// admins.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LessonManagement.php';
require_once __DIR__ . '/lib/LessonDetailUIManager.php';
Application::init();
require_login();

header('Content-Type: text/html; charset=utf-8');

$me = current_user();
$lessonId = (int)($_GET['lesson_id'] ?? 0);

if (!$lessonId || !LessonManagement::canUserViewLesson((int)$me['id'], $lessonId)) {
    http_response_code(403);
    echo '<p class="error">This lesson is not yours to see.</p>';
    exit;
}

$lesson = LessonManagement::getLesson($lessonId);
if (!$lesson) {
    http_response_code(404);
    echo '<p class="error">Lesson not found.</p>';
    exit;
}

LessonDetailUIManager::renderDetail($lesson);
