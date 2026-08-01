<?php
// Evaluates the edit-profile form (POST from profile/edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile/');
    exit;
}

require_csrf();

$me = current_user();

$fields = person_basic_info_fields_from_post();
$first = trim((string)($fields['first_name'] ?? ''));
$last = trim((string)($fields['last_name'] ?? ''));
$email = strtolower(trim((string)($fields['email'] ?? '')));
$secondaryEmail = trim((string)($fields['secondary_email'] ?? ''));

function redirect_back_with_error(string $error, array $form): void {
    $_SESSION['error'] = $error;
    $_SESSION['form_data'] = $form;
    header('Location: /profile/edit.php');
    exit;
}

$errors = [];
if ($first === '') $errors[] = 'First name is required.';
if ($last === '') $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
if ($secondaryEmail !== '' && !filter_var($secondaryEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Secondary email is not a valid email address.';
if (!empty($errors)) {
    redirect_back_with_error(implode(' ', $errors), $fields);
}

if (strtolower((string)$me['email']) !== $email && UserManagement::emailExists($email)) {
    redirect_back_with_error('That email is already in use by another account.', $fields);
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    UserManagement::updateProfile($ctx, (int)$me['id'], $fields);
    $_SESSION['success'] = 'Profile updated.';
    header('Location: /profile/');
    exit;
} catch (Throwable $e) {
    redirect_back_with_error('Error updating profile: ' . $e->getMessage(), $fields);
}
