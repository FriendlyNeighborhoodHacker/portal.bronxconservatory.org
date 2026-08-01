<?php
// Shared scaffolding for the multistep CSV import flows (per
// docs/php-guidelines.md "Import Flows"): Upload -> Mapping -> Validation ->
// Commit. Three flows share the generic step pages in this directory,
// selected by ?flow=:
//   teachers           — create/update teachers (TeacherCsvImport)
//   location_dates     — a semester's class dates   (LocationDatesCsvImport)
//   location_teachers  — a semester's teacher-location pairs (LocationTeachersCsvImport)
// In-progress state (parsed rows, mapping, validation) lives in
// $_SESSION['import_<flow>'].
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/CsvImport.php';
require_once __DIR__ . '/../../lib/TeacherCsvImport.php';
require_once __DIR__ . '/../../lib/LocationDatesCsvImport.php';
require_once __DIR__ . '/../../lib/LocationTeachersCsvImport.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';

function import_flows(): array {
    return [
        'teachers' => [
            'title' => 'Import Teachers',
            'class' => 'TeacherCsvImport',
            'needs_semester' => false,
            'default_next' => '/admin/teachers.php',
        ],
        'location_dates' => [
            'title' => 'Import Location Dates',
            'class' => 'LocationDatesCsvImport',
            'needs_semester' => true,
            'default_next' => '/admin/semesters.php',
        ],
        'location_teachers' => [
            'title' => 'Import Location Teachers',
            'class' => 'LocationTeachersCsvImport',
            'needs_semester' => true,
            'default_next' => '/admin/semesters.php',
        ],
    ];
}

/** Resolve ?flow= (or POSTed flow) or die with a 404. */
function import_current_flow(): array {
    $key = (string)($_GET['flow'] ?? $_POST['flow'] ?? '');
    $flows = import_flows();
    if (!isset($flows[$key])) {
        http_response_code(404);
        die('Unknown import flow.');
    }
    $flow = $flows[$key] + ['key' => $key];

    if ($flow['needs_semester']) {
        $semesterId = (int)($_GET['semester_id'] ?? $_POST['semester_id'] ?? 0);
        if ($semesterId <= 0 || !SemesterManagement::find($semesterId)) {
            http_response_code(400);
            die('This import needs a valid semester_id.');
        }
        $flow['semester_id'] = $semesterId;
    } else {
        $flow['semester_id'] = null;
    }

    $next = validate_relative_next_path($_GET['next'] ?? $_POST['next'] ?? '');
    $flow['next'] = $next !== '' ? $next : $flow['default_next'];
    return $flow;
}

function import_session_key(array $flow): string {
    return 'import_' . $flow['key'];
}

/** Hidden fields every step's form carries so the flow context survives. */
function import_hidden_fields_html(array $flow): string {
    $html = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
          . '<input type="hidden" name="flow" value="' . h($flow['key']) . '">'
          . '<input type="hidden" name="next" value="' . h($flow['next']) . '">';
    if ($flow['semester_id'] !== null) {
        $html .= '<input type="hidden" name="semester_id" value="' . (int)$flow['semester_id'] . '">';
    }
    return $html;
}

/** ?flow=...&semester_id=...&next=... for step links/redirects. */
function import_query_string(array $flow): string {
    $params = ['flow' => $flow['key']];
    if ($flow['semester_id'] !== null) {
        $params['semester_id'] = (string)$flow['semester_id'];
    }
    if ($flow['next'] !== $flow['default_next']) {
        $params['next'] = $flow['next'];
    }
    return '?' . http_build_query($params);
}

/** The step indicator bar (1 Upload · 2 Mapping · 3 Validation · 4 Commit). */
function import_steps_html(array $flow, int $current): string {
    $steps = ['Upload', 'Mapping', 'Validation', 'Commit'];
    $html = '<div class="wizard-steps">';
    foreach ($steps as $i => $label) {
        $n = $i + 1;
        $class = 'wizard-step' . ($n === $current ? ' active' : ($n < $current ? ' done' : ''));
        $html .= '<span class="' . $class . '"><span class="wizard-step-num">' . $n . '</span> ' . h($label) . '</span>';
    }
    $html .= '</div>';
    $context = '';
    if ($flow['semester_id'] !== null) {
        $semester = SemesterManagement::find((int)$flow['semester_id']);
        if ($semester) {
            $context = '<p class="small">For semester: <strong>' . h(SemesterManagement::label($semester)) . '</strong></p>';
        }
    }
    return $html . $context;
}
