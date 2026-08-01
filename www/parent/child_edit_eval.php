<?php
// POST: save one of my children's basic information (from parent/child_edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile/');
    exit;
}
require_csrf();

$me = current_user();
$childId = (int)($_POST['id'] ?? 0);
if (!StudentTeacherManagement::isParentOf((int)$me['id'], $childId) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Not your student');
}
$child = UserManagement::findById($childId);
if (!$child) {
    http_response_code(404);
    die('Student not found');
}

$fields = person_basic_info_fields_from_post();
$first = trim((string)($fields['first_name'] ?? ''));
$last = trim((string)($fields['last_name'] ?? ''));
$email = strtolower(trim((string)($fields['email'] ?? '')));
$secondaryEmail = trim((string)($fields['secondary_email'] ?? ''));

function redirect_back_with_error(string $error, int $childId, array $form): void {
    $_SESSION['error'] = $error;
    $_SESSION['form_data'] = $form;
    header('Location: /parent/child_edit.php?id=' . $childId);
    exit;
}

// A child often has no email of their own, so email is optional here — but a
// value that is present must be valid and unused by another account.
$errors = [];
if ($first === '') $errors[] = 'First name is required.';
if ($last === '') $errors[] = 'Last name is required.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not a valid email address.';
if ($secondaryEmail !== '' && !filter_var($secondaryEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Secondary email is not a valid email address.';
if (!empty($errors)) {
    redirect_back_with_error(implode(' ', $errors), $childId, $fields);
}

if ($email !== '' && strtolower((string)$child['email']) !== $email && UserManagement::emailExists($email)) {
    redirect_back_with_error('That email is already in use by another account.', $childId, $fields);
}

try {
    UserManagement::updateProfile(UserContext::getLoggedInUserContext(), $childId, $fields);
    $_SESSION['success'] = 'Profile updated.';
    header('Location: /parent/child_edit.php?id=' . $childId);
    exit;
} catch (Throwable $e) {
    redirect_back_with_error('Error updating profile: ' . $e->getMessage(), $childId, $fields);
}
