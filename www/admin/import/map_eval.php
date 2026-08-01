<?php
// POST: apply the chosen mapping, validate every row, move to validation.
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
if (!$state || empty($state['headers'])) {
    header('Location: /admin/import/upload.php' . import_query_string($flow));
    exit;
}

$mappingInput = $_POST['mapping'] ?? [];
$flowClass = $flow['class'];
$validFields = array_keys($flowClass::targetFields());

$mapping = [];
foreach (array_keys($state['headers']) as $i) {
    $field = (string)($mappingInput[$i] ?? '');
    $mapping[$i] = in_array($field, $validFields, true) ? $field : '';
}

try {
    if (!array_filter($mapping, fn($f) => $f !== '')) {
        throw new InvalidArgumentException('Map at least one column to a field.');
    }
    $mappedRows = CsvImport::applyMapping($state['rows'], $mapping);
    $context = $flow['semester_id'] !== null ? ['semester_id' => (int)$flow['semester_id']] : [];
    $validated = $flowClass::validateRows($mappedRows, $context);

    // The mapped data replaces the original CSV from here on (per guideline).
    $_SESSION[import_session_key($flow)] = [
        'headers' => $state['headers'],
        'rows' => $state['rows'],
        'mapping' => $mapping,
        'validated' => $validated,
    ];
    header('Location: /admin/import/validate.php' . import_query_string($flow));
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = $e->getMessage();
    $_SESSION[import_session_key($flow)]['mapping'] = $mapping;
    header('Location: /admin/import/map.php' . import_query_string($flow));
}
exit;
