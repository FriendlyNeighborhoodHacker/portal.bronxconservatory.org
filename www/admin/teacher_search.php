<?php
// GET JSON: teacher typeahead — {ok:true, items:[{id,label}]}.
//
// The label carries the locations the teacher works this semester, because the
// place you most need this list is when picking a substitute: "Grace Lin —
// Bronx Community College" answers the real question, which is whether she is
// even at the right building.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
$q = trim((string)($_GET['q'] ?? ''));

// teacher id => the locations they are assigned to this semester.
$locationsByTeacher = [];
$semesterId = Application::adminSelectedSemesterId();
if ($semesterId !== null) {
    foreach (SemesterManagement::locationTeachers($semesterId) as $column) {
        $locationsByTeacher[(int)$column['teacher_user_id']][(string)$column['location_name']] = true;
    }
}

$items = [];
foreach (StudentTeacherManagement::searchTeachersByNamePrefix($q) as $row) {
    $label = $row['first_name'] . ' ' . $row['last_name'];
    $locations = array_keys($locationsByTeacher[(int)$row['id']] ?? []);
    if ($locations) {
        $label .= ' — ' . implode(', ', $locations);
    } elseif ($semesterId !== null) {
        $label .= ' — not at any location this semester';
    }
    $items[] = ['id' => (int)$row['id'], 'label' => $label];
}
echo json_encode(['ok' => true, 'items' => $items]);
