<?php
// POST: send the "Great news — here's your schedule" email (with the
// family-access-token enrollment link) and flip status to schedule_assigned.
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
$ctx = UserContext::getLoggedInUserContext();

try {
    FamilyManagement::sendScheduleAssignedEmail($ctx, $familyId);
    $_SESSION['family_flash'] = 'Schedule email sent (see the Email Log for delivery status).';
} catch (\Throwable $e) {
    $_SESSION['family_flash_error'] = $e->getMessage();
}

header('Location: /admin/family.php?id=' . $familyId);
exit;
