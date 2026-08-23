<?php
// Shared display helpers for the Admin > Uncompleted Forms pages, mirroring
// lead_ui.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/InquiryManagement.php';
require_once __DIR__ . '/../lib/LeadManagement.php';

// How far the visitor got before they stopped — the one thing that decides
// what to say when you call them. The two forms count steps differently.
function incomplete_inquiry_stage_label(array $row): string {
    $step = (int)($row['last_step_completed'] ?? 1);
    if (($row['source'] ?? 'inquiry') === 'registration') {
        if ($step >= 5) return 'Payment plan chosen';
        if ($step >= 4) return 'Policies agreed';
        if ($step >= 3) return 'Students entered';
        if ($step >= 2) return 'Family info';
        return 'Email only';
    }
    return $step >= 2 ? 'Contact and address' : 'Contact only';
}

// Which public form the visitor was filling out.
function incomplete_inquiry_source_label(array $row): string {
    return ($row['source'] ?? 'inquiry') === 'registration' ? 'Registration' : 'Inquiry';
}

/**
 * The edit form's POST, split into the four groups the library takes.
 * Save and Complete post the same fields — they differ only in how much of it
 * has to be there — so both evaluators read it through here.
 */
function incomplete_inquiry_posted_form(): array {
    return [
        'contact' => [
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'newsletter_opt_in' => !empty($_POST['newsletter_opt_in']),
            'sms_consent' => !empty($_POST['sms_consent']),
        ],
        'address' => [
            'address_country' => trim((string)($_POST['address_country'] ?? InquiryManagement::DEFAULT_COUNTRY)),
            'address_street_1' => trim((string)($_POST['address_street_1'] ?? '')),
            'address_street_2' => trim((string)($_POST['address_street_2'] ?? '')),
            'address_city' => trim((string)($_POST['address_city'] ?? '')),
            'address_state' => trim((string)($_POST['address_state'] ?? '')),
            'address_province' => trim((string)($_POST['address_province'] ?? '')),
            'address_zip' => trim((string)($_POST['address_zip'] ?? '')),
        ],
        'student' => [
            'first_name' => trim((string)($_POST['student_first_name'] ?? '')),
            'last_name' => trim((string)($_POST['student_last_name'] ?? '')),
            'age' => trim((string)($_POST['student_age'] ?? '')),
            'enrollment_status' => (string)($_POST['enrollment_status'] ?? ''),
            'instruments_of_interest' => array_values(array_intersect(
                array_map('strval', (array)($_POST['instruments_of_interest'] ?? [])),
                LeadManagement::INQUIRY_INSTRUMENT_INTERESTS
            )),
            'instruments_other' => trim((string)($_POST['instruments_other'] ?? '')),
        ],
        'details' => [
            'semester_label' => (string)($_POST['semester_label'] ?? ''),
            'owned_instruments' => array_values(array_intersect(
                array_map('strval', (array)($_POST['owned_instruments'] ?? [])),
                LeadManagement::OWNED_INSTRUMENT_CHOICES
            )),
            'owned_instruments_other' => trim((string)($_POST['owned_instruments_other'] ?? '')),
            'music_background' => trim((string)($_POST['music_background'] ?? '')),
            'theory_program_interest' => (string)($_POST['theory_program_interest'] ?? ''),
            'theory_knowledge' => (string)($_POST['theory_knowledge'] ?? ''),
            'comments' => trim((string)($_POST['comments'] ?? '')),
            'referral_source' => (string)($_POST['referral_source'] ?? ''),
        ],
    ];
}

/**
 * Everything posted, flattened the way the form reads it back, so a rejected
 * attempt returns a page with every field still filled in.
 */
function incomplete_inquiry_old_input(array $form): array {
    return $form['contact'] + $form['address'] + $form['details'] + [
        'student_first_name' => $form['student']['first_name'],
        'student_last_name' => $form['student']['last_name'],
        'student_age' => $form['student']['age'],
        'enrollment_status' => $form['student']['enrollment_status'],
        'instruments_of_interest' => $form['student']['instruments_of_interest'],
        'instruments_other' => $form['student']['instruments_other'],
    ];
}
