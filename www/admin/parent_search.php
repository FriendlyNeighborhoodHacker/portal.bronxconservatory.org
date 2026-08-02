<?php
// GET JSON: parent typeahead — {ok:true, items:[{id,label}]}. Anyone can be
// linked to a child as their parent, so each result says what that person
// already is ("parent", "teacher", "student") to tell two similar names apart.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
$q = trim((string)($_GET['q'] ?? ''));
$childUserId = (int)($_GET['child_user_id'] ?? 0);
$items = [];
foreach (StudentTeacherManagement::searchPeopleForParentLink($q, $childUserId ?: null) as $row) {
    $label = $row['first_name'] . ' ' . $row['last_name'];
    if (!empty($row['email'])) {
        $label .= ' (' . $row['email'] . ')';
    }
    $roles = array_keys(array_filter([
        'parent' => !empty($row['is_parent']),
        'teacher' => !empty($row['is_teacher']),
        'student' => !empty($row['is_student']),
    ]));
    if ($roles) {
        $label .= ' — ' . implode(', ', $roles);
    }
    $items[] = ['id' => (int)$row['id'], 'label' => $label];
}
echo json_encode(['ok' => true, 'items' => $items]);
