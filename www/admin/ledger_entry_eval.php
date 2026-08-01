<?php
// POST: record a manual ledger entry from Edit Student's modals —
// action=payment | scholarship | custom. PRG back to the edit screen.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$studentUserId = (int)($_POST['student_user_id'] ?? 0);
$semesterId = (int)($_POST['semester_id'] ?? 0) ?: null;
$returnTo = validate_relative_next_path($_POST['return_to'] ?? '');
if ($returnTo === '') {
    $returnTo = '/admin/student_edit.php?id=' . $studentUserId;
}

$amountCents = (int)round((float)($_POST['amount'] ?? 0) * 100);
$description = trim((string)($_POST['description'] ?? ''));
$ctx = UserContext::getLoggedInUserContext();

try {
    switch ((string)($_POST['action'] ?? '')) {
        case 'payment':
            Billing::recordManualPayment($ctx, $studentUserId, $amountCents,
                (string)($_POST['entry_date'] ?? date('Y-m-d')), $semesterId, $description);
            $_SESSION['people_flash'] = 'Payment recorded.';
            break;
        case 'scholarship':
            Billing::applyScholarship($ctx, $studentUserId, $semesterId, $amountCents, $description);
            $_SESSION['people_flash'] = 'Scholarship applied.';
            break;
        case 'custom':
            Billing::addCustomEntry($ctx, $studentUserId,
                (string)($_POST['accounting_type'] ?? 'credit'), $amountCents, $semesterId, $description);
            $_SESSION['people_flash'] = 'Ledger entry added.';
            break;
        default:
            throw new InvalidArgumentException('Unknown ledger action.');
    }
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: ' . $returnTo);
exit;
