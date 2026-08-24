<?php
// POST: end dev-only "login as" and restore the original developer's login.
// Not under /admin/ because the effective user is typically not an admin.
require_once __DIR__ . '/partials.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}
require_csrf();

if (!impersonator_id()) {
    header('Location: /index.php');
    exit;
}

if (end_impersonation_and_restore()) {
    header('Location: /admin/');
} else {
    header('Location: /login.php');
}
exit;
