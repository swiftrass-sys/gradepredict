<?php
/**
 * cron/cleanup.php — Scheduled maintenance. Deletes expired/used tokens so
 * they don't pile up forever, and prunes old error-log lines.
 *
 * Not triggered by any web request — this is meant to be run on a schedule
 * by the OS, e.g. once a day:
 *   0 3 * * *  php /path/to/gradepredict/cron/cleanup.php >> /path/to/gradepredict/data/cron.log 2>&1
 * (XAMPP on Windows: use Task Scheduler instead of cron; same command.)
 *
 * Safe to run from the CLI only — refuses to run over HTTP so a stray
 * public request can't trigger it or read its output.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line (cron/Task Scheduler), not the browser.');
}

require_once __DIR__ . '/../config.php';

$pdo = db();
$deleted = [];

$deleted['expired_remember_tokens'] = $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
$deleted['expired_password_resets'] = $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW() OR used = 1");

// Keep the error log from growing forever on long-running installs.
$logFile = DATA_DIR . '/php-error.log';
if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) { // 5MB
    $lines = file($logFile);
    file_put_contents($logFile, implode('', array_slice($lines, -2000))); // keep the last 2000 lines
    $deleted['log_trimmed'] = true;
}

echo '[' . date('c') . "] Cleanup done: " . json_encode($deleted) . "\n";
