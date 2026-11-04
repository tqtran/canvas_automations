<?php
declare(strict_types=1);

/**
 * Setup for the web pages: everything in config.php, plus the session, CSRF token and
 * security headers.
 */

require_once __DIR__ . '/config.php';

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
// Marks log lines with the browser session that caused them, so log.php can show a person
// only their own entries. A hash, so the log never holds a usable session ID.
$sessionTag = hash('sha256', session_id());

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
