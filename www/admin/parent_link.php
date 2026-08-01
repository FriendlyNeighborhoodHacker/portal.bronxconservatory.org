<?php
// POST JSON: link an existing parent to a student.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    $parentId = (int)($_POST['parent_user_id'] ?? 0);
    $childId = (int)($_POST['child_user_id'] ?? 0);
    if ($parentId <= 0 || $childId <= 0) {
        throw new InvalidArgumentException('A parent and a child are required.');
    }
    $role = trim((string)($_POST['role'] ?? '')) ?: null;
    StudentTeacherManagement::linkParentChild(UserContext::getLoggedInUserContext(), $parentId, $childId, $role);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
