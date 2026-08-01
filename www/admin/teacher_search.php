<?php
// GET JSON: teacher typeahead — {ok:true, items:[{id,label}]}.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
$q = trim((string)($_GET['q'] ?? ''));
$items = [];
foreach (StudentTeacherManagement::searchTeachersByNamePrefix($q) as $row) {
    $label = $row['first_name'] . ' ' . $row['last_name'];
    if (!empty($row['email'])) {
        $label .= ' (' . $row['email'] . ')';
    }
    $items[] = ['id' => (int)$row['id'], 'label' => $label];
}
echo json_encode(['ok' => true, 'items' => $items]);
