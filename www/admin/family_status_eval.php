<?php
// POST: change a family's status. Redirects back to the family page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
require_csrf();

$familyId = (int)($_POST['family_id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$ctx = UserContext::getLoggedInUserContext();

try {
    FamilyManagement::setStatus($ctx, $familyId, $status);
    $_SESSION['family_flash'] = 'Status updated to "' . (FamilyManagement::STATUS_LABELS[$status] ?? $status) . '".';
} catch (\Throwable $e) {
    $_SESSION['family_flash_error'] = $e->getMessage();
}

header('Location: /admin/family.php?id=' . $familyId);
exit;
