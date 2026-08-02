<?php
// POST: send the saved template, filled in with example answers, to the
// signed-in admin — the only way to see what it really looks like in a mail
// client.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailTemplateManagement.php';
require_once __DIR__ . '/../mailer.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/email_templates.php');
    exit;
}
require_csrf();

$key = (string)($_POST['template_key'] ?? '');
$user = current_user();
$to = trim((string)($user['email'] ?? ''));

try {
    if ($to === '') {
        throw new RuntimeException('Your account has no email address to send a test to.');
    }
    $rendered = EmailTemplateManagement::render($key, EmailTemplateManagement::sampleVariables($key));
    if ($rendered === null) {
        throw new RuntimeException('Unknown email template: ' . $key);
    }
    $sent = send_email($to, '[Test] ' . $rendered['subject'], $rendered['body_html'],
        trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($sent) {
        $_SESSION['email_template_flash'] = 'Test sent to ' . $to . '.';
    } else {
        // send_email already recorded why in the email log.
        $_SESSION['email_template_flash_error'] =
            'The test could not be sent. See Admin > Maintenance > Email Log for the reason.';
    }
} catch (\Throwable $e) {
    $_SESSION['email_template_flash_error'] = $e->getMessage();
}
header('Location: /admin/email_template.php?key=' . urlencode($key));
exit;
