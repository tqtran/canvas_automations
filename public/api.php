<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CanvasAutomations\CanvasDeployRun;

// No login: the Canvas token is the credential, and Canvas decides what it may do.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST.']);
    exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Your page expired. Reload it and try again.']);
    exit;
}

@set_time_limit(90);

$action = (string)($_POST['action'] ?? '');
// Anyone can reach this endpoint, so it only talks to the configured Canvas URL;
// otherwise it would be an open relay to any host.
$configuredCanvas = $appConfig['canvas.base_url'];
if ($configuredCanvas === '') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'canvas_not_configured', 'message' => 'The Canvas URL is not set in this server\'s config.']);
    exit;
}
$_POST['base_url'] = $configuredCanvas;

// The LTI settings in the server config are optional. They fill in whatever the user leaves blank.
$lti = [
    'launch_url' => $appConfig['lti.launch_url'],
    'consumer_key' => $appConfig['lti.consumer_key'],
    'consumer_secret' => $appConfig['lti.consumer_secret'],
];

$store = is_array($_SESSION['canvas_deploy'] ?? null) ? $_SESSION['canvas_deploy'] : [];
$batchRunId = $store['run']['id'] ?? null;
if ($action === 'run_batch') {
    // A batch talks to Canvas for about 20 seconds. Release the session lock so
    // other tabs are not blocked meanwhile.
    session_write_close();
}
$service = new CanvasDeployRun(
    $store,
    $lti,
    $appConfig['data_dir'],
    0,
    null,
    static function (string $event, array $data) use ($audit): void {
        $audit($event, $data);
    },
    static function (string $message, array $context): void {
        error_log('canvas_automations: ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
);

// Throttle repeated rejected tokens per address so this open endpoint cannot be used to
// guess Canvas tokens.
$throttleFile = $appConfig['data_dir'] . '/failed_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
$failures = [];
if (is_file($throttleFile)) {
    $failures = array_values(array_filter(
        (array)json_decode((string)@file_get_contents($throttleFile), true),
        static fn($at): bool => is_int($at) && $at > time() - 900
    ));
    if (count($failures) >= 10) {
        http_response_code(429);
        header('Retry-After: 900');
        echo json_encode(['ok' => false, 'error' => 'throttled', 'message' => 'Too many rejected tokens. Wait 15 minutes, then try again.']);
        exit;
    }
}

$response = $action === 'save_defaults'
    ? ['status' => 404, 'body' => ['ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.']]
    : $service->dispatch($action, $_POST);
if (($response['body']['canvas_status'] ?? 0) === 401) {
    $failures[] = time();
    if (is_dir(dirname($throttleFile)) || @mkdir(dirname($throttleFile), 0700, true)) {
        @file_put_contents($throttleFile, json_encode($failures), LOCK_EX);
    }
}
if ($action === 'run_batch') {
    session_start();
    // Write back only if no other tab replaced or ended this run meanwhile.
    if (($_SESSION['canvas_deploy']['run']['id'] ?? null) === $batchRunId) {
        $_SESSION['canvas_deploy'] = $store;
    }
} else {
    $_SESSION['canvas_deploy'] = $store;
}
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
