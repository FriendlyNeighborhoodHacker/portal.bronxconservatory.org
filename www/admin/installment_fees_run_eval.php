<?php
// POST: run the installment-fee sweep for real (the page shows the dry run
// first), back to the sweep page with a summary flash.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/installment_fees.php');
    exit;
}
require_csrf();

try {
    $result = Billing::applyAutomaticInstallmentFees(UserContext::getLoggedInUserContext());
    $applied = count($result['applied']);
    $_SESSION['installment_fees_flash'] = $applied > 0
        ? 'Applied ' . $applied . ' installment fee' . ($applied === 1 ? '' : 's') . '.'
        : 'Nothing to apply — everyone is paid up or already charged.';
} catch (\Throwable $e) {
    $_SESSION['installment_fees_flash_error'] = $e->getMessage();
}
header('Location: /admin/installment_fees.php');
exit;
