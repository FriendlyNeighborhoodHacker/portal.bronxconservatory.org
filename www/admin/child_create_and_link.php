<?php
// POST JSON: create a new child (student) and link them to a parent.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();

try {
    $parentId = (int)($_POST['parent_user_id'] ?? 0);
    if ($parentId <= 0) {
        throw new InvalidArgumentException('A parent is required.');
    }
    $childId = UserManagement::createUser($ctx, [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'no_login' => true,
    ]);
    $extras = [];
    foreach (['suffix', 'preferred_name'] as $key) {
        if (trim((string)($_POST[$key] ?? '')) !== '') {
            $extras[$key] = (string)$_POST[$key];
        }
    }
    if ($extras) {
        UserManagement::updateProfile($ctx, $childId, $extras);
    }
    StudentTeacherManagement::ensureStudentProfile($ctx, $childId, [
        'class_of' => (string)($_POST['class_of'] ?? ''),
    ]);
    StudentTeacherManagement::linkParentChild($ctx, $parentId, $childId, null);
    echo json_encode(['ok' => true, 'child_user_id' => $childId]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
