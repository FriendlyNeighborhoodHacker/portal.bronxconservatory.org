<?php
// POST: dev-only "login as" — render the site as another user. The real
// developer's id stays in the session so the top bar can restore it.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();
require_admin();
require_developer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}
require_csrf();

$returnTo = validate_relative_next_path($_POST['return_to'] ?? '');
if ($returnTo === '') {
    $returnTo = '/admin/users.php';
}

$me = current_user();
$target = UserManagement::findById((int)($_POST['user_id'] ?? 0));
if (!$target || !empty($target['is_deleted'])) {
    $_SESSION['people_flash_error'] = 'User not found.';
    header('Location: ' . $returnTo);
    exit;
}
if ((int)$target['id'] === (int)$me['id']) {
    $_SESSION['people_flash_error'] = 'You are already logged in as this user.';
    header('Location: ' . $returnTo);
    exit;
}

// If already impersonating (possible when the impersonated user is themself a
// developer), keep the original impersonator so "Return" goes to the real human.
$impersonatorId = impersonator_id() ?? (int)$me['id'];

ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.impersonate_start', [
    'impersonator_user_id' => $impersonatorId,
    'target_user_id' => (int)$target['id'],
]);

if (!impersonator_id()) {
    $_SESSION['impersonator_name'] = trim($me['first_name'] . ' ' . $me['last_name']);
}
$_SESSION['impersonator_uid'] = $impersonatorId;
$_SESSION['uid'] = (int)$target['id'];
$_SESSION['is_admin'] = !empty($target['is_admin']) ? 1 : 0;
$_SESSION['is_super'] = 0;

header('Location: /index.php');
exit;
