<?php
// POST: apply the selected database migrations.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_developer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/migrations.php');
    exit;
}
require_csrf();

if (!MigrationRunner::isConfigured()) {
    $_SESSION['migrations_flash_error'] = 'Migrations are not enabled on this server (MIGRATIONS_DIR is not set).';
    header('Location: /admin/migrations.php');
    exit;
}

$requested = $_POST['filenames'] ?? [];
if (!is_array($requested)) {
    $requested = [];
}
$requested = array_values(array_filter(array_map('strval', $requested), fn($s) => $s !== ''));

if (empty($requested)) {
    $_SESSION['migrations_flash_error'] = 'No migrations were selected.';
    header('Location: /admin/migrations.php');
    exit;
}

try {
    $result = MigrationRunner::apply($requested, UserContext::getLoggedInUserContext());
} catch (\Throwable $e) {
    $_SESSION['migrations_flash_error'] = $e->getMessage();
    header('Location: /admin/migrations.php');
    exit;
}

$appliedCount = count($result['applied']);

if ($result['failed'] !== null) {
    // Some may have applied before the failure — report both.
    $msg = 'Migration failed on ' . $result['failed']['filename'] . ': ' . $result['failed']['error'];
    if ($appliedCount > 0) {
        $msg = $appliedCount . ' migration' . ($appliedCount === 1 ? '' : 's') . ' applied, then ' . $msg;
    }
    $_SESSION['migrations_flash_error'] = $msg;
} elseif ($appliedCount === 0) {
    $_SESSION['migrations_flash_error'] = 'Nothing to apply — the selected migrations were already applied.';
} else {
    $_SESSION['migrations_flash'] = $appliedCount . ' migration' . ($appliedCount === 1 ? '' : 's') . ' applied.';
}

header('Location: /admin/migrations.php');
exit;
