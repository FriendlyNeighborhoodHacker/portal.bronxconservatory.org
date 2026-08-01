<?php
// Shared scaffolding for the multistep CSV import flows (per
// docs/php-guidelines.md "Import Flows"): Upload -> Mapping -> Validation ->
// Commit. Three flows share the generic step pages in this directory,
// selected by ?flow=:
//   teachers           — create/update teachers (TeacherCsvImport)
//   location_dates     — a semester's class dates   (LocationDatesCsvImport)
//   location_teachers  — a semester's teacher-location pairs (LocationTeachersCsvImport)
//   hold_blocks        — teachers' standing non-lesson time (HoldBlocksCsvImport)
//   reservations       — a semester's whole schedule (SemesterReservationsCsvImport)
//   ledger_entries     — opening charges and payments (LedgerEntriesCsvImport)
// In-progress state (parsed rows, mapping, validation) lives in
// $_SESSION['import_<flow>'].
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/CsvImport.php';
require_once __DIR__ . '/../../lib/TeacherCsvImport.php';
require_once __DIR__ . '/../../lib/PeopleCsvImport.php';
require_once __DIR__ . '/../../lib/LocationCsvImport.php';
require_once __DIR__ . '/../../lib/LocationDatesCsvImport.php';
require_once __DIR__ . '/../../lib/LocationTeachersCsvImport.php';
require_once __DIR__ . '/../../lib/HoldBlocksCsvImport.php';
require_once __DIR__ . '/../../lib/SemesterReservationsCsvImport.php';
require_once __DIR__ . '/../../lib/LedgerEntriesCsvImport.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';

