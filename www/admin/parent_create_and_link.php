<?php
// POST JSON: create a new parent and link them to a student.
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
    $childId = (int)($_POST['child_user_id'] ?? 0);
    if ($childId <= 0) {
        throw new InvalidArgumentException('A child is required.');
    }
    $parentId = UserManagement::createUser($ctx, [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'no_login' => true,
    ]);
    $cell = trim((string)($_POST['cell_phone'] ?? ''));
    if ($cell !== '') {
        UserManagement::updateProfile($ctx, $parentId, ['cell_phone' => $cell]);
    }
    $role = trim((string)($_POST['role'] ?? '')) ?: null;
    StudentTeacherManagement::linkParentChild($ctx, $parentId, $childId, $role);
    echo json_encode(['ok' => true, 'parent_user_id' => $parentId]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
