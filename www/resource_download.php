<?php
// Authorization-checked download of a lesson resource (private_files are
// never publicly served). ResourceManagement::canUserDownload decides.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ResourceManagement.php';
require_once __DIR__ . '/lib/Files.php';
Application::init();
require_login();

$me = current_user();
$resourceId = (int)($_GET['id'] ?? 0);
if ($resourceId <= 0) {
    http_response_code(400);
    die('Bad request');
}

$resource = ResourceManagement::find($resourceId);
if (!$resource) {
    http_response_code(404);
    die('Not found');
}
if (!ResourceManagement::canUserDownload((int)$me['id'], $resourceId)) {
    http_response_code(403);
    die('You do not have access to this material');
}

$file = Files::getPrivateFileForDownload((int)$resource['private_file_id']);
if (!$file) {
    http_response_code(404);
    die('File missing');
}

header('Content-Type: ' . ($file['content_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . strlen($file['data']));
header('Content-Disposition: inline; filename="' . rawurlencode((string)($file['original_filename'] ?: 'material')) . '"');
header('X-Content-Type-Options: nosniff');
echo $file['data'];
