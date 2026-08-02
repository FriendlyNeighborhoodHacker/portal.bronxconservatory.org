<?php
// Shared display helpers for the Admin > Leads pages.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadManagement.php';
require_once __DIR__ . '/../lib/InquiryManagement.php';

function lead_status_html(string $status): string {
    $label = LeadManagement::STATUS_LABELS[$status] ?? $status;
    return '<span class="status-pill lead-' . h($status) . '">' . h($label) . '</span>';
}

function lead_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

function lead_semester_label(array $lead): string {
    if (empty($lead['season']) || empty($lead['year'])) {
        return '—';
    }
    return ucfirst((string)$lead['season']) . ' ' . $lead['year'];
}

function lead_is_inquiry(array $lead): bool {
    return (string)($lead['source'] ?? 'registration') === 'inquiry';
}

function lead_source_html(array $lead): string {
    $source = (string)($lead['source'] ?? 'registration');
    $label = LeadManagement::SOURCE_LABELS[$source] ?? $source;
    return '<span class="status-pill lead-source-' . h($source) . '">' . h($label) . '</span>';
}

// A JSON-array column as a readable list, folding an "Other" entry into the
// free text that went with it. Empty stays empty so callers can show a dash.
function lead_json_list(?string $json, ?string $other = null): string {
    $items = json_decode((string)($json ?? '[]'), true);
    $items = is_array($items) ? array_map('strval', $items) : [];
    if ($other !== null && trim($other) !== '') {
        $items = array_map(fn($i) => $i === 'Other' ? 'Other: ' . trim($other) : $i, $items);
    }
    return implode(', ', $items);
}

// What a lead student wants, however they said it: a registration lead names
// one instrument and a lesson length, an inquiry lead lists interests and has
// decided neither.
function lead_student_wants(array $student): string {
    if (trim((string)($student['instrument'] ?? '')) !== '') {
        $detail = (string)$student['instrument'];
        if (!empty($student['lesson_length_minutes'])) {
            $detail .= ', ' . (int)$student['lesson_length_minutes'] . ' min';
        }
        if (!empty($student['guitar_ensemble'])) {
            $detail .= ' + Ensemble';
        }
        return $detail;
    }
    $interests = lead_json_list($student['instruments_of_interest'] ?? null, $student['instruments_other'] ?? null);
    return $interests !== '' ? 'interested in ' . $interests : 'no instrument yet';
}

// "2 students — Lucia (Piano, 30 min), Marco (Violin, 60 min + Ensemble)"
function lead_students_summary(array $students): string {
    $parts = [];
    foreach ($students as $student) {
        $parts[] = $student['first_name'] . ' (' . lead_student_wants($student) . ')';
    }
    return count($students) . ' student' . (count($students) === 1 ? '' : 's')
        . ($parts ? ' — ' . implode(', ', $parts) : '');
}

function lead_paid_badge_html(array $lead): string {
    if ((int)$lead['amount_paid_cents'] <= 0) {
        return '';
    }
    return '<span class="lead-paid-badge">Paid ' . h(lead_dollars((int)$lead['amount_paid_cents'])) . '</span>';
}

// The mailing address on one line, skipping whatever is missing — an inquiry
// may have stopped before the address page.
function lead_address_line(array $row): string {
    $street = trim(($row['address_street_1'] ?? '') . ' ' . ($row['address_street_2'] ?? ''));
    $cityLine = trim(implode(' ', array_filter([
        trim((string)($row['address_city'] ?? '')) !== '' ? $row['address_city'] . ',' : '',
        (string)($row['address_state'] ?? ''),
        (string)($row['address_zip'] ?? ''),
    ], fn($p) => trim((string)$p) !== '')));
    $country = trim((string)($row['address_country'] ?? ''));
    if ($country === InquiryManagement::DEFAULT_COUNTRY) {
        $country = ''; // The usual case; only worth saying when it is not.
    }
    $parts = array_filter([$street, $cityLine, $country], fn($p) => trim((string)$p) !== '');
    return $parts ? implode(' · ', $parts) : '—';
}
