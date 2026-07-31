<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function header_html(string $title): void {
    ApplicationUI::headerHtml($title);
}

function footer_html(): void {
    ApplicationUI::footerHtml();
}

// Stable avatar color for a person's name (child cards, rosters).
function person_avatar_color(string $name): string {
    $palette = ['#579bfc', '#00c875', '#a25ddc', '#fdab3d', '#e2445c', '#0086c0', '#9d99b9', '#037f4c'];
    return $palette[crc32($name) % count($palette)];
}

// Colored initials circle + name. Empty name renders the $emptyLabel fallback.
function person_chip_html(?string $first, ?string $last, string $emptyLabel = 'Unassigned'): string {
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    if ($name === '') return '<span class="unassigned">' . h($emptyLabel) . '</span>';
    $initials = strtoupper(mb_substr($first ?? '', 0, 1) . mb_substr($last ?? '', 0, 1));
    if ($initials === '') $initials = strtoupper(mb_substr($name, 0, 1));
    return '<span class="assignee"><span class="assignee-avatar" style="background:' . h(person_avatar_color($name)) . '">' . h($initials)
        . '</span><span class="assignee-name">' . h($name) . '</span></span>';
}

// "Sat, Apr 11 · 9:00–9:30 AM" for a lesson row (start_datetime + duration_minutes).
function lesson_time_html(string $startDatetime, int $durationMinutes): string {
    $start = strtotime($startDatetime);
    $end = $start + $durationMinutes * 60;
    $sameMeridiem = date('A', $start) === date('A', $end);
    $startLabel = date('g:i', $start) . ($sameMeridiem ? '' : ' ' . date('A', $start));
    return h(date('D, M j', $start) . ' · ' . $startLabel . '–' . date('g:i A', $end));
}

// Where a lesson happens: "Online" (with icon), "Room 12 · Bronx Community
// College", or an em dash when nothing is set. $lesson needs is_online, room,
// location_name keys.
function lesson_place_html(array $lesson): string {
    if (!empty($lesson['is_online'])) {
        return '<span class="online-tag" title="Online lesson">&#128187; Online</span>';
    }
    $parts = array_filter([
        trim((string)($lesson['room'] ?? '')) !== '' ? 'Room ' . $lesson['room'] : '',
        (string)($lesson['location_name'] ?? ''),
    ]);
    return $parts ? h(implode(' · ', $parts)) : '<span class="small">—</span>';
}

// A lesson's display name: the group class name, or "Violin Lesson" /
// "Lesson" for individual lessons. $lesson needs lesson_type, name,
// instrument_name keys.
function lesson_name_label(array $lesson): string {
    if ($lesson['lesson_type'] === 'group' && trim((string)($lesson['name'] ?? '')) !== '') {
        return (string)$lesson['name'];
    }
    $instrument = trim((string)($lesson['instrument_name'] ?? ''));
    return $instrument !== '' ? $instrument . ' Lesson' : 'Lesson';
}

// The family status pill used across admin pages.
function family_status_html(string $status): string {
    $labels = [
        'needs_follow_up' => 'Needs Follow-Up',
        'ready_to_enroll' => 'Ready to Enroll',
        'schedule_assigned' => 'Schedule Assigned',
        'enrolled' => 'Enrolled',
    ];
    $label = $labels[$status] ?? $status;
    return '<span class="status-pill status-' . h($status) . '">' . h($label) . '</span>';
}
