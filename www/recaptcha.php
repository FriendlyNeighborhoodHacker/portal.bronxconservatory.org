<?php
// Google reCAPTCHA Enterprise (score-based, invisible) helpers, shared by the
// public logged-out flows (registration and the information request).
//
// The site key is a reCAPTCHA Enterprise score-based key: there is no
// checkbox. The widget emits a hidden token field and a script that fetches a
// token when the form is submitted; the server verifies it via the siteverify
// endpoint using the key's LEGACY SECRET KEY (Google Cloud console >
// reCAPTCHA > key details > Integration > "Use legacy key"), which returns
// the score and action for Enterprise tokens.
//
// Keys live in config.local.php; when unset the widget is empty and
// verification is skipped, so local development needs no keys — and removing
// the keys from a server's config is the quick way to switch it off.
require_once __DIR__ . '/config.php';

function recaptcha_is_configured(): bool {
    return defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
        && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '';
}

// Tokens below this score are treated as bots. Override with
// define('RECAPTCHA_MIN_SCORE', 0.3) in config.local.php if real families
// are being refused.
function recaptcha_min_score(): float {
    return defined('RECAPTCHA_MIN_SCORE') ? (float)RECAPTCHA_MIN_SCORE : 0.5;
}

// Emits the invisible-token machinery for the enclosing <form>: on submit it
// fetches a token for $action and then submits for real. $action must match
// what the eval page passes to recaptcha_verify_or_null(), and may only
// contain letters, digits, slashes and underscores.
function recaptcha_widget_html(string $action): string {
    if (!recaptcha_is_configured()) {
        return '';
    }
    $siteKey = htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8');
    $actionJs = json_encode($action);
    $siteKeyJs = json_encode(RECAPTCHA_SITE_KEY);
    return <<<HTML
<script src="https://www.google.com/recaptcha/enterprise.js?render={$siteKey}" async defer></script>
<input type="hidden" name="g-recaptcha-response" value="">
<script>
(function () {
  var form = document.currentScript.closest('form');
  if (!form) return;
  var field = form.querySelector('input[name="g-recaptcha-response"]');
  var tokenReady = false, inFlight = false;
  form.addEventListener('submit', function (e) {
    if (tokenReady) return; // token fetched — let this submit through
    // If Google's script never loaded (blocked, offline), submit without a
    // token; the server decides what to do with that.
    if (!window.grecaptcha || !grecaptcha.enterprise) return;
    e.preventDefault();
    if (inFlight) return;
    inFlight = true;
    grecaptcha.enterprise.ready(function () {
      grecaptcha.enterprise.execute({$siteKeyJs}, {action: {$actionJs}})
        .then(function (token) { field.value = token; })
        .catch(function () {})
        .then(function () {
          tokenReady = true;
          // form.submit() skips submit handlers; native validation already
          // passed before the submit event fired.
          form.submit();
        });
    });
  });
})();
</script>
HTML;
}

// Verifies the submitted token with Google and checks the score and action.
// Returns an error message to show the user, or null when verification
// passed (or reCAPTCHA is unconfigured).
function recaptcha_verify_or_null(string $expectedAction): ?string {
    if (!recaptcha_is_configured()) {
        return null;
    }
    $token = trim((string)($_POST['g-recaptcha-response'] ?? ''));
    if ($token === '') {
        return 'We could not verify this submission — please try again. '
            . 'If this keeps happening, call us and we will take your information by phone.';
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
    if (empty($result['success'])) {
        return 'reCAPTCHA verification failed — please try again.';
    }
    // A token minted for one form must not pass on another.
    if (isset($result['action']) && $result['action'] !== $expectedAction) {
        return 'reCAPTCHA verification failed — please try again.';
    }
    if (isset($result['score']) && (float)$result['score'] < recaptcha_min_score()) {
        return 'We could not verify this submission — please try again. '
            . 'If this keeps happening, call us and we will take your information by phone.';
    }
    return null;
}
