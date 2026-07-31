<?php
// POST: publish an announcement.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/announcements.php');
    exit;
}
require_csrf();

try {
    AnnouncementManagement::create(
        UserContext::getLoggedInUserContext(),
        (string)($_POST['title'] ?? ''),
        (string)($_POST['body'] ?? ''),
        (string)($_POST['audience'] ?? 'all')
    );
    $_SESSION['announcement_flash'] = 'Announcement published.';
} catch (\Throwable $e) {
    $_SESSION['announcement_flash_error'] = $e->getMessage();
}
header('Location: /admin/announcements.php');
exit;
