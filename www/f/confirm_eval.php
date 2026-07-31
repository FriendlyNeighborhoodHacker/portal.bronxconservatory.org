<?php
// POST: confirm enrollment from the token link. The token itself is the
// authorization (no login, no CSRF session guaranteed — the unguessable
// token plays that role, matching the sibling app's /t/ pattern).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyAccessTokens.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

$rawToken = (string)($_POST['token'] ?? '');
$auth = FamilyAccessTokens::verify($rawToken);
if (!$auth) {
    header('Location: /f/schedule.php?token=' . urlencode($rawToken));
    exit;
}

FamilyManagement::markEnrolled(null, (int)$auth['family_id']);

header('Location: /f/schedule.php?token=' . urlencode($rawToken) . '&confirmed=1');
exit;
