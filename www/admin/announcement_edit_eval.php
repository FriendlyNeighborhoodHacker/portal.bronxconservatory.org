<?php
// POST: save or delete an announcement edit.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/announcements.php');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    if (($_POST['action'] ?? '') === 'delete') {
        AnnouncementManagement::delete($ctx, $id);
        $_SESSION['announcement_flash'] = 'Announcement deleted.';
    } else {
        AnnouncementManagement::update($ctx, $id,
            (string)($_POST['title'] ?? ''),
            (string)($_POST['body'] ?? ''),
            (string)($_POST['valid_until'] ?? ''));
        $_SESSION['announcement_flash'] = 'Announcement saved.';
    }
    header('Location: /admin/announcements.php');
} catch (\Throwable $e) {
    $_SESSION['announcement_flash_error'] = $e->getMessage();
    header('Location: /admin/announcement_edit.php?id=' . $id);
}
exit;
