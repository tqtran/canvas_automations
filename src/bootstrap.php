<?php
declare(strict_types=1);

/**
 * Shared setup for public/index.php and public/api.php: settings, session, CSRF token,
 * and the audit log. Settings come from config.local.php (see config.example.php),
 * then from environment variables.
 */

require_once __DIR__ . '/CanvasDeployRun.php';

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
    'canvas.base_url' => trim((string)$setting('canvas_base_url', 'CANVAS_BASE_URL')),
    'lti.launch_url' => trim((string)$setting('lti_launch_url', 'LTI_LAUNCH_URL')),
    'lti.consumer_key' => trim((string)$setting('lti_consumer_key', 'LTI_CONSUMER_KEY')),
    'lti.consumer_secret' => (string)$setting('lti_consumer_secret', 'LTI_CONSUMER_SECRET'),
    'data_dir' => (string)$setting('data_dir', 'CANVAS_AUTOMATIONS_DATA', dirname(__DIR__) . '/var'),
];
// Defaults for the form (link name, module name, ...) may be set with their canvas.* keys.
foreach ($fileSettings as $key => $value) {
    if (str_starts_with((string)$key, 'canvas.')) {
        $appConfig[$key] = $value;
    }
}
$configGet = static fn(string $key, mixed $default = null): mixed => $appConfig[$key] ?? $default;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
session_name('CANVASAUTOSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

/** Append one JSON line to var/audit.log. Never contains the token or the shared secret. */
$audit = static function (string $event, array $data) use ($appConfig): void {
    $dir = $appConfig['data_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/audit.log', json_encode([
        'at' => gmdate('c'),
        'event' => $event,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ] + $data, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
