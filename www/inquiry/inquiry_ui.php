<?php
// Shared scaffolding for the public "Request More Information" flow, modeled
// on register/register_ui.php: session-held state across the four pages, step
// guards, the step header, and the flash-error helpers. No login required —
// Application::init() gives the anonymous session, so csrf_token() and
// require_csrf() work as-is.
//
// Unlike registration there is no "is it open?" gate: an information request
// is always welcome, which is the whole point of the flow.
//
// The wizard state lives under its own session key. A visitor can be part way
// through registration when they decide to ask a question instead, and the two
// flows must not overwrite each other.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../recaptcha.php';
require_once __DIR__ . '/../lib/InquiryManagement.php';
require_once __DIR__ . '/../lib/LeadManagement.php';

const INQUIRY_STEPS = ['Contact', 'Address', 'Student', 'Details'];

// ===== Wizard state ($_SESSION['inquiry_wizard']) =====

function inquiry_state(): array {
    return (array)($_SESSION['inquiry_wizard'] ?? [
        'contact' => [],
        'address' => [],
        'student' => [],
        'details' => [],
        'max_step' => 0, // highest COMPLETED step (1-4)
    ]);
}

function inquiry_save(array $state): void {
    $_SESSION['inquiry_wizard'] = $state;
}

// Clears the form state but deliberately NOT inquiry_lead_id — done.php needs
// it to know a submission actually happened.
function inquiry_reset(): void {
    unset($_SESSION['inquiry_wizard'], $_SESSION['inquiry_draft_id']);
}

// Step numbers: 1 contact, 2 address, 3 student, 4 details.
// Forward jumps redirect to the first incomplete step; going back is free.
function inquiry_require_step(int $step): array {
    $state = inquiry_state();
    $maxAllowed = (int)$state['max_step'] + 1;
    if ($step > $maxAllowed) {
        header('Location: ' . inquiry_step_url($maxAllowed));
        exit;
    }
    return $state;
}

function inquiry_step_url(int $step): string {
    $urls = [
        1 => '/inquiry/contact.php',
        2 => '/inquiry/address.php',
        3 => '/inquiry/student.php',
        4 => '/inquiry/details.php',
    ];
    return $urls[$step] ?? $urls[1];
}

function inquiry_mark_completed(array $state, int $step): array {
    $state['max_step'] = max((int)$state['max_step'], $step);
    return $state;
}

// The step-indicator bar (same CSS the registration and admin wizards use).
function inquiry_steps_html(int $current): string {
    $html = '<div class="wizard-steps">';
    foreach (INQUIRY_STEPS as $i => $label) {
        $n = $i + 1;
        $class = 'wizard-step' . ($n === $current ? ' active' : ($n < $current ? ' done' : ''));
        $html .= '<span class="' . $class . '"><span class="wizard-step-num">' . $n . '</span> ' . h($label) . '</span>';
    }
    return $html . '</div>';
}

// One-shot flash set by the _eval pages on validation failure.
function inquiry_flash_error(): ?string {
    $error = $_SESSION['inquiry_error'] ?? null;
    unset($_SESSION['inquiry_error']);
    return $error;
}

function inquiry_fail(string $message, string $backTo): void {
    $_SESSION['inquiry_error'] = $message;
    header('Location: ' . $backTo);
    exit;
}

// The uncompleted form this visitor is filling in (pages 1-2), and the lead it
// became (pages 3-4). Both are held server-side and never posted, so one
// visitor can never address another visitor's record.
function inquiry_draft_id(): ?int {
    $id = (int)($_SESSION['inquiry_draft_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function inquiry_lead_id(): ?int {
    $id = (int)($_SESSION['inquiry_lead_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// ===== Small form helpers =====

// A checked="checked" attribute when $value is among the saved answers.
function inquiry_checked(array $saved, string $key, string $value): string {
    return in_array($value, (array)($saved[$key] ?? []), true) ? ' checked' : '';
}

function inquiry_radio_checked(array $saved, string $key, string $value): string {
    return (string)($saved[$key] ?? '') === $value ? ' checked' : '';
}

function inquiry_value(array $saved, string $key, string $default = ''): string {
    $value = $saved[$key] ?? null;
    return $value === null || $value === '' ? $default : (string)$value;
}
