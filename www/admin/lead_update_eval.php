<?php
// POST: save a lead's status + internal notes.
require_once __DIR__ . '/lead_ui.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/leads.php');
    exit;
}
require_csrf();

$leadId = (int)($_POST['lead_id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    LeadManagement::updateStatus($ctx, $leadId, (string)($_POST['status'] ?? ''));
    LeadManagement::saveAdminNotes($ctx, $leadId, (string)($_POST['admin_notes'] ?? ''));
    $_SESSION['lead_flash'] = 'Saved.';
} catch (\Throwable $e) {
    $_SESSION['lead_flash_error'] = $e->getMessage();
}
header('Location: /admin/lead.php?id=' . $leadId);
exit;
