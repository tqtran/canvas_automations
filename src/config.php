<?php
declare(strict_types=1);

/**
 * Settings, the audit log and the run queue, shared by the web pages and bin/worker.php.
 * Settings come from config.local.php (see config.example.php), then from environment
 * variables. No session and no headers here, so the command-line worker can use it.
 */

require_once __DIR__ . '/CanvasDeployRun.php';
require_once __DIR__ . '/DeployQueue.php';

$settingsFile = dirname(__DIR__) . '/config.local.php';
$fileSettings = is_file($settingsFile) ? (array)require $settingsFile : [];
$setting = static function (string $key, string $env, mixed $default = '') use ($fileSettings): mixed {
    if (array_key_exists($key, $fileSettings)) {
        return $fileSettings[$key];
    }
    $value = getenv($env);
    return $value === false ? $default : $value;
};

$appConfig = [
    // https:// is added to either address if it was left out.
    'canvas.base_url' => CanvasAutomations\CanvasClient::withScheme((string)$setting('canvas_base_url', 'CANVAS_BASE_URL')),
    'lti.launch_url' => CanvasAutomations\CanvasClient::withScheme((string)$setting('lti_launch_url', 'LTI_LAUNCH_URL')),
    'lti.consumer_key' => trim((string)$setting('lti_consumer_key', 'LTI_CONSUMER_KEY')),
    'lti.consumer_secret' => (string)$setting('lti_consumer_secret', 'LTI_CONSUMER_SECRET'),
    // Optional list of Canvas hosts people may connect to, e.g. ['*.instructure.com'].
    'canvas.allowed_hosts' => (array)$setting('canvas_allowed_hosts', 'CANVAS_ALLOWED_HOSTS', []),
    'data_dir' => (string)$setting('data_dir', 'CANVAS_AUTOMATIONS_DATA', dirname(__DIR__) . '/var'),
    // Encrypts the run keys kept in the queue. Generated into data_dir/queue.key if not set.
    'queue_key' => trim((string)$setting('queue_key', 'CANVAS_QUEUE_KEY')),
    // Secret for public/cron.php. Empty turns that address off (bin/worker.php still works).
    'worker_key' => trim((string)$setting('worker_key', 'CANVAS_WORKER_KEY')),
];
// Defaults for the form (link name, module name, ...) may be set with their canvas.* keys.
foreach ($fileSettings as $key => $value) {
    if (str_starts_with((string)$key, 'canvas.')) {
        $appConfig[$key] = $value;
    }
}
if (is_string($appConfig['canvas.allowed_hosts'][0] ?? null) && count($appConfig['canvas.allowed_hosts']) === 1) {
    $appConfig['canvas.allowed_hosts'] = array_values(array_filter(array_map('trim', explode(',', $appConfig['canvas.allowed_hosts'][0]))));
}
$configGet = static fn(string $key, mixed $default = null): mixed => $appConfig[$key] ?? $default;

/** Append one JSON line to var/audit.log. Never contains the token or the shared secret. */
$audit = static function (string $event, array $data) use ($appConfig): void {
    $dir = $appConfig['data_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $file = $dir . '/audit.log';
    // Keep one older file: past 20 MB, audit.log becomes audit.log.1.
    if (@filesize($file) > 20 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, json_encode([
        'at' => gmdate('c'),
        'event' => $event,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')),
    ] + $data, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};

/** A run engine over $store, set up for the shared queue. */
$makeEngine = static function (array &$store, array $lti = []) use ($appConfig, $audit): CanvasAutomations\CanvasDeployRun {
    return (new CanvasAutomations\CanvasDeployRun(
        $store,
        $lti + ['launch_url' => '', 'consumer_key' => '', 'consumer_secret' => ''],
        $appConfig['data_dir'],
        0,
        null,
        static function (string $event, array $data) use ($audit): void {
            $audit($event, $data);
        },
        static function (string $message, array $context) use ($audit): void {
            // Each course's result goes to audit.log with the run it belongs to.
            $audit(...($message === 'Canvas deploy course result' ? ['course_result', $context] : ['engine', $context + ['message' => $message]]));
        }
    ))->forQueue();
};

$queue = new CanvasAutomations\DeployQueue(
    rtrim($appConfig['data_dir'], '/\\') . '/queue',
    CanvasAutomations\DeployQueue::key($appConfig['queue_key'], $appConfig['data_dir']),
    static fn(array &$store): CanvasAutomations\CanvasDeployRun => $makeEngine($store),
    null,
    null,
    $audit
);
