<?php
// Where PHP stores session files. Set SESSION_SAVE_PATH in config.local.php to
// keep them in a directory this account owns (e.g. /home/<user>/var/sessions)
// rather than a shared system default. Sessions written to a directory the PHP
// user cannot read back fail *silently*: $_SESSION comes up empty on every
// request, which shows up as "CSRF token mismatch" on every POST and a logout
// that never sticks. So this fails loudly instead.
function app_prepare_session_save_path(): void {
    if (!defined('SESSION_SAVE_PATH') || SESSION_SAVE_PATH === '') {
        return;
    }
    $path = SESSION_SAVE_PATH;

    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (!is_dir($path) || !is_writable($path)) {
        http_response_code(500);
        die('Session directory is not writable: ' . htmlspecialchars($path, ENT_QUOTES));
    }

    session_save_path($path);
    // Custom save paths are not covered by the distro cron that cleans the
    // system session directory, so let PHP's own probabilistic GC do it.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
}
