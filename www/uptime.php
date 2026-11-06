<?php
// Uptime / health check for external monitors (UptimeRobot, Pingdom, cron curl).
//
// GET /uptime.php
//   200  {"status":"ok", ...}      PHP is serving requests AND the database answers
//   503  {"status":"fail", ...}    the database is unreachable or not responding
//
// Point the monitor at this URL and alert on anything other than HTTP 200
// (or on the body not containing "ok"). No session, no login, no CSRF, and
// no writes, so it is safe to hit every minute. Like gitwebhook.php it loads
// config.local.php directly instead of config.php so a health check never
// creates session files.
//
// The response deliberately names only WHICH check failed, never the
// exception message, so a database outage cannot leak hostnames or
// credentials to the public internet. Details are in the PHP error log.

require __DIR__ . '/config.local.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$startedAt = microtime(true);
$checks = ['php' => 'ok'];
$healthy = true;

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    // A real table read, not just "SELECT 1": the connection can be up while
    // the schema is missing (fresh install, wrong database name).
    $pdo->query('SELECT 1 FROM users LIMIT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $healthy = false;
    $checks['database'] = 'fail';
    error_log('uptime.php database check failed: ' . $e->getMessage());
}

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'fail',
    'checks' => $checks,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'time' => gmdate('c'),
]), "\n";
