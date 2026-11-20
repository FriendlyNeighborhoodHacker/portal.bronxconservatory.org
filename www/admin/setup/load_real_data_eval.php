<?php
// POST (developers only): wipe the database and load the real-data directory
// named by REAL_DATA_DIR in config.local.php, then return to the setup page
// with a summary. The card on setup/index.php offers this only where that
// constant is set; both conditions and the typed confirmation are enforced
// again here so the URL alone is never enough.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/RealDataLoader.php';
Application::init();
require_developer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/setup/index.php');
    exit;
}
require_csrf();

if (!RealDataLoader::isAvailable()) {
    http_response_code(403);
    die('Real data can only be loaded where REAL_DATA_DIR in config.local.php points at the real-data directory.');
}

if (trim((string)($_POST['confirm'] ?? '')) !== 'RESET') {
    $_SESSION['import_flash_error'] = 'Nothing was changed: type RESET in the box to confirm.';
    header('Location: /admin/setup/index.php#real-data');
    exit;
}

// Thousands of lessons are generated; the default 30 seconds is not enough on a slow database.
@set_time_limit(600);
ignore_user_abort(true);

try {
    $result = RealDataLoader::load(UserContext::getLoggedInUserContext(), RealDataLoader::dataDir(), true);
    // Land the admin on the first semester (the one with the schedule).
    $_SESSION['admin_semester_id'] = (int)($result['semesters'][0] ?? 0);
    $_SESSION['import_flash'] = RealDataLoader::summarize($result);
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = 'Real data load stopped: ' . $e->getMessage()
        . ' Fix the file and run it again — the load starts with a reset, so a second run is clean.';
}
header('Location: /admin/setup/index.php#real-data');
exit;
