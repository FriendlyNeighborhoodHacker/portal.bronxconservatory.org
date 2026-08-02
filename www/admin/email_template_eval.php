<?php
// POST: save an email template's subject and body.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailTemplateManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/email_templates.php');
    exit;
}
require_csrf();

$key = (string)($_POST['template_key'] ?? '');
$ctx = UserContext::getLoggedInUserContext();

try {
    EmailTemplateManagement::update(
        $ctx,
        $key,
        (string)($_POST['subject'] ?? ''),
        (string)($_POST['body_html'] ?? '')
    );
    $_SESSION['email_template_flash'] = 'Template saved.';
    header('Location: /admin/email_templates.php');
} catch (\Throwable $e) {
    $_SESSION['email_template_flash_error'] = $e->getMessage();
    // Their wording, not the saved copy, comes back on the edit page.
    $_SESSION['email_template_old'] = [
        'subject' => (string)($_POST['subject'] ?? ''),
        'body_html' => (string)($_POST['body_html'] ?? ''),
    ];
    header('Location: /admin/email_template.php?key=' . urlencode($key));
}
exit;
