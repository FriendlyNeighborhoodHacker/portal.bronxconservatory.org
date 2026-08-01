<?php
// POST (import step 4): commit the validated rows, clear state, move on.
require_once __DIR__ . '/import_ui.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semesters.php');
    exit;
}
require_csrf();
$flow = import_current_flow();
$state = $_SESSION[import_session_key($flow)] ?? null;
if (!$state || !isset($state['validated'])) {
    header('Location: /admin/import/upload.php' . import_query_string($flow));
    exit;
}

$flowClass = $flow['class'];
$context = $flow['semester_id'] !== null ? ['semester_id' => (int)$flow['semester_id']] : [];

try {
    $summary = $flowClass::commit(UserContext::getLoggedInUserContext(), $state['validated'], $context);
    unset($_SESSION[import_session_key($flow)]);
    $parts = [];
    if ($summary['created']) $parts[] = $summary['created'] . ' created';
    if ($summary['updated']) $parts[] = $summary['updated'] . ' updated';
    if ($summary['skipped']) $parts[] = $summary['skipped'] . ' skipped';
    $_SESSION['import_flash'] = $flow['title'] . ' complete: ' . ($parts ? implode(', ', $parts) : 'nothing to do') . '.';
    header('Location: ' . $flow['next']);
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = 'Import failed: ' . $e->getMessage();
    header('Location: /admin/import/validate.php' . import_query_string($flow));
}
exit;
