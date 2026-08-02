<?php
// Shared scaffolding for the public registration wizard (modeled on
// admin/import/import_ui.php): session-held state across the five steps,
// step guards, the wizard-steps header, pricing display helpers, and the
// reCAPTCHA hooks. No login required — Application::init() gives the
// anonymous session, so csrf_token()/require_csrf() work as-is.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../recaptcha.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/LeadManagement.php';

const REGISTRATION_STEPS = ['Family', 'Students', 'Policies', 'Payment Plan', 'Review', 'Checkout'];

// Absolute URL for a path on this site — Stripe needs a real return URL.
function registration_absolute_url(string $path): string {
    return Settings::absoluteUrl($path);
}

/**
 * The semester registration is open for, or renders the friendly "closed"
 * page and exits. Every wizard page calls this first.
 */
function registration_require_open(): array {
    $semesterId = Settings::registrationSemesterId();
    $semester = $semesterId !== null ? SemesterManagement::find($semesterId) : null;
    if ($semester) {
        return $semester;
    }
    ApplicationUI::minimalHeaderHtml('Registration');
    ?>
    <div style="max-width:560px;margin:0 auto;text-align:center;">
      <h1>Registration is currently closed</h1>
      <p>We're not taking online registrations right now — but we'd love to
        hear from you! Call us at
        <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> or email
        <a href="mailto:info@bronxconservatory.org">info@bronxconservatory.org</a>
        and we'll help you find a spot.</p>
      <p style="margin-top:24px;"><a class="btn-outline" href="/welcome.php">Back</a></p>
    </div>
    <?php
    ApplicationUI::minimalFooterHtml();
    exit;
}

function registration_semester_label(array $semester): string {
    return SemesterManagement::label($semester);
}

// ===== Wizard state ($_SESSION['registration_wizard']) =====

function registration_state(): array {
    return (array)($_SESSION['registration_wizard'] ?? [
        'family' => [],
        'students' => [[]], // one empty block to start
        'scheduling' => [],
        'policies_agreed' => false,
        'installment' => null, // null = unanswered
        'max_step' => 0,       // highest COMPLETED step (1-5)
    ]);
}

function registration_save(array $state): void {
    $_SESSION['registration_wizard'] = $state;
}

function registration_reset(): void {
    unset($_SESSION['registration_wizard']);
}

// Step numbers: 1 family, 2 students, 3 policies, 4 payment plan, 5 review.
// Forward jumps redirect to the first incomplete step; going back is free.
function registration_require_step(int $step): array {
    $state = registration_state();
    $maxAllowed = (int)$state['max_step'] + 1;
    if ($step > $maxAllowed) {
        header('Location: ' . registration_step_url($maxAllowed));
        exit;
    }
    return $state;
}

function registration_step_url(int $step): string {
    $urls = [
        1 => '/register/family.php',
        2 => '/register/students.php',
        3 => '/register/policies.php',
        4 => '/register/payment_plan.php',
        5 => '/register/review.php',
        6 => '/register/checkout.php',
    ];
    return $urls[$step] ?? $urls[1];
}

function registration_mark_completed(array $state, int $step): array {
    $state['max_step'] = max((int)$state['max_step'], $step);
    return $state;
}

// The step-indicator bar (same CSS the admin wizards use).
function registration_steps_html(int $current): string {
    $html = '<div class="wizard-steps">';
    foreach (REGISTRATION_STEPS as $i => $label) {
        $n = $i + 1;
        $class = 'wizard-step' . ($n === $current ? ' active' : ($n < $current ? ' done' : ''));
        $html .= '<span class="' . $class . '"><span class="wizard-step-num">' . $n . '</span> ' . h($label) . '</span>';
    }
    return $html . '</div>';
}

// One-shot flash set by the _eval pages on validation failure.
function registration_flash_error(): ?string {
    $error = $_SESSION['registration_error'] ?? null;
    unset($_SESSION['registration_error']);
    return $error;
}

function registration_fail(string $message, string $backTo): void {
    $_SESSION['registration_error'] = $message;
    header('Location: ' . $backTo);
    exit;
}

// The state's student blocks minus untouched blanks — what the quote and the
// final submission actually use.
function registration_clean_students(array $state): array {
    $students = [];
    foreach ((array)$state['students'] as $student) {
        if (trim((string)($student['first_name'] ?? '')) === ''
            && trim((string)($student['last_name'] ?? '')) === '') {
            continue;
        }
        $students[] = $student;
    }
    return $students;
}

// ===== Money display =====

function registration_dollars(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

// Marketing copy money: whole dollars lose the ".00" ("$420", "$28.33").
function registration_money(int $cents): string {
    return '$' . ($cents % 100 === 0
        ? number_format(intdiv($cents, 100))
        : number_format($cents / 100, 2));
}

// "$420 for 15 weeks ($28 per lesson)" — the per-lesson figure is derived so
// it can never drift from the prices in Settings.
function registration_price_breakdown(int $cents, string $unit = 'lesson'): string {
    $weeks = Settings::semesterWeeks();
    $perLesson = (int)round($cents / $weeks);
    return registration_money($cents) . ' for ' . $weeks . ' weeks ('
        . registration_money($perLesson) . ' per ' . $unit . ')';
}

// The tuition table shown in the step-1 intro — rendered from Settings so
// the copy can never drift from the actual quote math.
function registration_tuition_intro_html(array $semester): string {
    $plus = '<p class="tuition-plus">+</p>';
    return '<div class="card"><p><strong>' . h(registration_semester_label($semester)) . ' Tuition:</strong></p>'
        . '<ul class="stack" style="gap:2px;">'
        . '<li>30-minute private lessons (full semester): <strong>'
        . registration_price_breakdown(Settings::tuition30Cents()) . '</strong></li>'
        . '<li>60-minute private lessons (full semester): <strong>'
        . registration_price_breakdown(Settings::tuition60Cents()) . '</strong></li>'
        . '<li>30-minute Guitar Ensemble (full semester): <strong>'
        . registration_price_breakdown(Settings::tuitionEnsembleCents(), 'session') . '</strong></li>'
        . '</ul>'
        . $plus
        . '<p>Registration fee: <strong>' . registration_money(Settings::registrationCostCents())
        . '</strong> (one per family per semester).</p>'
        . $plus
        . '<p>Recital &amp; Logistics fee: <strong>' . registration_money(Settings::recitalFeeCents())
        . '</strong> per lesson block.</p>'
        . '<p class="small">Registrations done after the start of the semester (space permitting) will be prorated to the remaining weeks of term.</p>'
        . '</div>';
}

// reCAPTCHA lives in www/recaptcha.php — shared with the /inquiry/ flow.
