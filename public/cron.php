<?php
declare(strict_types=1);

/**
 * The queue worker over HTTP, for a scheduled task that calls a URL instead of php.exe:
 *
 *   curl -fsS -H "X-Worker-Key: <worker_key>" https://localhost/cron.php
 *
 * (or ?key=<worker_key>, which ends up in the web server's access log). Set worker_key in
 * config.local.php; without it this address is off. Call it on the server itself
 * (localhost) so the request does not pass through the gateway. Each call works for about
 * 35 seconds, well inside IIS's FastCGI limits.
 */

require_once __DIR__ . '/../src/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$given = (string)($_SERVER['HTTP_X_WORKER_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '');
if ($appConfig['worker_key'] === '' || strlen($appConfig['worker_key']) < 16) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'The worker address is off. Set worker_key (16 characters or more) in config.local.php.']);
    exit;
}
if (!hash_equals($appConfig['worker_key'], $given)) {
    $audit('worker_key_rejected', []);
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Wrong worker key.']);
    exit;
}

ignore_user_abort(true);
@set_time_limit(120);
try {
    $summary = $queue->work(35.0, 10.0, 20.0);
} catch (\Throwable $exception) {
    error_log('canvas_automations: worker failed ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'The worker failed: ' . $exception->getMessage()]);
    exit;
}
echo json_encode(['ok' => true] + $summary);
