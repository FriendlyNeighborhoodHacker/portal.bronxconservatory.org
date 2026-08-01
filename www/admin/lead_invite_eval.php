<?php
// POST: send the converted parent their set-up-your-account invitation.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/leads.php');
    exit;
}
require_csrf();

$leadId = (int)($_POST['lead_id'] ?? 0);
$lead = LeadManagement::findLead($leadId);
$ctx = UserContext::getLoggedInUserContext();

try {
    if (!$lead || empty($lead['converted_parent_user_id'])) {
        throw new InvalidArgumentException('Convert the lead first — there is no parent account to invite yet.');
    }
    UserManagement::sendAccountInvite($ctx, (int)$lead['converted_parent_user_id']);
    $_SESSION['lead_flash'] = 'Account invite sent to ' . $lead['email'] . '.';
} catch (\Throwable $e) {
    $_SESSION['lead_flash_error'] = $e->getMessage();
}
header('Location: /admin/lead.php?id=' . $leadId);
exit;
