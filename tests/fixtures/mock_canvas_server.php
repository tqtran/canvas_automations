<?php
declare(strict_types=1);

/**
 * Local mock Canvas for browser testing of the Deploy to Canvas page.
 *
 *   php -S 127.0.0.1:8766 tests/fixtures/mock_canvas_server.php
 *
 * GET /__reset?account_tool=1&slow=1&throttle=2&fresh_courses=119 reseeds the scenario.
 * GET /__state returns what the mock now holds (tools and modules per course).
 * The only accepted token is FakeCanvas::TOKEN.
 */

require_once __DIR__ . '/FakeCanvas.php';

$stateFile = sys_get_temp_dir() . '/canvas-automations-mock-' . (int)($_SERVER['SERVER_PORT'] ?? 0) . '.json';
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

$save = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, (string)json_encode($state), LOCK_EX);
};
$respond = static function (int $status, array $headers, string $body): void {
    http_response_code($status);
    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $body;
};

if ($path === '/__reset') {
    $state = FakeCanvas::scenario([
        'account_tool' => !empty($query['account_tool']),
        'slow' => !empty($query['slow']),
        'non_admin' => !empty($query['non_admin']),
        'throttle' => (int)($query['throttle'] ?? 0),
        'fresh_courses' => isset($query['fresh_courses']) ? (int)$query['fresh_courses'] : 119,
    ]);
    $save($state);
    $respond(200, [], (string)json_encode(['ok' => true, 'courses' => count($state['courses'])]));
    return;
}

$raw = is_file($stateFile) ? (string)file_get_contents($stateFile) : '';
$state = $raw !== '' ? json_decode($raw, true) : FakeCanvas::scenario();

if ($path === '/__state') {
    $summary = [];
    foreach ($state['courses'] as $id => $course) {
        $summary[$id] = [
            'tools' => array_map(static fn(array $tool): string => $tool['name'] . ' -> ' . ($tool['url'] ?? $tool['domain']), $course['tools']),
            'modules' => array_map(static fn(array $module): array => [
                'name' => $module['name'],
                'published' => $module['published'],
                'items' => array_map(static fn(array $item): string => ($item['published'] ?? false ? 'published ' : 'unpublished ') . ($item['title'] ?? '') . ' -> ' . ($item['external_url'] ?? ''), $module['items']),
            ], $course['modules']),
        ];
    }
    $respond(200, [], (string)json_encode(['calls' => count($state['calls']), 'courses' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[] = $name . ': ' . $value;
}
$scheme = 'http';
$canvas = new FakeCanvas($state);
$response = $canvas->handle(
    (string)$_SERVER['REQUEST_METHOD'],
    $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
    $headers,
    (string)file_get_contents('php://input')
);
$save($canvas->state);
$respond($response['status'], $response['headers'], $response['body']);
