<?php
// POST: append an internal note to a lead, optionally moving its status in the
// same save. Notes are history, so this only ever adds.
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
    LeadManagement::addLeadNote(
        $ctx,
        $leadId,
        (string)($_POST['note_body'] ?? ''),
        (string)($_POST['status'] ?? '') ?: null
    );
    $_SESSION['lead_flash'] = 'Note added.';
} catch (\Throwable $e) {
    $_SESSION['lead_flash_error'] = $e->getMessage();
    // Hand back what they typed rather than making them write it again.
    $_SESSION['lead_note_old'] = (string)($_POST['note_body'] ?? '');
}
header('Location: /admin/lead.php?id=' . $leadId);
exit;
