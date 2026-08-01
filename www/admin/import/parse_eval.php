<?php
// POST: parse the uploaded/pasted CSV and move to the mapping step.
require_once __DIR__ . '/import_ui.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semesters.php');
    exit;
}
require_csrf();
$flow = import_current_flow();

$text = '';
if (!empty($_FILES['csv_file']['tmp_name']) && (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $text = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
} else {
    $text = (string)($_POST['csv_text'] ?? '');
}
$delimiter = ($_POST['delimiter'] ?? 'comma') === 'tab' ? "\t" : ',';

try {
    $parsed = CsvImport::parseCsv($text, $delimiter);
    if (!$parsed['rows']) {
        throw new InvalidArgumentException('The file has a header but no data rows.');
    }
    $flowClass = $flow['class'];
    $_SESSION[import_session_key($flow)] = [
        'headers' => $parsed['headers'],
        'rows' => $parsed['rows'],
        'mapping' => CsvImport::suggestColumnMapping($parsed['headers'], $flowClass::targetFields()),
    ];
    header('Location: /admin/import/map.php' . import_query_string($flow));
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = $e->getMessage();
    header('Location: /admin/import/upload.php' . import_query_string($flow));
}
exit;
