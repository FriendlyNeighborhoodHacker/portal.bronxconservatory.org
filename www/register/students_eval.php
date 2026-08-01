<?php
// POST: step 2 actions — add/remove a student block, go back, or validate
// and continue. Everything is saved to the wizard state first so no answer
// is ever lost.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/students.php');
    exit;
}
require_csrf();

$action = (string)($_POST['action'] ?? 'next');

$students = [];
foreach ((array)($_POST['students'] ?? []) as $student) {
    $students[] = [
        'first_name' => trim((string)($student['first_name'] ?? '')),
        'last_name' => trim((string)($student['last_name'] ?? '')),
        'class_of' => trim((string)($student['class_of'] ?? '')),
        'instrument' => (string)($student['instrument'] ?? ''),
        'lesson_length_minutes' => (int)($student['lesson_length_minutes'] ?? 30),
        'guitar_ensemble' => !empty($student['guitar_ensemble']) ? 1 : 0,
    ];
}
if (!$students) {
    $students = [[]];
}

$scheduling = [
    'location_preference' => (string)($_POST['location_preference'] ?? ''),
    'preferred_days' => array_values((array)($_POST['preferred_days'] ?? [])),
    'availability_blocks' => array_values(array_intersect(
        (array)($_POST['availability_blocks'] ?? []),
        array_keys(LeadManagement::AVAILABILITY_BLOCKS)
    )),
    'notes' => trim((string)($_POST['scheduling_notes'] ?? '')),
];

$state = registration_state();
$state['students'] = $students;
$state['scheduling'] = $scheduling;

if ($action === 'add') {
    $state['students'][] = [];
    registration_save($state);
    header('Location: /register/students.php');
    exit;
}
if (str_starts_with($action, 'remove:')) {
    $index = (int)substr($action, 7);
    unset($state['students'][$index]);
    $state['students'] = array_values($state['students']) ?: [[]];
    registration_save($state);
    header('Location: /register/students.php');
    exit;
}
if ($action === 'back') {
    registration_save($state);
    header('Location: /register/family.php');
    exit;
}

// action=next — validate before moving on.
registration_save($state);

$complete = 0;
foreach ($students as $i => $student) {
    $blank = $student['first_name'] === '' && $student['last_name'] === '';
    if ($blank && count($students) > 1) {
        continue; // an untouched extra block is fine
    }
    if ($student['first_name'] === '' || $student['last_name'] === '') {
        registration_fail('Please enter student ' . ($i + 1) . '\'s full name.', '/register/students.php');
    }
    if (!in_array($student['instrument'], LeadManagement::INSTRUMENT_CHOICES, true)) {
        registration_fail('Please choose an instrument for student ' . ($i + 1) . '.', '/register/students.php');
    }
    // "Class of" is optional — only a value that was actually typed is checked.
    if ($student['class_of'] !== ''
        && (!preg_match('/^\d{4}$/', $student['class_of'])
            || (int)$student['class_of'] < 1900 || (int)$student['class_of'] > 2200)) {
        registration_fail(
            'Student ' . ($i + 1) . '\'s "Class of" should be a four-digit graduation year, for example '
                . ((int)date('Y') + 6) . ' — or leave it blank.',
            '/register/students.php'
        );
    }
    $complete++;
}
if ($complete === 0) {
    registration_fail('Please tell us about at least one student.', '/register/students.php');
}
if ($scheduling['location_preference'] === '') {
    registration_fail('Please choose a location preference.', '/register/students.php');
}
if (!$scheduling['availability_blocks']) {
    registration_fail('Please check at least one time block you can attend.', '/register/students.php');
}

registration_save(registration_mark_completed($state, 2));
header('Location: /register/policies.php');
exit;
