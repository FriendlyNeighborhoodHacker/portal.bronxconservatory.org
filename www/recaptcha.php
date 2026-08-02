<?php
// Google reCAPTCHA v2 (checkbox) helpers, shared by the public logged-out
// flows (registration and the information request).
//
// Keys live in config.local.php; when unset the widget is hidden and
// verification is skipped, so local development needs no keys.
require_once __DIR__ . '/config.php';

function recaptcha_is_configured(): bool {
    return defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
        && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '';
}

function recaptcha_widget_html(): string {
    if (!recaptcha_is_configured()) {
        return '';
    }
    return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
        . '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') . '"></div>';
}

// Verifies the submitted token with Google. Returns an error message to show
// the user, or null when verification passed (or reCAPTCHA is unconfigured).
function recaptcha_verify_or_null(): ?string {
    if (!recaptcha_is_configured()) {
        return null;
    }
    $token = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    if ($token === '') {
        return 'Please check the "I\'m not a robot" box.';
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if ($body === false) {
        // Google unreachable: fail open rather than lose a real family's
        // submission (the lead queue is human-reviewed anyway).
        return null;
    }
    $result = json_decode((string)$body, true);
    return !empty($result['success']) ? null : 'reCAPTCHA verification failed — please try again.';
}
