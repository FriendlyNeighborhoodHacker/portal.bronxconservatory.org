<?php
// GitHub deploy webhook. POST only; the X-Hub-Signature-256 header is the
// authorization (no CSRF, no session, no database by design), so this file
// pulls in config.local.php directly rather than config.php.
//
// Configure the repository webhook to POST here with content type
// application/json and the secret set to GIT_WEBHOOK_SECRET.

require __DIR__ . '/config.local.php';

if (!defined('GIT_WEBHOOK_SECRET') || GIT_WEBHOOK_SECRET === '') {
    http_response_code(500);
    echo "Webhook not configured\n";
    exit;
}

$secret = GIT_WEBHOOK_SECRET;

// Per-machine paths; override in config.local.php.
$deployScript = defined('DEPLOY_SCRIPT') ? DEPLOY_SCRIPT
    : '/home/bronxconservatory/deploy/portal.bronxconservatory.org.sh';
$logFile = defined('DEPLOY_LOG') ? DEPLOY_LOG
    : '/home/bronxconservatory/deploy/logs/portal.bronxconservatory.org.webhook.log';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

function logMessage(string $logFile, string $message): void
{
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

if (!$payload || !$signature) {
    http_response_code(400);
    echo "Missing payload or signature\n";
    logMessage($logFile, "Missing payload or signature");
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo "Invalid signature\n";
    logMessage($logFile, "Invalid signature");
    exit;
}

if ($event !== 'push') {
    http_response_code(200);
    echo "Ignored non-push event\n";
    exit;
}

// Run deploy script and capture output
$output = [];
$returnVar = 0;
exec($deployScript . ' 2>&1', $output, $returnVar);

if ($returnVar !== 0) {
    http_response_code(500);
    echo "Deploy failed\n";
    echo implode("\n", $output);
    logMessage($logFile, "Deploy failed");
    logMessage($logFile, implode("\n", $output));
    exit;
}

http_response_code(200);
logMessage($logFile, 'Deploy Successful');
echo "Deploy successful\n";
echo implode("\n", $output);
