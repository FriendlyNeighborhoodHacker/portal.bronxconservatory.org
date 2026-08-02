<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function header_html(string $title, array $options = []): void {
    ApplicationUI::headerHtml($title, $options);
}

function footer_html(): void {
    ApplicationUI::footerHtml();
}

// Stable avatar color for a person's name (child cards, rosters). Drawn
// around the BCM blue and coral (docs/app_spec.md "Design Notes") rather than
// a generic rainbow, and every one is dark enough to carry white initials.
function person_avatar_color(string $name): string {
    $palette = ['#0062DC', '#C2410C', '#6D28D9', '#0F766E', '#B45309', '#9D174D', '#166534', '#00132A'];
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

// A lesson's display name: "Violin Lesson", or "Lesson" when no instrument
// is known. $lesson needs an instrument_name key.
function lesson_name_label(array $lesson): string {
    $instrument = trim((string)($lesson['instrument_name'] ?? ''));
    return $instrument !== '' ? $instrument . ' Lesson' : 'Lesson';
}
