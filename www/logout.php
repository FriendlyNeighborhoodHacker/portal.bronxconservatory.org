<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ActivityLog.php';
require_once __DIR__ . '/lib/UserContext.php';

// Initialize application
Application::init();

// Logging out while impersonating means "return to the real user" — a plain
// session_destroy would drop the impersonation but the developer's
// remember_token cookie would silently log them back in anyway.
if (impersonator_id()) {
    header('Location: ' . (end_impersonation_and_restore() ? '/admin/' : '/login.php'));
    exit;
}

// Log logout before clearing session
$currentUser = current_user();
if ($currentUser) {
    ActivityLog::log(new UserContext((int)$currentUser['id'], !empty($currentUser['is_admin'])), 'user.logout', []);
}

// Clear session
session_destroy();

// Clear remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Redirect to login
header('Location: /login.php');
exit;
