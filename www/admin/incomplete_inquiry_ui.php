<?php
// Shared display helpers for the Admin > Uncompleted Forms pages, mirroring
// lead_ui.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/InquiryManagement.php';

// How far the visitor got before they stopped — the one thing that decides
// what to say when you call them.
function incomplete_inquiry_stage_label(array $row): string {
    return (int)($row['last_step_completed'] ?? 1) >= 2
        ? 'Contact and address'
        : 'Contact only';
}
