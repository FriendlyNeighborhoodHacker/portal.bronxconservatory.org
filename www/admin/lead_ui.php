<?php
// Shared display helpers for the Admin > Leads pages.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadManagement.php';

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

// "2 students — Lucia (Piano, 30 min), Marco (Violin, 60 min + Ensemble)"
function lead_students_summary(array $students): string {
    $parts = [];
    foreach ($students as $student) {
        $detail = $student['instrument'] . ', ' . (int)$student['lesson_length_minutes'] . ' min';
        if (!empty($student['guitar_ensemble'])) {
            $detail .= ' + Ensemble';
        }
        $parts[] = $student['first_name'] . ' (' . $detail . ')';
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
