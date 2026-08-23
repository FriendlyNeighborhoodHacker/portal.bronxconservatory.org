#!/usr/bin/env php
<?php
// Daily installment-fee sweep: post the installment plan fee to confirmed
// students in in-progress semesters (past their first day) whose balance is
// not paid in full and who have not already been charged the fee. Idempotent —
// safe to run more than once a day. All the logic lives in
// Billing::applyAutomaticInstallmentFees(); this script is argv + output only.
//
// Usage: php apply_installment_fees.php [--dry-run] [--today=YYYY-MM-DD]
//
// Crontab (daily at 2:15 AM server time):
//   15 2 * * * php /path/to/www/bin/apply_installment_fees.php >> /var/log/bcm_installment_fees.log 2>&1
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../lib/Billing.php';

// Ledger dates must follow the school's timezone, not the server's — the same
// rule Application::init() applies for web requests.
try {
    date_default_timezone_set(Settings::timezone());
} catch (\Throwable $e) {
    // Settings unreachable: keep the server default rather than dying.
}

$dryRun = false;
$today = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--today=')) {
        $today = substr($arg, strlen('--today='));
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        fwrite(STDERR, "Usage: php apply_installment_fees.php [--dry-run] [--today=YYYY-MM-DD]\n");
        exit(1);
    }
}

try {
    $result = Billing::applyAutomaticInstallmentFees(null, $today, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] installment fee sweep FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$prefix = $dryRun ? '[dry-run] ' : '';
foreach ($result['applied'] as $row) {
    echo $prefix . 'Charged ' . Billing::formatCents((int)$row['amount_cents'])
        . ' installment fee to ' . $row['student_name']
        . ' (user #' . $row['student_user_id'] . ', ' . $row['semester_label'] . ")\n";
}
echo '[' . date('Y-m-d H:i:s') . '] ' . $prefix . 'installment fee sweep: '
    . count($result['applied']) . ' applied, ' . $result['skipped'] . ' skipped, '
    . $result['semesters'] . " semester(s) checked\n";
exit(0);
