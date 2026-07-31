<?php
// POST: set a user's teacher/student roles and instruments (admin only).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    if (!empty($_POST['is_teacher'])) {
        StudentTeacherManagement::ensureTeacherProfile($ctx, $userId, [
            'gender' => $_POST['teacher_gender'] ?? null,
        ]);
        InstrumentCatalog::setTeacherInstruments($ctx, $userId, (array)($_POST['teacher_instrument_ids'] ?? []));
    } else {
        StudentTeacherManagement::removeTeacherProfile($ctx, $userId);
    }

    if (!empty($_POST['is_student'])) {
        StudentTeacherManagement::ensureStudentProfile($ctx, $userId);
        InstrumentCatalog::setStudentInstruments($ctx, $userId, (array)($_POST['student_instrument_ids'] ?? []));
    } else {
        StudentTeacherManagement::removeStudentProfile($ctx, $userId);
    }

    header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('Roles saved.'));
} catch (\Throwable $e) {
    header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode($e->getMessage()));
}
exit;
