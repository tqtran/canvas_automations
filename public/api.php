<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CanvasAutomations\CanvasClient;
use CanvasAutomations\DeployQueue;

// No login: the Canvas token is the credential, and Canvas decides what it may do.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST.']);
    exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    $reply = json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Your page expired. Reload it and try again.']);
    $audit('request_failed', ['session' => $sessionTag, 'action' => (string)($_POST['action'] ?? ''), 'status' => 403, 'reply' => $reply]);
    error_log('canvas_automations: request failed 403 ' . $reply);
    http_response_code(403);
    echo $reply;
    exit;
}

@set_time_limit(90);

$action = (string)($_POST['action'] ?? '');
// Write every rejected request and its reason to var/audit.log and the PHP error log,
// because a proxy or IIS may replace the reply before the page can show it.
$logFailure = static function (int $status, array $body) use ($audit, $action, $sessionTag): void {
    // The raw request (secrets blanked) and the exact reply body, unfiltered.
    $request = $_POST;
    foreach (['canvas_token', 'lti_consumer_secret', 'csrf_token', 'run_key'] as $secret) {
        if (isset($request[$secret])) {
            $request[$secret] = '[hidden, ' . strlen((string)$request[$secret]) . ' chars]';
        }
    }
    $entry = [
        'session' => $sessionTag,
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
// "college.instructure.com" works as well as "https://college.instructure.com".
$_POST['base_url'] = CanvasClient::withScheme((string)$_POST['base_url']);
if (isset($_POST['lti_launch_url'])) {
    $_POST['lti_launch_url'] = CanvasClient::withScheme((string)$_POST['lti_launch_url']);
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

// Runs live in the shared queue (src/DeployQueue.php); this browser session may see the
// runs it started, and those of a Canvas user it has connected as.
$store = [];
$service = $makeEngine($store, $lti);
$sessionJobs = array_values(array_filter((array)($_SESSION['canvas_jobs'] ?? []), [DeployQueue::class, 'validId']));
$allowJobs = static function (array $ids) use (&$sessionJobs): void {
    $sessionJobs = array_slice(array_values(array_unique(array_merge($ids, $sessionJobs))), 0, 50);
    $_SESSION['canvas_jobs'] = $sessionJobs;
};
$jobId = (string)($_POST['job_id'] ?? '');
$needJob = static function () use ($jobId, &$sessionJobs): ?array {
    if (!DeployQueue::validId($jobId) || !in_array($jobId, $sessionJobs, true)) {
        return ['status' => 403, 'body' => ['ok' => false, 'error' => 'job_forbidden', 'message' => 'This run was not started from this browser. Connect with the Canvas token that started it to see it.']];
    }
    return null;
};
$jobReply = static function (?array $view): array {
    return $view === null
        ? ['status' => 404, 'body' => ['ok' => false, 'error' => 'job_missing', 'message' => 'This run is no longer on the server (finished runs are kept for 7 days).']]
        : ['status' => 200, 'body' => ['ok' => true] + $view];
};

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

switch ($action) {
    case 'run_start':
        // The engine checks the token and settings and sets the run up; the queue then owns it.
        $queue->maintain();
        $response = $service->dispatch('run_start', $_POST);
        if ($response['status'] === 200) {
            try {
                $job = $queue->create($store, $response['body']);
                $allowJobs([$job['id']]);
                $accountTool = (bool)($response['body']['account_tool'] ?? false);
                // The run key stays on the server now; the page only gets the run id.
                $response = $jobReply($queue->view($job['id']));
                $response['body']['account_tool'] = $accountTool;
            } catch (\Throwable $exception) {
                error_log('canvas_automations: queue failed ' . $exception->getMessage());
                $response = ['status' => 500, 'body' => ['ok' => false, 'error' => 'queue_unavailable', 'message' => 'The run could not be queued: ' . $exception->getMessage()]];
            }
        }
        break;
    case 'job_status':
        if (($response = $needJob()) !== null) {
            break;
        }
        $helped = false;
        // No worker has checked in lately: this page moves the queue along by one short step
        // of the first run in line (which may be someone else's), keeping one run at a time.
        // 5 s target, 14 s hard limit: well under a gateway's 20-second limit.
        if (($_POST['help'] ?? '') === '1' && !$queue->workerFresh()) {
            session_write_close();
            $helped = $queue->stepNext(5.0, 14.0, 'page') !== null;
        }
        $response = $jobReply($queue->view($jobId, (int)($_POST['from'] ?? 0)));
        $response['body']['helped'] = $helped;
        break;
    case 'job_cancel':
        if (($response = $needJob()) !== null) {
            break;
        }
        $queue->cancel($jobId);
        $response = $jobReply($queue->view($jobId));
        break;
    case 'my_jobs':
        // Runs this Canvas user started from any browser.
        $response = $service->dispatch('test_connection', $_POST);
        if ($response['status'] === 200) {
            $mine = $queue->owned((string)$response['body']['origin'], (int)$response['body']['user_id']);
            $allowJobs(array_column($mine, 'id'));
            $response = ['status' => 200, 'body' => ['ok' => true, 'jobs' => $mine, 'worker_seen' => $queue->workerSeen()]];
        }
        break;
    case 'session_jobs':
        $response = ['status' => 200, 'body' => ['ok' => true, 'jobs' => $queue->summaries($sessionJobs), 'worker_seen' => $queue->workerSeen()]];
        break;
    case 'test_connection':
    case 'terms':
    case 'courses':
    case 'course_flags':
        if ($action === 'course_flags') {
            // Talks to Canvas for several seconds and saves nothing; don't hold the session.
            session_write_close();
        }
        $response = $service->dispatch($action, $_POST);
        break;
    default:
        $response = ['status' => 404, 'body' => ['ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.']];
}
if (($response['body']['canvas_status'] ?? 0) === 401) {
    $failures[] = time();
    if (is_dir(dirname($throttleFile)) || @mkdir(dirname($throttleFile), 0700, true)) {
        @file_put_contents($throttleFile, json_encode($failures), LOCK_EX);
    }
}
if ($response['status'] >= 400) {
    $logFailure($response['status'], $response['body']);
}
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
