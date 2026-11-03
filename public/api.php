<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CanvasAutomations\CanvasClient;
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
    $audit('request_failed', ['action' => (string)($_POST['action'] ?? ''), 'status' => 403, 'reply' => '{"ok":false,"error":"csrf"}']);
    error_log('canvas_automations: request failed 403 csrf');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Your page expired. Reload it and try again.']);
    exit;
}

@set_time_limit(90);

$action = (string)($_POST['action'] ?? '');
// Write every rejected request and its reason to var/audit.log and the PHP error log,
// because a proxy or IIS may replace the reply before the page can show it.
$logFailure = static function (int $status, array $body) use ($audit, $action): void {
    // The raw request (secrets blanked) and the exact reply body, unfiltered.
    $request = $_POST;
    foreach (['canvas_token', 'lti_consumer_secret', 'csrf_token', 'run_key'] as $secret) {
        if (isset($request[$secret])) {
            $request[$secret] = '[hidden, ' . strlen((string)$request[$secret]) . ' chars]';
        }
    }
    $entry = [
        'action' => $action,
        'status' => $status,
        'request' => $request,
        'reply' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $audit('request_failed', $entry);
    error_log('canvas_automations: request failed ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
};
// The person picks their Canvas. Anyone can reach this endpoint, so a URL other than the
// configured one must be a public https host (and on the allowed list, when there is one);
// otherwise this would be a relay into the server's own network.
$configuredCanvas = $appConfig['canvas.base_url'];
if (trim((string)($_POST['base_url'] ?? '')) === '') {
    $_POST['base_url'] = $configuredCanvas;
}
$normalize = static function (string $url): string {
    try {
        return CanvasClient::normalizeBaseUrl($url);
    } catch (\Throwable) {
        return '';
    }
};
$postedCanvas = $normalize((string)$_POST['base_url']);
$sameCanvas = $postedCanvas !== '' && $configuredCanvas !== '' && $postedCanvas === $normalize($configuredCanvas);
$refuse = static function (string $message) use ($logFailure): never {
    $body = ['ok' => false, 'error' => 'canvas_url', 'message' => $message, 'fields' => ['base_url' => $message]];
    $logFailure(422, $body);
    http_response_code(422);
    echo json_encode($body);
    exit;
};
if ($postedCanvas !== '' && !$sameCanvas) {
    $host = (string)parse_url($postedCanvas, PHP_URL_HOST);
    $allowed = $appConfig['canvas.allowed_hosts'];
    if ($allowed !== [] && !array_filter($allowed, static fn($pattern): bool => fnmatch(strtolower((string)$pattern), $host))) {
        $refuse('This server only connects to these Canvas sites: ' . implode(', ', $allowed) . '.');
    }
    $addresses = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : array_merge(
        array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
    );
    if ($addresses === []) {
        $refuse('Could not find ' . $host . '. Check the Canvas URL.');
    }
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $refuse($host . ' is a private or internal address. Enter your public Canvas URL.');
        }
    }
}

// The LTI settings in the server config are optional and fill in whatever the user leaves
// blank, but only for the configured Canvas: the shared secret never goes to another host.
$lti = $sameCanvas ? [
    'launch_url' => $appConfig['lti.launch_url'],
    'consumer_key' => $appConfig['lti.consumer_key'],
    'consumer_secret' => $appConfig['lti.consumer_secret'],
] : ['launch_url' => '', 'consumer_key' => '', 'consumer_secret' => ''];

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
        $logFailure(429, ['error' => 'throttled', 'message' => 'Too many rejected tokens from this address.']);
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
if ($response['status'] >= 400) {
    $logFailure($response['status'], $response['body']);
}
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
