<?php
// POST (dev instances only): load every file in sample_data/ through the
// real importers in README order, then return to the setup page with a
// summary. Offered by setup/index.php only when DEV_SITE is on; enforced
// again here so the URL alone is not enough on production.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SampleDataLoader.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/setup/index.php');
    exit;
}
require_csrf();

if (!SampleDataLoader::isAvailable()) {
    http_response_code(403);
    die('Sample data can only be loaded on a dev instance (DEV_SITE in config.local.php).');
}

try {
    $result = SampleDataLoader::load(UserContext::getLoggedInUserContext());
    // Land the admin on the semester the schedule file filled.
    $_SESSION['admin_semester_id'] = (int)$result['semesters']['fall_semester_id'];
    $_SESSION['import_flash'] = SampleDataLoader::summarize($result);
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = 'Sample data load stopped: ' . $e->getMessage();
}
header('Location: /admin/setup/index.php');
exit;