function import_flows(): array {
    return [
        'locations' => [
            'title' => 'Import Locations',
            'class' => 'LocationCsvImport',
            'needs_semester' => false,
            'default_next' => '/admin/locations.php',
        ],
        'teachers' => [
            'title' => 'Import Teachers',
            'class' => 'TeacherCsvImport',
            'needs_semester' => false,
            'default_next' => '/admin/teachers.php',
        ],
        'people' => [
            'title' => 'Import Students & Parents',
            'class' => 'PeopleCsvImport',
            'needs_semester' => false,
            'default_next' => '/admin/students.php',
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
        'hold_blocks' => [
            'title' => 'Import Hold Blocks',
            'class' => 'HoldBlocksCsvImport',
            'needs_semester' => true,
            'default_next' => '/admin/semesters.php',
        ],
        'reservations' => [
            'title' => 'Import Schedule',
            'class' => 'SemesterReservationsCsvImport',
            'needs_semester' => true,
            'default_next' => '/admin/semesters.php',
        ],
        'ledger_entries' => [
            'title' => 'Import Charges & Payments',
            'class' => 'LedgerEntriesCsvImport',
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

/**
 * Per-flow instructions for the upload step: which columns to include
 * (required vs. optional, accepted formats) and an example CSV. Column names
 * don't have to match exactly — the mapping step lets the admin fix them —
 * but using these names maps everything automatically.
 */
function import_columns_help_html(array $flow): string {
    $columns = [];
    $example = '';
    $intro = '';

    switch ($flow['key']) {
        case 'locations':
            $intro = 'One row per teaching location. Rows are matched to existing locations by '
                . 'name, so re-importing updates addresses instead of creating duplicates. These '
                . 'exact names are what the semester\'s class-dates and teacher-assignment CSVs '
                . 'must reference later — importing them here keeps everything consistent.';
            $columns = [
                ['Location Name', 'required', 'used everywhere the location is referenced'],
                ['Address', 'optional', ''],
                ['Status', 'optional', '"active" or "inactive" — blank means active'],
            ];
            $example = "Location Name,Address\n"
                . "Access Bronx Charter School,\"1180 Rev. James A. Polite Ave, Bronx, NY 10459\"\n"
                . "Bronx Community College,\"2155 University Ave, Bronx, NY 10453\"";
            break;

        case 'teachers':
            $intro = 'One row per teacher. First and last name are required, plus an email '
                . 'or a cell phone number (used to match people who are already in the system).';
            $columns = [
                ['First Name', 'required', ''],
                ['Last Name', 'required', ''],
                ['Email', 'required*', '*either Email or Cell Phone Number'],
                ['Cell Phone Number', 'required*', '*either Email or Cell Phone Number'],
                ['Suffix', 'optional', ''],
                ['Preferred Name', 'optional', ''],
                ['Secondary Email', 'optional', ''],
                ['Home Phone', 'optional', ''],
                ['Address Street 1', 'optional', ''],
                ['Address Street 2', 'optional', ''],
                ['Address City', 'optional', ''],
                ['Address State', 'optional', ''],
                ['Address Zip', 'optional', ''],
                ['Emergency Contact Name', 'optional', ''],
                ['Emergency Contact Phone', 'optional', ''],
                ['Shirt Size', 'optional', ''],
            ];
            $example = "First Name,Last Name,Email,Cell Phone Number\n"
                . "Marisol,Vega,marisol@example.org,718-555-0101\n"
                . "James,Okafor,james@example.org,718-555-0102";
            break;

        case 'people':
            $intro = 'One file for the whole roster: one row per person — students AND their '
                . 'parents share the same columns. A student row\'s "Parents" column lists who '
                . 'their parents are (names or emails, separated by ; or ,) — each must match '
                . 'another row in this file or a person already in the system. Anyone listed as '
                . 'a parent becomes a parent; everyone else becomes a student. Row order doesn\'t '
                . 'matter, and siblings naturally share the same parent rows.';
            $columns = [
                ['First Name', 'required', ''],
                ['Last Name', 'required', ''],
                ['Email', 'optional', 'used to match existing people, and as a Parents identifier'],
                ['Cell Phone Number', 'optional', 'used to match existing people when present'],
                ['Parents', 'optional', 'e.g. "Rosa Ramos; Hector Ramos" or "rosa@example.org"'],
                ['Suffix', 'optional', ''],
                ['Preferred Name', 'optional', ''],
                ['Secondary Email', 'optional', ''],
                ['Home Phone', 'optional', ''],
                ['Address Street 1', 'optional', ''],
                ['Address Street 2', 'optional', ''],
                ['Address City', 'optional', ''],
                ['Address State', 'optional', ''],
                ['Address Zip', 'optional', ''],
                ['Date of Birth', 'optional', 'students — 2014-03-05 or 3/5/2014'],
                ['Class Of', 'optional', 'students — graduation year, e.g. 2031'],
                ['Grade', 'optional', 'students — e.g. 7'],
                ['School Name', 'optional', 'students'],
                ['Instruments', 'optional', 'students — separated by ; or , — e.g. "Piano; Violin"'],
            ];
            $example = "First Name,Last Name,Email,Class Of,Instruments,Parents\n"
                . "Rosa,Ramos,rosa@example.org,,,\n"
                . "Denise,Brown,denise.brown@example.org,,,\n"
                . "Lucia,Ramos,,2031,Piano,Rosa Ramos\n"
                . "Marco,Ramos,,2033,Violin,Rosa Ramos\n"
                . "Devon,Brown,,2029,\"Violin, Viola\",denise.brown@example.org";
            break;

        case 'location_dates':
            $intro = 'One row per class date per location. The location name must match one of '
                . 'this semester\'s active locations. Inactive rows are breaks/holidays: no '
                . 'lessons are generated for them, and the notes text is shown to families.';
            $columns = [
                ['Location Name', 'required', 'must match an active location for this semester'],
                ['Date', 'required', '2026-09-12 or 9/12/2026'],
                ['Start Time', 'required', '9:00 am, 4:30 PM, or 14:30'],
                ['End Time', 'required', 'same formats as Start Time'],
                ['Status', 'optional', '"active" or "inactive" — blank means active'],
                ['Notes', 'optional', 'e.g. "Day 1" or "Holiday Week" (shown for inactive dates)'],
            ];
            $example = "Location Name,Date,Start Time,End Time,Status,Notes\n"
                . "Bronx Community College,9/12/2026,9:00 am,5:00 pm,active,Day 1\n"
                . "Bronx Community College,9/19/2026,9:00 am,5:00 pm,inactive,Holiday Week";
            break;

        case 'location_teachers':
            $intro = 'One row per teacher-location pair: which teachers teach at which location '
                . 'this semester (these pairs become the Semester Schedule grid\'s columns). '
                . 'Teacher names must match teachers already in the system — upload teachers first.';
            $columns = [
                ['Teacher Name', 'required', 'first and last name, e.g. "Marisol Vega"'],
                ['Location Name', 'required', 'must match an active location for this semester'],
            ];
            $example = "Teacher Name,Location Name\n"
                . "Marisol Vega,Bronx Community College\n"
                . "James Okafor,Access Bronx Charter School";
            break;

        case 'hold_blocks':
            $intro = 'One row per standing block of a teacher\'s non-lesson time — lunch, a '
                . 'regular errand. Each row holds that slot on every class date this semester, '
                . 'so no student can be booked into it. The teacher must already be assigned to '
                . 'the location (import location teachers first).';
            $columns = [
                ['Teacher Name', 'required', 'first and last name, e.g. "Marisol Vega"'],
                ['Location Name', 'required', 'must match an active location for this semester'],
                ['Day', 'required', 'weekday name, e.g. "Saturday" (or 0=Sunday … 6=Saturday)'],
                ['Start Time', 'required', '12:00 pm, 12:00 PM, or 12:00'],
                ['End Time', 'required', 'same formats; must be after Start Time, at most 4 hours later'],
                ['Title', 'required', 'what the time is for, e.g. "Lunch"'],
            ];
            $example = "Teacher Name,Location Name,Day,Start Time,End Time,Title\n"
                . "Marisol Vega,Access Bronx Charter School,Saturday,12:00 pm,1:30 pm,Lunch\n"
                . "Andre Baptiste,Bronx Community College,Saturday,12:00 pm,1:30 pm,Lunch";
            break;

        case 'reservations':
            $intro = 'One row per weekly lesson slot — the whole semester schedule in one file, '
                . 'for moving a schedule you already run into the portal. Students and teachers '
                . 'must already exist, and the teacher must be assigned to the location (import '
                . 'class dates, location teachers and hold blocks first). Confirmed rows generate '
                . 'their lessons, but NO charges are posted for any row: balances carried over '
                . 'from your old system are loaded separately, so nobody is billed twice.';
            $columns = [
                ['Student Name', 'required', 'first and last name, e.g. "Lucia Ramos" (or their email)'],
                ['Teacher Name', 'required', 'first and last name, e.g. "Marisol Vega"'],
                ['Location Name', 'required', 'must match an active location for this semester'],
                ['Day', 'required', 'weekday name, e.g. "Saturday" (or 0=Sunday … 6=Saturday)'],
                ['Start Time', 'required', '10:00 am, 10:00 AM, or 10:00'],
                ['Duration Minutes', 'optional', 'e.g. 30, 45, 60 — blank means 30'],
                ['Status', 'optional', 'pending reach out (default), pending confirmation, or confirmed'],
            ];
            $example = "Student Name,Teacher Name,Location Name,Day,Start Time,Duration Minutes,Status\n"
                . "Lucia Ramos,Marisol Vega,Access Bronx Charter School,Saturday,10:00 am,30,confirmed\n"
                . "Devon Brown,Sofia Petrov,Access Bronx Charter School,Saturday,11:00 am,60,pending confirmation";
            break;

        case 'ledger_entries':
            $intro = 'Where every family\'s money already stands: one row per charge they ran up '
                . 'and per payment they made, on the date it actually happened. This is how balances '
                . 'come across when the portal takes over an existing roster — the schedule import '
                . 'deliberately posts no charges, so these rows are what makes the Semester Schedule '
                . 'colour-code who owes what. Every row belongs to the semester shown above. '
                . 'Re-uploading the same file changes nothing: rows already on the ledger are skipped.';
            $columns = [
                ['Student Name', 'required', 'first and last name, e.g. "Lucia Ramos" (or their email)'],
                ['Entry Type', 'required', 'registration, lessons, recital fee, payment, scholarship or other'],
                ['Amount', 'required', 'dollars, always positive — e.g. 425.00 or $425'],
                ['Date', 'optional', '8/15/2026 or 2026-08-15 — blank means the semester start date'],
                ['Debit or Credit', 'optional', 'charges default to debit, payments to credit; required for "other"'],
                ['Description', 'optional', 'e.g. "Check #1042" — a sensible default is used when blank'],
            ];
            $example = "Student Name,Entry Type,Amount,Date,Debit or Credit,Description\n"
                . "Lucia Ramos,registration,35.00,8/15/2026,,\n"
                . "Lucia Ramos,lessons,425.00,8/15/2026,,\n"
                . "Lucia Ramos,payment,475.00,8/20/2026,,Check #1042";
            break;
    }

    $html = '<div class="card"><h3>What to include</h3>';
    if ($intro !== '') {
        $html .= '<p class="small">' . h($intro) . '</p>';
    }
    $html .= '<table class="list"><thead><tr><th>Column</th><th></th><th>Notes</th></tr></thead><tbody>';
    foreach ($columns as [$name, $requirement, $note]) {
        $html .= '<tr><td><code>' . h($name) . '</code></td>'
            . '<td class="small">' . h($requirement) . '</td>'
            . '<td class="small">' . h($note) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p class="small" style="margin-bottom:4px;">Example:</p>'
        . '<pre class="import-example">' . h($example) . '</pre>'
        . '<p class="small" style="margin-bottom:0;">The first line must be the header row. '
        . 'Different column names are fine — you can fix the mapping in the next step.</p>';
    $html .= '</div>';
    return $html;
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
