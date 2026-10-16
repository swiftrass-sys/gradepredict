<?php
/**
 * health.php — Liveness/readiness check for uptime monitors, load-balancer
 * health probes, or a simple `curl` in a cron job.
 *
 * - Liveness: "is PHP even running?" — you already know that if this page
 *   returns anything at all.
 * - Readiness: "can the app actually serve a request right now?" — checked
 *   below by making sure the database connects and responds.
 *
 * Intentionally outside require_once('config.php')'s normal page flow: no
 * session, no auth, no HTML — just a fast JSON response so monitoring tools
 * can poll it every few seconds without any real cost.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$status = 'ok';
$checks = [];
$httpCode = 200;

try {
    $start = microtime(true);
    db()->query('SELECT 1')->fetch();
    $checks['database'] = [
        'ok' => true,
        'latency_ms' => round((microtime(true) - $start) * 1000, 1),
    ];
} catch (Throwable $e) {
    $status = 'error';
    $httpCode = 503;
    $checks['database'] = ['ok' => false];
    error_log('Health check DB failure: ' . $e->getMessage());
}

$checks['writable_data_dir'] = ['ok' => is_writable(DATA_DIR)];
if (!$checks['writable_data_dir']['ok']) {
    $status = 'degraded';
}

http_response_code($httpCode);
echo json_encode([
    'status' => $status,
    'time' => date('c'),
    'checks' => $checks,
], JSON_PRETTY_PRINT);
