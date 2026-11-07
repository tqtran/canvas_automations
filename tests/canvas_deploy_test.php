<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CanvasDeployRun.php';
require_once __DIR__ . '/../src/DeployQueue.php';
require_once __DIR__ . '/fixtures/FakeCanvas.php';

use CanvasAutomations\CanvasApiException;
use CanvasAutomations\CanvasClient;
use CanvasAutomations\CanvasDeadlineExceeded;
use CanvasAutomations\CanvasDeployRun;
use CanvasAutomations\CanvasLtiDeployer;
use CanvasAutomations\CanvasTransport;
use CanvasAutomations\DeployQueue;
use CanvasAutomations\Encryption;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback, string $class, ?string $kind = null): bool {
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception instanceof $class && ($kind === null || ($exception instanceof CanvasApiException && $exception->kind() === $kind));
    }
    return false;
};

final class FakeClock
{
    public float $now;
    public array $sleeps = [];

    public function __construct()
    {
        // FakeCanvas dates its terms from the real clock, so start there.
        $this->now = (float)time();
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }
}

/** Replays canned responses and records every request. */
final class ScriptedTransport implements CanvasTransport
{
    public array $requests = [];

    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next + ['headers' => [], 'body' => '[]'];
    }
}

/** Sends requests to FakeCanvas, charging simulated time per call. */
final class FakeCanvasTransport implements CanvasTransport
{
    public function __construct(public FakeCanvas $canvas, private FakeClock $clock, private float $callCost = 0.1)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $start = $this->clock->now;
        $response = $this->canvas->handle($method, $url, $headers, $body);
        $this->clock->now += $this->callCost;
        if ($this->clock->now - $start > $timeoutSeconds) {
            $this->clock->now = $start + $timeoutSeconds;
            throw new CanvasApiException('Canvas did not answer in time.', 0, 'timeout');
        }
        return $response;
    }
}

// --- Offering parsing shared with launch.php -------------------------------------------

// --- Client: address rules -------------------------------------------------------------
$check(CanvasClient::normalizeBaseUrl(' https://College.Instructure.com/ ') === 'https://college.instructure.com', 'Canvas origin was not normalized.');
$check(CanvasClient::normalizeBaseUrl('https://canvas.test:443') === 'https://canvas.test', 'Default https port was kept.');
$check(CanvasClient::normalizeBaseUrl('https://canvas.test:8443') === 'https://canvas.test:8443', 'Custom port was dropped.');
$check(CanvasClient::normalizeBaseUrl('http://127.0.0.1:8766') === 'http://127.0.0.1:8766', 'Loopback http was rejected outside production.');
foreach (['http://canvas.example.edu', 'https://canvas.test/courses', 'https://canvas.test/?x=1', 'https://user:pw@canvas.test', 'ftp://canvas.test', ''] as $bad) {
    $check($throws(static fn() => CanvasClient::normalizeBaseUrl($bad), CanvasApiException::class, 'config'), "Unsafe Canvas URL accepted: {$bad}");
}
putenv('CANVAS_AUTOMATIONS_ENV=production');
$check($throws(static fn() => CanvasClient::normalizeBaseUrl('http://localhost:8766'), CanvasApiException::class, 'config'), 'Loopback http was accepted in production.');
putenv('CANVAS_AUTOMATIONS_ENV');

$check(
    CanvasClient::buildQuery(['include' => ['term', 'items'], 'course_navigation' => ['enabled' => 'true', 'text' => 'A B'], 'flag' => false, 'skip' => null])
        === 'include%5B%5D=term&include%5B%5D=items&course_navigation%5Benabled%5D=true&course_navigation%5Btext%5D=A%20B&flag=false',
    'Canvas parameters are not encoded as key[] and key[sub].'
);
$check(CanvasClient::nextLink(['<https://c.test/a?page=2>; rel="next", <https://c.test/a?page=1>; rel="first"']) === 'https://c.test/a?page=2', 'Next link not parsed.');
$check(CanvasClient::nextLink(['<https://c.test/a?page=1>; rel="current"']) === null, 'Missing next link was invented.');

// --- Client: safety and pacing ----------------------------------------------------------
$clock = new FakeClock();
$makeClient = static fn(array $responses, ?float $deadline = null) => [
    $transport = new ScriptedTransport($responses),
    new CanvasClient('https://canvas.test', FakeCanvas::TOKEN, $transport, $deadline, $clock, [$clock, 'sleep']),
];

[$transport, $client] = $makeClient([
    ['status' => 200, 'headers' => ['link' => ['<https://evil.example/api/v1/accounts?page=2>; rel="next"']], 'body' => '[{"id":1}]'],
]);
$check($throws(static fn() => $client->getAll('/api/v1/accounts'), CanvasApiException::class, 'origin'), 'Cross-origin pagination link was followed.');
$check(count($transport->requests) === 1, 'The token was sent to a cross-origin pagination link.');

[, $client] = $makeClient([['status' => 302, 'headers' => ['location' => ['https://evil.example/']]]]);
$check($throws(static fn() => $client->get('/api/v1/users/self'), CanvasApiException::class, 'redirect'), 'Redirects were not refused.');

$clock->sleeps = [];
[$transport, $client] = $makeClient([
    ['status' => 429, 'headers' => ['retry-after' => ['1']], 'body' => ''],
    ['status' => 403, 'body' => '403 Forbidden (Rate Limit Exceeded)'],
    ['status' => 200, 'body' => '{"id":7}'],
]);
$check(($client->get('/api/v1/users/self')['id'] ?? null) === 7, 'Throttled request did not succeed after backoff.');
$check($clock->sleeps === [1.0, 2.0], 'Rate-limit backoff did not honour Retry-After then back off exponentially.');

[, $client] = $makeClient(array_fill(0, 4, ['status' => 429, 'body' => '']));
$check($throws(static fn() => $client->get('/api/v1/users/self'), CanvasApiException::class, 'rate_limited'), 'Persistent throttling was retried forever.');

$clock->sleeps = [];
[, $client] = $makeClient([['status' => 200, 'headers' => ['x-rate-limit-remaining' => ['40.5']], 'body' => '{}']]);
$client->get('/api/v1/users/self');
$check($clock->sleeps === [1.0], 'Low remaining quota did not pause.');

[, $client] = $makeClient([['status' => 429, 'headers' => ['retry-after' => ['10']], 'body' => '']], $clock->now + 5);
$check($throws(static fn() => $client->get('/api/v1/users/self'), CanvasDeadlineExceeded::class), 'A rate-limit wait was allowed past the deadline.');

[$transport, $client] = $makeClient([['status' => 200, 'body' => '{}']], $clock->now + 5);
$client->get('/api/v1/users/self');
$check($transport->requests[0]['timeout'] <= 5.0 && $transport->requests[0]['timeout'] > 4.0, 'HTTP timeout was not capped by the time left.');
[$transport, $client] = $makeClient([['status' => 200, 'body' => '{}']]);
$client->get('/api/v1/users/self');
$check($transport->requests[0]['timeout'] === 15.0, 'Per-call timeout is not 15 seconds without a deadline.');

[$transport, $client] = $makeClient([], $clock->now + 0.5);
$check($throws(static fn() => $client->get('/api/v1/users/self'), CanvasDeadlineExceeded::class) && $transport->requests === [], 'A call started with under a second left.');

[, $client] = $makeClient([new CanvasApiException('Canvas did not answer in time.', 0, 'timeout')]);
$check($throws(static fn() => $client->get('/api/v1/users/self'), CanvasDeadlineExceeded::class), 'A transport timeout was not turned into a retryable deadline.');

[, $client] = $makeClient([['status' => 400, 'body' => '{"errors":[{"message":"bad token ' . FakeCanvas::TOKEN . '"}]}']]);
try {
    $client->get('/api/v1/users/self');
    $check(false, 'HTTP 400 did not throw.');
} catch (CanvasApiException $exception) {
    $check(!str_contains($exception->getMessage(), FakeCanvas::TOKEN) && str_contains($exception->getMessage(), '[token]'), 'Token leaked into an error message.');
}
ob_start();
var_dump($client);
$dump = (string)ob_get_clean();
$check(!str_contains(print_r($client, true), FakeCanvas::TOKEN) && !str_contains($dump, FakeCanvas::TOKEN), 'Token visible in debug output.');

// --- Deployer rules ---------------------------------------------------------------------
$check(
    CanvasLtiDeployer::normalizeUrl('HTTPS://SLO.example.edu:443/lti/launch.php/') === CanvasLtiDeployer::normalizeUrl('https://slo.example.edu/lti/launch.php'),
    'URL normalization ignores case, default port, and trailing slash incorrectly.'
);
$check(CanvasLtiDeployer::normalizeUrl('https://slo.example.edu/lti/launch.php?a=1') !== CanvasLtiDeployer::normalizeUrl('https://slo.example.edu/lti/launch.php'), 'Query was dropped from URL comparison.');
$check(CanvasLtiDeployer::normalizeUrl('https://slo.example.edu:8443/x') === 'https://slo.example.edu:8443/x', 'Custom port dropped from URL comparison.');
$launch = FakeCanvas::LAUNCH_URL;
$check(CanvasLtiDeployer::findMatchingTool([FakeCanvas::tool(1, 'Other', null, 'k', 'public', 'SLO.example.edu')], $launch) !== null, 'Domain-only tool was not matched.');
$check(CanvasLtiDeployer::findMatchingTool([FakeCanvas::tool(1, 'Other', 'https://slo.example.edu/other', 'k', 'public', 'slo.example.edu')], $launch) === null, 'A different URL on the same domain was matched.');
$foreign = CanvasLtiDeployer::findForeignTools([
    FakeCanvas::tool(1, 'SLO Cloud Reporting', FakeCanvas::PROD_LAUNCH_URL, 'k'),
    FakeCanvas::tool(2, 'Zoom', 'https://zoom.example/lti', 'zoom'),
    FakeCanvas::tool(3, 'Legacy', 'https://old.example/lti', FakeCanvas::CONSUMER_KEY),
    FakeCanvas::tool(4, 'SLO Cloud Reporting', $launch, FakeCanvas::CONSUMER_KEY),
], $launch, 'SLO Cloud Reporting', FakeCanvas::CONSUMER_KEY);
$check(array_column($foreign, 'id') === [1, 3], 'Foreign SLO Cloud tools were not identified by name or key.');
$warnings = CanvasLtiDeployer::toolWarnings(FakeCanvas::tool(1, 'x', $launch, 'other-key', 'anonymous'), FakeCanvas::CONSUMER_KEY);
$check(count($warnings) === 2, 'Key and privacy mismatches were not both reported.');
$payload = CanvasLtiDeployer::toolPayload([
    'link_name' => 'SLO Cloud Reporting', 'consumer_key' => 'k', 'consumer_secret' => 's', 'launch_url' => $launch,
]);
$check($payload['privacy_level'] === 'public', 'Tool is not created with public privacy (names and email).');
$check($payload['course_navigation']['visibility'] === 'admins' && $payload['course_navigation']['enabled'] === 'true', 'Course navigation is not limited to instructors.');
$check($payload['course_navigation']['text'] === 'SLO Cloud Reporting' && $payload['shared_secret'] === 's' && $payload['url'] === $launch, 'Tool payload fields are wrong.');

$now = (int)$clock->now;
$past = gmdate('Y-m-d\TH:i:s\Z', $now - 86400);
$future = gmdate('Y-m-d\TH:i:s\Z', $now + 86400);
$check(CanvasLtiDeployer::isConcluded(['workflow_state' => 'completed'], $now), 'Completed course not concluded.');
$check(CanvasLtiDeployer::isConcluded(['term' => ['end_at' => $past]], $now), 'Ended term not concluded.');
$check(!CanvasLtiDeployer::isConcluded(['term' => ['end_at' => $future]], $now), 'Future term concluded.');
$check(!CanvasLtiDeployer::isConcluded(['term' => ['end_at' => null]], $now), 'Undated term concluded.');
$check(CanvasLtiDeployer::isConcluded(['restrict_enrollments_to_course_dates' => true, 'end_at' => $past, 'term' => ['end_at' => $future]], $now), 'Course date override ignored.');
$check(!CanvasLtiDeployer::isConcluded(['restrict_enrollments_to_course_dates' => false, 'end_at' => $past, 'term' => ['end_at' => $future]], $now), 'Course end date used without the override.');

// --- Deployer against the fake Canvas ---------------------------------------------------
$canvas = new FakeCanvas(FakeCanvas::scenario(['fresh_courses' => 0]), static function (float $seconds) use ($clock): void {
    $clock->now += $seconds;
});
$fakeClient = new CanvasClient('https://canvas.test', FakeCanvas::TOKEN, new FakeCanvasTransport($canvas, $clock), null, $clock, [$clock, 'sleep']);
$deploySettings = [
    'launch_url' => $launch, 'consumer_key' => FakeCanvas::CONSUMER_KEY, 'consumer_secret' => FakeCanvas::CONSUMER_SECRET,
    'link_name' => 'SLO Cloud Reporting', 'create_module' => true, 'module_name' => 'SLO Cloud', 'dry_run' => false, 'account_tool' => false,
];
$courseRow = static fn(int $id): array => CanvasLtiDeployer::compactCourse(['id' => $id, 'name' => 'Course ' . $id, 'sis_course_id' => (string)$id]);
$posts = static fn(): int => count(array_filter($canvas->state['calls'], static fn(string $call): bool => str_starts_with($call, 'POST')));
$deployer = new CanvasLtiDeployer($fakeClient, $deploySettings);

$dry = (new CanvasLtiDeployer($fakeClient, ['dry_run' => true] + $deploySettings))->deployCourse($courseRow(1001));
$check($dry['tool'] === 'would_create' && $dry['module'] === 'would_create' && $posts() === 0, 'Dry run wrote to Canvas or misreported.');

$first = $deployer->deployCourse($courseRow(1001));
$tool = $canvas->state['courses'][1001]['tools'][0] ?? [];
$module = $canvas->state['courses'][1001]['modules'][0] ?? [];
$check($first['tool'] === 'created' && $first['module'] === 'created' && $first['status'] === 'ok', 'Fresh course was not deployed.');
$check(($tool['privacy_level'] ?? '') === 'public' && ($tool['course_navigation']['visibility'] ?? '') === 'admins' && ($tool['shared_secret_set'] ?? false), 'Created tool has the wrong settings.');
$check(($module['published'] ?? true) === false && ($module['items'][0]['external_url'] ?? '') === $launch && ($module['items'][0]['type'] ?? '') === 'ExternalTool', 'Module link was not created unpublished.');
$postsBefore = $posts();
$again = $deployer->deployCourse($courseRow(1001));
$check($again['tool'] === 'present' && $again['module'] === 'present' && $posts() === $postsBefore, 'Re-running a course was not idempotent.');

$check($deployer->deployCourse($courseRow(1002))['tool'] === 'present', 'Trailing-slash URL was not recognised as installed.');
$differentKey = $deployer->deployCourse($courseRow(1003));
$check($differentKey['tool'] === 'present' && $differentKey['status'] === 'warning' && str_contains(implode(' ', $differentKey['notes']), 'different consumer key'), 'Key mismatch was not left alone with a warning.');
$refreshed = $deployer->deployCourse($courseRow(1004));
$check($refreshed['tool'] === 'created' && str_contains(implode(' ', $refreshed['notes']), 'two tabs'), 'Foreign production tool was not flagged.');
$forbidden = $deployer->deployCourse($courseRow(1006));
$check($forbidden['status'] === 'failed' && $forbidden['tool'] === 'failed' && str_contains(implode(' ', $forbidden['notes']), '403'), 'A 403 course was not recorded as failed.');
$published = $deployer->deployCourse($courseRow(1010));
$check($published['module'] === 'added' && count($canvas->state['courses'][1010]['modules']) === 1 && str_contains(implode(' ', $published['notes']), 'published'), 'Existing module was not reused with a warning.');
$check($deployer->deployCourse($courseRow(1011))['module'] === 'present', 'Existing link in another module was not detected.');

$calls = count($canvas->state['calls']);
$accountLevel = (new CanvasLtiDeployer($fakeClient, ['account_tool' => true, 'create_module' => false] + $deploySettings))->deployCourse($courseRow(1008));
$check($accountLevel['tool'] === 'present_account' && $accountLevel['module'] === 'off' && count($canvas->state['calls']) === $calls, 'Account-level install still triggered course lookups.');

$withSis = (new CanvasLtiDeployer($fakeClient, $deploySettings, static fn(?string $sis): array => $sis === null ? ['No SIS ID'] : []))->deployCourse(['sis_course_id' => null] + $courseRow(1009));
$check($withSis['status'] === 'warning' && $withSis['notes'][0] === 'No SIS ID', 'SIS inspector notes were not reported.');

// Nav link: shown or hidden by default, and "Update if found".
$hiddenPayload = CanvasLtiDeployer::toolPayload(['nav_default' => 'disabled'] + $deploySettings);
$check($hiddenPayload['course_navigation']['default'] === 'disabled', 'A hidden-by-default nav link was not created hidden.');
$check(CanvasLtiDeployer::toolPayload($deploySettings)['course_navigation']['default'] === 'enabled'
    && CanvasLtiDeployer::navDefault('bogus') === 'enabled', 'The nav default is not "enabled" when unset or unknown.');
$navTool = ['course_navigation' => CanvasLtiDeployer::navPlacement('SLO Cloud Reporting')];
$check(!CanvasLtiDeployer::navDiffers($navTool, 'SLO Cloud Reporting', 'enabled'), 'An identical nav link was seen as different.');
$check(CanvasLtiDeployer::navDiffers($navTool, 'SLO Cloud', 'enabled') && CanvasLtiDeployer::navDiffers($navTool, 'SLO Cloud Reporting', 'disabled'),
    'A changed link name or default was not seen as different.');
$check(!CanvasLtiDeployer::navDiffers(['course_navigation' => ['text' => 'X', 'visibility' => 'admins', 'enabled' => true]], 'X', 'enabled'),
    'A nav link without a default was not read as shown, as Canvas treats it.');

$navSettings = ['create_nav' => true, 'create_module' => false, 'link_name' => 'SLO Cloud', 'nav_default' => 'disabled'] + $deploySettings;
$puts = static fn(): int => count(array_filter($canvas->state['calls'], static fn(string $call): bool => str_starts_with($call, 'PUT')));
$putsBefore = $puts();
$leftAlone = (new CanvasLtiDeployer($fakeClient, $navSettings))->deployCourse($courseRow(1001));
$check($leftAlone['nav'] === 'present' && $puts() === $putsBefore, 'An existing nav link was changed without "Update if found".');
$navDry = (new CanvasLtiDeployer($fakeClient, ['update_nav' => true, 'dry_run' => true] + $navSettings))->deployCourse($courseRow(1001));
$check($navDry['nav'] === 'would_update' && $navDry['tool'] === 'present' && $puts() === $putsBefore, 'A nav update dry run wrote to Canvas or misreported.');
$navUpdated = (new CanvasLtiDeployer($fakeClient, ['update_nav' => true] + $navSettings))->deployCourse($courseRow(1001));
$updatedNav = $canvas->state['courses'][1001]['tools'][0]['course_navigation'] ?? [];
$check($navUpdated['nav'] === 'updated' && $navUpdated['status'] === 'ok' && ($updatedNav['text'] ?? '') === 'SLO Cloud'
    && ($updatedNav['default'] ?? '') === 'disabled' && ($updatedNav['visibility'] ?? '') === 'admins', 'An existing nav link was not updated.');
$check(($canvas->state['courses'][1001]['tools'][0]['consumer_key'] ?? '') === FakeCanvas::CONSUMER_KEY, 'Updating the nav link changed the tool credentials.');
$putsBefore = $puts();
$navSame = (new CanvasLtiDeployer($fakeClient, ['update_nav' => true] + $navSettings))->deployCourse($courseRow(1001));
$check($navSame['nav'] === 'present' && $puts() === $putsBefore, 'A nav link that already matches was written again.');
$navOff = (new CanvasLtiDeployer($fakeClient, ['update_nav' => true, 'create_nav' => false] + $navSettings))->deployCourse($courseRow(1001));
$check($navOff['nav'] === 'present' && $puts() === $putsBefore, '"Update if found" for nav applied with the nav link turned off.');

$canvas->state['account_tools'] = [['course_navigation' => CanvasLtiDeployer::navPlacement('SLO Cloud Reporting')] + FakeCanvas::tool(901, 'SLO Cloud Reporting', $launch, FakeCanvas::CONSUMER_KEY)];
$putsBefore = $puts();
$fromAccount = (new CanvasLtiDeployer($fakeClient, ['update_nav' => true] + $navSettings))->deployCourse($courseRow(1008));
$check($fromAccount['nav'] === 'present' && $puts() === $putsBefore && str_contains(implode(' ', $fromAccount['notes']), 'comes from the account'),
    'An account nav link was not reported as not updatable from a course.');
$canvas->state['account_tools'] = [];

// --- Run service ------------------------------------------------------------------------
$lockRoot = sys_get_temp_dir() . '/slocloud-canvas-deploy-test-' . bin2hex(random_bytes(4));
$lti = ['launch_url' => $launch, 'consumer_key' => FakeCanvas::CONSUMER_KEY, 'consumer_secret' => FakeCanvas::CONSUMER_SECRET];
$audits = [];
$logs = [];
$newService = static function (array &$store, FakeCanvas $canvas, FakeClock $clock, int $userId = 1, float $callCost = 0.1) use (&$audits, &$logs, $lti, $lockRoot): CanvasDeployRun {
    return new CanvasDeployRun(
        $store,
        $lti,
        $lockRoot,
        $userId,
        null,
        static function (string $event, array $data) use (&$audits): void { $audits[] = [$event, $data]; },
        static function (string $message, array $context) use (&$logs): void { $logs[] = [$message, $context]; },
        static fn(string $base, string $token, ?float $deadline): CanvasClient => new CanvasClient($base, $token, new FakeCanvasTransport($canvas, $clock, $callCost), $deadline, $clock, [$clock, 'sleep']),
        $clock,
        [$clock, 'sleep']
    );
};
$settings = static fn(array $overrides = []): array => $overrides + [
    'base_url' => 'https://canvas.test', 'link_name' => 'SLO Cloud Reporting', 'create_module' => '1', 'module_name' => 'SLO Cloud',
    'course_delay_ms' => '0', 'scope' => 'term', 'scope_value' => '10', 'dry_run' => '0', 'canvas_token' => FakeCanvas::TOKEN,
];
$runAll = static function (CanvasDeployRun $service, string $runId, string $runKey, array &$batches): array {
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $response = $service->dispatch('run_batch', ['run_id' => $runId, 'run_key' => $runKey]);
        if ($response['status'] !== 200) {
            return ['error' => $response['body']] + $rows;
        }
        $batches[] = $response['body'];
        array_push($rows, ...$response['body']['results']);
        if ($response['body']['done']) {
            return $rows;
        }
    }
    return $rows;
};

$validated = CanvasDeployRun::validateSettings($settings());
$check($validated['errors'] === [] && $validated['settings']['create_module'] === true && $validated['settings']['course_delay_ms'] === 0, 'Valid settings were rejected.');
$invalid = CanvasDeployRun::validateSettings($settings(['course_delay_ms' => '20000', 'scope' => 'course', 'scope_value' => '12, abc', 'link_name' => '', 'base_url' => 'http://canvas.example.edu']));
$check(array_keys($invalid['errors']) === ['base_url', 'link_name', 'course_delay_ms', 'scope_value'], 'Invalid settings were not all reported: ' . implode(',', array_keys($invalid['errors'])));
$check(CanvasDeployRun::validateSettings($settings(['scope' => 'course', 'scope_value' => '12, 12 ,34']))['settings']['scope_value'] === '12,34', 'Course IDs were not normalized.');
$check(isset(CanvasDeployRun::validateSettings($settings(['course_delay_ms' => '-1']))['errors']['course_delay_ms']), 'Negative delay accepted.');
$check(isset(CanvasDeployRun::validateSettings($settings(['scope' => 'term', 'scope_value' => '']))['errors']['scope_value']), 'Missing term accepted.');
$check(CanvasDeployRun::configValues($validated['settings']) === [
    'canvas.base_url' => 'https://canvas.test', 'canvas.link_name' => 'SLO Cloud Reporting', 'canvas.create_module' => true,
    'canvas.module_name' => 'SLO Cloud', 'canvas.course_delay_ms' => 0, 'canvas.default_scope' => 'term', 'canvas.default_scope_value' => '10',
], 'Defaults are not mapped to the canvas config keys.');
$configDefaults = CanvasDeployRun::defaultsFromConfig(static fn(string $key, mixed $default): mixed => ['canvas.default_scope' => 'bogus', 'canvas.course_delay_ms' => '500', 'canvas.link_name' => ''][$key] ?? $default);
$check($configDefaults['scope'] === 'active' && $configDefaults['course_delay_ms'] === 500 && $configDefaults['link_name'] === 'SLO Cloud Reporting', 'Config defaults were not sanitized.');

$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $canvas, $clock);

$connection = $service->dispatch('test_connection', $settings());
$check($connection['status'] === 200 && $connection['body']['is_admin'] === true && $connection['body']['accounts'][0]['id'] === 1, 'Connection test failed.');
$termsResponse = $service->dispatch('terms', $settings());
$check(array_column($termsResponse['body']['terms'] ?? [], 'id') === [12, 10, 11], 'Terms were not listed newest first.');
$check($service->dispatch('run_start', $settings(['link_name' => '']))['status'] === 422, 'Invalid run settings were not rejected with field errors.');
$check($service->dispatch('bogus', [])['status'] === 400, 'Unknown action accepted.');
$check($service->dispatch('run_start', $settings(['canvas_token' => 'wrong-token']))['body']['error'] === 'canvas_http', 'A rejected token did not surface as a Canvas error.');

$started = $service->dispatch('run_start', $settings(['course_delay_ms' => '500']));
$runId = $started['body']['run_id'] ?? '';
$runKey = $started['body']['run_key'] ?? '';
$check($started['status'] === 200 && preg_match('/^[0-9a-f]{32}$/', $runId) === 1 && preg_match('/^[0-9a-f]{64}$/', $runKey) === 1, 'Run did not start.');
$check(!str_contains(json_encode($store), FakeCanvas::TOKEN) && !str_contains(json_encode($store), $runKey), 'Session store holds the token or run key.');
$check($service->dispatch('run_batch', ['run_id' => $runId, 'run_key' => str_repeat('0', 64)])['body']['error'] === 'run_key_rejected', 'A wrong run key was accepted.');
$otherUser = $newService($store, $canvas, $clock, 2);
$check($otherUser->dispatch('run_batch', ['run_id' => $runId, 'run_key' => $runKey])['body']['error'] === 'run_forbidden', 'Another user could drive the run.');
$check($service->dispatch('run_batch', ['run_id' => str_repeat('a', 32), 'run_key' => $runKey])['body']['error'] === 'run_missing', 'An unknown run id was accepted.');

$secondStore = [];
$secondAdmin = $newService($secondStore, $canvas, $clock, 2);
$check($secondAdmin->dispatch('run_start', $settings())['body']['error'] === 'run_locked', 'A second concurrent run was not blocked.');

$sleepsBefore = count($clock->sleeps);
$batches = [];
$rows = $runAll($service, $runId, $runKey, $batches);
$check(!isset($rows['error']), 'Term run failed: ' . json_encode($rows['error'] ?? null));
$byId = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row['course_id'])) $byId[$row['course_id']] = $row;
}
$check(count($byId) === 130, 'Term run did not cover all 130 term courses across pages (got ' . count($byId) . ').');
$check(count($batches) > 1, 'The run did not split into batches.');
foreach ($batches as $batch) {
    if ($batch['stop_reason'] === 'time_target') {
        $check($batch['elapsed_ms'] >= 20000 && $batch['elapsed_ms'] < 30000, 'A time-boxed batch ended outside 20s plus one course: ' . $batch['elapsed_ms']);
    }
    $check($batch['processed'] >= 1 || $batch['stop_reason'] !== 'time_target', 'A batch made no progress.');
}
$delaySleeps = count(array_filter(array_slice($clock->sleeps, $sleepsBefore), static fn(float $s): bool => $s === 0.5));
$expectedDelays = array_sum(array_map(static fn(array $batch): int => max(0, $batch['processed'] - 1), $batches));
$check($delaySleeps === $expectedDelays, "Delay was not applied only between courses within a batch ({$delaySleeps} vs {$expectedDelays}).");
$check(($batches[0]['delay_ms'] ?? null) === 500, 'The browser is not told the delay to wait between batches.');
$check(($byId[1005]['status'] ?? '') === 'skipped' && str_contains(implode(' ', $byId[1005]['notes']), 'Blueprint'), 'Blueprint child was not listed as skipped.');
$check(($byId[1004]['tool'] ?? '') === 'created' && ($byId[1002]['tool'] ?? '') === 'present' && ($byId[1006]['status'] ?? '') === 'failed', 'Term run outcomes are wrong.');
$finished = $service->dispatch('run_finish', ['run_id' => $runId, 'run_key' => $runKey]);
$check($finished['status'] === 200 && $finished['body']['counts']['processed'] === 130 && $finished['body']['counts']['skipped'] === 1, 'Run finish did not report counts.');
$check(!isset($store['run']), 'Finished run stayed in the session.');
$check(trim((string)@file_get_contents($lockRoot . '/canvas_deploy.lock')) === '', 'Lock was not released on finish.');
$check(array_column($audits, 0) === ['canvas_deploy_start', 'canvas_deploy_finish'], 'Run start and finish were not audited: ' . implode(',', array_column($audits, 0)));
$check(!str_contains(json_encode([$audits, $logs]), FakeCanvas::TOKEN) && !str_contains(json_encode([$audits, $logs]), $runKey), 'Token or run key reached the audit or operational log.');
$check(count($logs) === 130, 'Per-course results were not logged.');

// Re-run is idempotent; dry run writes nothing.
$postsBefore = count(array_filter($canvas->state['calls'], static fn(string $call): bool => str_starts_with($call, 'POST')));
$started = $service->dispatch('run_start', $settings(['dry_run' => '1']));
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$postsAfter = count(array_filter($canvas->state['calls'], static fn(string $call): bool => str_starts_with($call, 'POST')));
$check($postsAfter === $postsBefore, 'Dry run wrote to Canvas.');
$check(count(array_filter($rows, static fn($row): bool => is_array($row) && in_array($row['tool'] ?? '', ['would_create', 'created'], true))) === 0, 'Second pass still wanted to create tools.');
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);

// ALL Active excludes concluded terms; ALL includes them.
$countFor = static function (string $scope) use ($service, $settings, $runAll): int {
    $response = $service->dispatch('run_start', $settings(['scope' => $scope, 'scope_value' => '', 'dry_run' => '1']));
    $batches = [];
    $rows = $runAll($service, $response['body']['run_id'], $response['body']['run_key'], $batches);
    $service->dispatch('run_finish', ['run_id' => $response['body']['run_id'], 'run_key' => $response['body']['run_key']]);
    return count(array_filter($rows, 'is_array'));
};
$active = $countFor('active');
$all = $countFor('all');
$check($all - $active === 5, "ALL Active did not exclude the concluded term ({$active} vs {$all}).");

// Starting again in the same session replaces the earlier run.
$audits = [];
$first = $service->dispatch('run_start', $settings(['dry_run' => '1']));
$second = $service->dispatch('run_start', $settings(['dry_run' => '1']));
$check($second['status'] === 200 && in_array('canvas_deploy_replaced', array_column($audits, 0), true), 'A new run in the same session did not replace the old one.');
$check($service->dispatch('run_batch', ['run_id' => $first['body']['run_id'], 'run_key' => $first['body']['run_key']])['body']['error'] === 'run_missing', 'A replaced run could still be driven.');

// Idle expiry frees the lock for another administrator.
$clock->now += CanvasDeployRun::IDLE_EXPIRY + 60;
$takeover = $secondAdmin->dispatch('run_start', $settings(['dry_run' => '1']));
$check($takeover['status'] === 200, 'A stale lock blocked a new run.');
$check($service->dispatch('run_batch', ['run_id' => $second['body']['run_id'], 'run_key' => $second['body']['run_key']])['body']['error'] === 'run_missing', 'An expired run could still be driven.');
$secondAdmin->dispatch('run_cancel', ['run_id' => $takeover['body']['run_id'], 'run_key' => $takeover['body']['run_key']]);

// A course that keeps timing out is retried, then failed after three deadline hits.
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['slow' => true, 'fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $canvas, $clock);
$started = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1007,1001']));
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$check(!isset($rows['error']), 'Slow-course run errored: ' . json_encode($rows['error'] ?? null));
$reasons = array_column($batches, 'stop_reason');
$check(array_slice($reasons, 0, 2) === ['deadline', 'deadline'] && count($batches) === 3, 'Slow course was not retried twice before failing: ' . implode(',', $reasons));
$slow = array_values(array_filter($rows, static fn($row): bool => is_array($row) && ($row['course_id'] ?? 0) === 1007))[0] ?? [];
$check(($slow['status'] ?? '') === 'failed' && str_contains(implode(' ', $slow['notes'] ?? []), 'Timed out'), 'Slow course was not failed after three deadline hits.');
$fresh = array_values(array_filter($rows, static fn($row): bool => is_array($row) && ($row['course_id'] ?? 0) === 1001))[0] ?? [];
$check(($fresh['tool'] ?? '') === 'created', 'The run did not continue after the slow course.');
foreach ($batches as $batch) {
    $check($batch['elapsed_ms'] <= 45000, 'A batch exceeded the 45-second hard deadline: ' . $batch['elapsed_ms']);
}
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);

// Look ahead with this run's slowest course so far: a step doesn't start a course that slow
// if it would end past the target, but always does at least one.
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $canvas, $clock);
$started = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1002,1003,1004,1008,1009,1010,1011', 'course_delay_ms' => '100']));
$steps = [];
for ($i = 0; $i < 30; $i++) {
    $step = $service->batch($started['body']['run_id'], $started['body']['run_key'], null, 1.5, 10.0);
    $steps[] = $step;
    if ($step['done']) {
        break;
    }
}
$reasons = array_column($steps, 'stop_reason');
$average = (float)end($steps)['course_seconds'];
$longest = (float)($store['run']['course_seconds_max'] ?? 0);
$check(in_array('estimate', $reasons, true) && $average > 0 && $longest >= $average && end($steps)['remaining'] === 0,
    'Steps did not end on the per-run estimate: ' . implode(',', $reasons) . ' avg ' . $average . ' max ' . $longest);
foreach ($steps as $index => $step) {
    $check($step['processed'] >= 1, 'A step did no course at all.');
    if ($index > 0 && $step['processed'] > 1) {
        $check($step['elapsed_ms'] / 1000 <= 1.5, 'A step ran past its target: ' . $step['elapsed_ms'] . ' ms.');
    }
}
$check(array_sum(array_column($steps, 'processed')) === 8, 'The look-ahead skipped or repeated courses.');
$check($steps[0]['remaining'] === 8 - $steps[0]['processed'], 'Courses left was not counted.');
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);

// A batch reply that never reaches the page loses no rows: rows the page has not confirmed
// are sent again, and the page ends with one row per course counted.
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['slow' => true, 'fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $canvas, $clock);
$started = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1007,1002']));
$ids = ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']];
$pageRows = [];
$lost = false;
for ($i = 0; $i < 20; $i++) {
    $reply = $service->dispatch('run_batch', $ids + ['received' => (string)count($pageRows)])['body'];
    if (!$lost && $reply['processed'] > 0) {
        $lost = true; // This reply never arrives.
        continue;
    }
    array_push($pageRows, ...array_slice($reply['results'], count($pageRows) - $reply['results_from']));
    if ($reply['done']) {
        break;
    }
}
$check($lost && count($pageRows) === $reply['counts']['processed'] && count($pageRows) === 3
    && array_column($pageRows, 'course_id') === array_unique(array_column($pageRows, 'course_id')),
    'Rows from a lost batch reply were not sent again, or were sent twice: ' . json_encode(array_column($pageRows, 'course_id')));
$service->dispatch('run_finish', $ids);

// Throttling is absorbed; account-level installs skip course tool lookups; course IDs report lookups.
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['account_tool' => true, 'throttle' => 2, 'fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $canvas, $clock);
$started = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1005,999999', 'create_module' => '0']));
$check($started['status'] === 200 && $started['body']['account_tool'] === true, 'Throttled start or account-level detection failed: ' . json_encode($started['body']));
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$byId = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row['course_id'])) $byId[$row['course_id']] = $row;
}
$check(($byId[1001]['tool'] ?? '') === 'present_account' && ($byId[1001]['module'] ?? '') === 'off', 'Account-level install was not reported.');
$check(($byId[1005]['status'] ?? '') === 'skipped', 'Blueprint child picked by ID was not skipped.');
$check(($byId[999999]['status'] ?? '') === 'failed' && str_contains(implode(' ', $byId[999999]['notes'] ?? []), '404'), 'Missing course ID was not reported.');
$check(!in_array('GET /api/v1/courses/1001/external_tools', $canvas->state['calls'], true), 'Course tool lookup ran despite the account-level install.');

// Removing the tool and its module link. Earlier blocks leave their run lock held.
array_map('unlink', glob($lockRoot . '/*') ?: []);
$removeCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$removeCanvas->state['courses'][1010]['modules'][0]['items'] = [['id' => 7100, 'type' => 'ExternalTool', 'title' => 'SLO', 'external_url' => $launch]];
$removeCanvas->state['courses'][1010]['modules'][0]['published'] = false;
$removeCanvas->state['courses'][1010]['modules'][0]['name'] = 'SLO Cloud';
$removeSettings = static fn(array $overrides = []): array => $settings(['scope' => 'course', 'scope_value' => '1001,1002,1010,1011', 'operation' => 'remove'] + $overrides);
$store = [];
$service = $newService($store, $removeCanvas, $clock);
$started = $service->dispatch('run_start', $removeSettings(['dry_run' => '1']));
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$byId = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row['course_id'])) $byId[$row['course_id']] = $row;
}
$check(($byId[1001]['tool'] ?? '') === 'absent', 'A course without the tool was not reported as absent.');
$check(($byId[1002]['tool'] ?? '') === 'would_remove' && count($removeCanvas->state['courses'][1002]['tools']) === 1, 'A removal dry run did not report the tool or changed Canvas.');
$check(($byId[1011]['module'] ?? '') === 'would_remove' && count($removeCanvas->state['courses'][1011]['modules'][0]['items']) === 1, 'A removal dry run did not report the module link or changed Canvas.');
$check(!in_array('DELETE', array_map(static fn(string $call): string => explode(' ', $call)[0], $removeCanvas->state['calls']), true), 'A removal dry run sent a DELETE.');

$store = [];
$service = $newService($store, $removeCanvas, $clock);
$started = $service->dispatch('run_start', $removeSettings());
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$byId = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row['course_id'])) $byId[$row['course_id']] = $row;
}
$check(($byId[1002]['tool'] ?? '') === 'removed' && $removeCanvas->state['courses'][1002]['tools'] === [], 'The course tool was not removed.');
$check(($byId[1011]['module'] ?? '') === 'removed' && $removeCanvas->state['courses'][1011]['modules'][0]['items'] === [], 'The module link was not removed.');
$check(count($removeCanvas->state['courses'][1011]['modules']) === 1, 'A module that is not the SLO Cloud module was deleted.');
$check(($byId[1010]['module'] ?? '') === 'removed' && !array_filter($removeCanvas->state['courses'][1010]['modules'], static fn(array $module): bool => $module['name'] === 'SLO Cloud'), 'The emptied, unpublished SLO Cloud module was not deleted.');
$service->dispatch('run_cancel', ['run_id' => '', 'run_key' => '']);

// Updating the credentials of a tool that is already in the course.
array_map('unlink', glob($lockRoot . '/*') ?: []);
$updateCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$updateSettings = static fn(array $overrides = []): array => $settings(['scope' => 'course', 'scope_value' => '1001,1002,1003', 'create_module' => '0'] + $overrides);
$runUpdate = static function (array $overrides) use ($newService, $updateCanvas, $clock, $runAll, $updateSettings): array {
    $store = [];
    $service = $newService($store, $updateCanvas, $clock);
    $started = $service->dispatch('run_start', $updateSettings($overrides));
    $batches = [];
    $rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
    $service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
    $byId = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['course_id'])) $byId[$row['course_id']] = $row;
    }
    return $byId;
};
$left = $runUpdate([]);
$check(($left[1003]['tool'] ?? '') === 'present' && ($updateCanvas->state['courses'][1003]['tools'][0]['consumer_key'] ?? '') === 'old-key', 'Without the option an existing tool was changed.');
$dry = $runUpdate(['update_existing' => '1', 'dry_run' => '1']);
$check(($dry[1003]['tool'] ?? '') === 'would_update' && ($updateCanvas->state['courses'][1003]['tools'][0]['consumer_key'] ?? '') === 'old-key', 'The update dry run did not report, or it changed Canvas.');
$updated = $runUpdate(['update_existing' => '1']);
$check(($updated[1003]['tool'] ?? '') === 'updated' && ($updateCanvas->state['courses'][1003]['tools'][0]['consumer_key'] ?? '') === FakeCanvas::CONSUMER_KEY, 'The existing tool was not given the new key.');
$check(count($updateCanvas->state['courses'][1003]['tools']) === 1, 'Updating added a second tool.');

// Account-level installs cannot be removed from a course.
$accountCanvas = new FakeCanvas(FakeCanvas::scenario(['account_tool' => true]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $newService($store, $accountCanvas, $clock);
$started = $service->dispatch('run_start', $removeSettings(['scope_value' => '1001', 'dry_run' => '1']));
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$check(($rows[0]['tool'] ?? '') === 'present_account' && ($rows[0]['status'] ?? '') === 'warning', 'An account-level install was not reported as not removable here.');

// The LTI settings are optional on the server: the user can type them in.
$noLti = static function (array &$store, FakeCanvas $canvas) use ($clock, $lockRoot): CanvasDeployRun {
    return new CanvasDeployRun(
        $store, [], $lockRoot, 1, null, null, null,
        static fn(string $base, string $token, ?float $deadline): CanvasClient => new CanvasClient($base, $token, new FakeCanvasTransport($canvas, $clock, 0.1), $deadline, $clock, [$clock, 'sleep']),
        $clock,
        [$clock, 'sleep']
    );
};
$typedCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$service = $noLti($store, $typedCanvas);
$missing = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001']));
$check($missing['status'] === 422 && array_keys($missing['body']['fields'] ?? []) === ['lti_launch_url', 'lti_consumer_key', 'lti_consumer_secret'], 'Installing should require the launch URL, key and secret.');
$urlOnlyCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$urlOnlyStore = [];
$urlOnly = $noLti($urlOnlyStore, $urlOnlyCanvas);
$urlOnlyStart = $urlOnly->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'lti_launch_url' => $launch]));
$check($urlOnlyStart['status'] === 422 && array_keys($urlOnlyStart['body']['fields'] ?? []) === ['lti_consumer_key', 'lti_consumer_secret'], 'Installing with only the launch URL was not refused.');

// With the key and secret: existing tools are left alone, the Nav column reflects them.
$started = $urlOnly->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1002', 'lti_launch_url' => $launch, 'lti_consumer_key' => FakeCanvas::CONSUMER_KEY, 'lti_consumer_secret' => 's']));
$batches = [];
$rows = $runAll($urlOnly, $started['body']['run_id'], $started['body']['run_key'], $batches);
$urlOnly->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$byId = array_column($rows, null, 'course_id');
$check(($byId[1001]['tool'] ?? '') === 'created' && ($byId[1001]['nav'] ?? '') === 'off' && empty($urlOnlyCanvas->state['courses'][1001]['tools'][0]['course_navigation'])
    && ($byId[1002]['tool'] ?? '') === 'present' && ($byId[1002]['nav'] ?? '') === 'present', 'Tool or Nav column wrong with "Add navigation" off.');

// The added module goes to the top: a new one is created first, an existing one is moved up.
$topCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$topCanvas->state['courses'][1001]['modules'] = [['id' => 601, 'name' => 'Week 1', 'published' => true, 'items' => []]];
array_unshift($topCanvas->state['courses'][1010]['modules'], ['id' => 602, 'name' => 'Week 1', 'published' => true, 'items' => []]);
$topStore = [];
$top = $noLti($topStore, $topCanvas);
$started = $top->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1010', 'create_module' => '1', 'lti_launch_url' => $launch, 'lti_consumer_key' => 'k', 'lti_consumer_secret' => 's']));
$batches = [];
$runAll($top, $started['body']['run_id'], $started['body']['run_key'], $batches);
$top->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$firstModule = static fn(int $id): string => (string)($topCanvas->state['courses'][$id]['modules'][0]['name'] ?? '');
$check($firstModule(1001) === 'SLO Cloud' && count($topCanvas->state['courses'][1001]['modules']) === 2, 'A new SLO Cloud module was not created at the top.');
$check($firstModule(1010) === 'SLO Cloud' && count($topCanvas->state['courses'][1010]['modules']) === 2, 'An existing SLO Cloud module was not moved to the top.');

// Any https launch URL is accepted (e.g. https://cloud.coastline.edu/SLO); plain http is not.
$httpUrl = $urlOnly->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'lti_launch_url' => 'http://slo.example.edu/SLO']));
$check($httpUrl['status'] === 422 && array_key_exists('lti_launch_url', $httpUrl['body']['fields'] ?? []), 'A plain http launch URL was accepted.');

// Doubtful matches (no nav link, or matched only by domain) are reported and nothing is added.
$noNavCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$noNavCanvas->state['courses'][1002]['tools'][0]['course_navigation'] = null;
$noNavCanvas->state['courses'][1001]['tools'] = [FakeCanvas::tool(805, 'Coastline Portal', null, 'other-key', 'public', 'slo.example.edu')];
$noNavStore = [];
$noNav = $noLti($noNavStore, $noNavCanvas);
$started = $noNav->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001,1002', 'create_module' => '1', 'lti_launch_url' => $launch, 'lti_consumer_key' => 'k', 'lti_consumer_secret' => 's', 'create_nav' => '1']));
$batches = [];
$rows = $runAll($noNav, $started['body']['run_id'], $started['body']['run_key'], $batches);
$noNav->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$byId = array_column($rows, null, 'course_id');
$hasNote = static fn(array $row, string $text): bool => (bool)array_filter($row['notes'] ?? [], static fn(string $note): bool => str_contains($note, $text));
$navTool = $noNavCanvas->state['courses'][1002]['tools'][0];
$check(($byId[1002]['tool'] ?? '') === 'present' && ($byId[1002]['nav'] ?? '') === 'added' && ($byId[1002]['module'] ?? '') === 'created'
    && ($byId[1002]['status'] ?? '') === 'ok'
    && ($navTool['course_navigation']['text'] ?? '') !== '' && empty($navTool['shared_secret_set']),
    'A course tool without a nav link did not get one (without touching its secret).');
$check(($byId[1001]['tool'] ?? '') === 'doubtful' && ($byId[1001]['status'] ?? '') === 'skipped'
    && $hasNote($byId[1001], 'only by its domain') && $hasNote($byId[1001], 'Coastline Portal'), 'A domain-only match was not reported and skipped.');
$check(count($noNavCanvas->state['courses'][1001]['tools']) === 1 && $noNavCanvas->state['courses'][1001]['modules'] === [],
    'Something was added to a course with a doubtful match.');

// An account tool without a nav link. "Add navigation" off: nothing is done about the nav (no
// course copy). On: the key and secret are required, the key must match the account tool's, and
// a course copy carries the nav link.
$accountNoNavRun = static function (array $extra) use ($clock, $noLti, $settings, $launch, $runAll): array {
    $canvas = new FakeCanvas(FakeCanvas::scenario(['account_tool' => true]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
    $canvas->state['account_tools'][0]['course_navigation'] = null;
    $store = [];
    $service = $noLti($store, $canvas);
    $started = $service->dispatch('run_start', $settings($extra + ['scope' => 'course', 'scope_value' => '1001', 'create_module' => '1', 'lti_launch_url' => $launch, 'lti_consumer_key' => FakeCanvas::CONSUMER_KEY, 'lti_consumer_secret' => 's']));
    if ($started['status'] !== 200) {
        return [[], $canvas, $started];
    }
    $batches = [];
    $rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
    $finished = $service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
    return [$rows[0] ?? [], $canvas, $started, $finished['body']['counts'] ?? []];
};
[$row, $canvasAfter, $started] = $accountNoNavRun([]);
$check(($started['body']['account_tool'] ?? true) === false, 'An account tool without a nav link was trusted for every course.');
$check(($row['tool'] ?? '') === 'present_account' && ($row['nav'] ?? '') === 'off' && ($row['status'] ?? '') === 'ok' && ($row['module'] ?? '') === 'created'
    && $canvasAfter->state['courses'][1001]['tools'] === [],
    '"Add navigation" off: the nav should be "Not requested", with no course copy and the module still added.');
[, , $started] = $accountNoNavRun(['create_nav' => '1', 'lti_consumer_key' => '', 'lti_consumer_secret' => '']);
$check($started['status'] === 422 && array_keys($started['body']['fields'] ?? []) === ['lti_consumer_key', 'lti_consumer_secret'],
    '"Add navigation" without a key and secret was not refused field by field.');
[$row, $canvasAfter] = $accountNoNavRun(['create_nav' => '1', 'lti_consumer_key' => 'other-key', 'lti_consumer_secret' => 's']);
$check(($row['nav'] ?? '') === 'not_added' && $hasNote($row, 'does not match') && $canvasAfter->state['courses'][1001]['tools'] === [],
    '"Add navigation" with a key that differs from the account tool: should add nothing and say why.');
[$row, $canvasAfter] = $accountNoNavRun(['create_nav' => '1', 'lti_consumer_key' => FakeCanvas::CONSUMER_KEY, 'lti_consumer_secret' => 's', 'dry_run' => '1']);
$check(($row['tool'] ?? '') === 'would_create_for_nav' && ($row['nav'] ?? '') === 'would_add' && $canvasAfter->state['courses'][1001]['tools'] === [],
    '"Add navigation" dry run: should say it would add the nav copy, and change nothing.');
[$row, $canvasAfter, , $copyCounts] = $accountNoNavRun(['create_nav' => '1', 'lti_consumer_key' => FakeCanvas::CONSUMER_KEY, 'lti_consumer_secret' => 's']);
$check(($copyCounts['tool_created'] ?? -1) === 0 && ($copyCounts['tool_present'] ?? 0) === 1 && ($copyCounts['tool_copied'] ?? 0) === 1,
    'A course copy for the nav link should count as already present plus a copy, not as a new install.');
$added = $canvasAfter->state['courses'][1001]['tools'][0] ?? [];
$check(($row['tool'] ?? '') === 'created_for_nav' && ($row['nav'] ?? '') === 'added' && ($row['status'] ?? '') === 'ok' && ($row['module'] ?? '') === 'created'
    && !empty($added['course_navigation']) && !empty($added['shared_secret_set']), '"Add navigation" with the key and secret: the course nav copy was not added.');

// Opt-in: add the nav link to the account tool itself. A dry run changes nothing.
foreach ([true, false] as $dry) {
    $fixCanvas = new FakeCanvas(FakeCanvas::scenario(['account_tool' => true]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
    $fixCanvas->state['account_tools'][0]['course_navigation'] = null;
    $fixStore = [];
    $fixService = $noLti($fixStore, $fixCanvas);
    $started = $fixService->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'lti_launch_url' => $launch, 'lti_consumer_key' => FakeCanvas::CONSUMER_KEY, 'lti_consumer_secret' => 's', 'fix_account_nav' => '1', 'dry_run' => $dry ? '1' : '0']));
    $batches = [];
    $rows = $runAll($fixService, $started['body']['run_id'], $started['body']['run_key'], $batches);
    $fixService->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
    $accountNav = $fixCanvas->state['account_tools'][0]['course_navigation'] ?? null;
    $check($dry ? ($accountNav === null && ($rows[0]['nav'] ?? '') === 'would_add') : (!empty($accountNav) && ($rows[0]['nav'] ?? '') === 'present'),
        'Adding the nav link to the account tool did not work' . ($dry ? ' (dry run)' : '') . '.');
    $check($fixCanvas->state['courses'][1001]['tools'] === [], 'A course tool was created although the account tool got the nav link.');
}

// Course list flags: app, nav and module, read-only.
$flagsCanvas = new FakeCanvas(FakeCanvas::scenario(), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$flagsStore = [];
$flagsService = $noLti($flagsStore, $flagsCanvas);
$noUrl = $flagsService->dispatch('course_flags', ['base_url' => 'https://canvas.test', 'canvas_token' => FakeCanvas::TOKEN, 'course_ids' => '1001']);
$check(($noUrl['body']['launch_url_known'] ?? true) === false, 'Course flags ran without a launch URL.');
$callsBefore = count($flagsCanvas->state['calls']);
$flagged = $flagsService->dispatch('course_flags', ['base_url' => 'https://canvas.test', 'canvas_token' => FakeCanvas::TOKEN, 'lti_launch_url' => $launch, 'course_ids' => '1001,1002,1011']);
$f = $flagged['body']['flags'] ?? [];
$check(($f['1001'] ?? []) === array_merge($f['1001'] ?? [], ['app' => 'no', 'nav' => 'no', 'module' => 'no'])
    && ($f['1002']['app'] ?? '') === 'yes' && ($f['1002']['nav'] ?? '') === 'yes' && ($f['1002']['module'] ?? '') === 'no'
    && ($f['1011']['module'] ?? '') === 'yes', 'Course list flags are wrong.');
$check(!array_filter(array_slice($flagsCanvas->state['calls'], $callsBefore), static fn(string $call): bool => !str_starts_with($call, 'GET ')), 'Course list flags changed something in Canvas.');
// A slow course: the call stops starting new courses after the soft limit and hands the rest back.
$flagsCanvas->state['slow_courses'] = [1001];
$flagsCanvas->state['slow_seconds'] = 7;
$slow = $flagsService->dispatch('course_flags', ['base_url' => 'https://canvas.test', 'canvas_token' => FakeCanvas::TOKEN, 'lti_launch_url' => $launch, 'course_ids' => '1001,1002,1011']);
$check(isset($slow['body']['flags']['1001']) && ($slow['body']['pending'] ?? []) === [1002, 1011], 'Course flags did not stop early and hand back the remaining courses.');
$removeNeedsUrl = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'operation' => 'remove']));
$check($removeNeedsUrl['status'] === 422 && array_keys($removeNeedsUrl['body']['fields'] ?? []) === ['lti_launch_url'], 'Removing should only need the launch URL.');
$started = $service->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'lti_launch_url' => $launch, 'lti_consumer_key' => 'typed-key', 'lti_consumer_secret' => 'typed-secret']));
$check($started['status'] === 200, 'Typed LTI settings were refused.');
$batches = [];
$rows = $runAll($service, $started['body']['run_id'], $started['body']['run_key'], $batches);
$service->dispatch('run_finish', ['run_id' => $started['body']['run_id'], 'run_key' => $started['body']['run_key']]);
$installed = $typedCanvas->state['courses'][1001]['tools'][0] ?? [];
$check(($rows[0]['tool'] ?? '') === 'created' && ($rows[0]['nav'] ?? '') === 'off' && ($installed['consumer_key'] ?? '') === 'typed-key' && !empty($installed['shared_secret_set']), 'The tool was not installed with the typed key and secret.');
$check(!str_contains(json_encode($_SESSION ?? []) . json_encode($store), 'typed-secret'), 'The typed shared secret was stored in clear text.');

// Non-admin tokens are refused.
$canvas->state['accounts'] = [];
$store = [];
$service = $newService($store, $canvas, $clock);
$check($service->dispatch('run_start', $settings())['body']['error'] === 'not_admin', 'A non-admin token could start a run.');
$check($service->dispatch('test_connection', $settings())['body']['is_admin'] === false, 'Non-admin token reported as admin.');

$prerequisites = CanvasDeployRun::prerequisites(['launch_url' => 'http://slo.example.edu/lti/launch.php', 'consumer_key' => '', 'consumer_secret' => 's']);
$check(!$prerequisites[0]['ok'] && !$prerequisites[1]['ok'] && $prerequisites[2]['ok'], 'Prerequisites do not require an https launch URL and a consumer key.');

// The shared queue: runs from several people take turns, a worker finishes them without a
// browser, and the token and keys are deleted when a run ends.
$queueDir = $lockRoot . '/queue';
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$queueEngine = static fn(array &$store): CanvasDeployRun => $newService($store, $canvas, $clock, 0)->forQueue();
$queueLog = [];
$queue = new DeployQueue($queueDir, Encryption::generateKey(), $queueEngine, $clock, [$clock, 'sleep'],
    static function (string $event, array $data) use (&$queueLog): void { $queueLog[] = [$event, $data]; });
$eventsFor = static function (string $id) use (&$queueLog): array {
    return array_values(array_filter($queueLog, static fn(array $entry): bool => ($entry[1]['run_id'] ?? '') === $id));
};
$enqueue = static function (array $extra) use ($queueEngine, $queue, $settings): array {
    $store = [];
    $started = $queueEngine($store)->dispatch('run_start', $settings($extra));
    return $started['status'] === 200 ? $queue->create($store, $started['body']) : ['error' => $started['body']];
};
$first = $enqueue(['scope' => 'course', 'scope_value' => '1001,1002,1003']);
$second = $enqueue(['scope' => 'course', 'scope_value' => '1004,1008', 'dry_run' => '1']);
$check(isset($first['id'], $second['id']), 'Two runs could not be queued side by side: ' . json_encode([$first['error'] ?? null, $second['error'] ?? null]));
$check(!str_contains((string)file_get_contents($queueDir . '/' . $first['id'] . '.json'), FakeCanvas::TOKEN), 'The queue file holds the Canvas token in the clear.');
$check($queue->view($first['id'])['status'] === 'queued' && $queue->view($first['id'])['ahead'] === 0 && $queue->view($second['id'])['ahead'] === 1,
    'Runs did not report their place in line.');
$check($queue->view($second['id'])['ahead_courses'] === 3 && $queue->view($second['id'])['ahead_seconds'] > 0,
    'The work ahead (courses left in earlier runs) was not counted: ' . json_encode(array_intersect_key($queue->view($second['id']), array_flip(['ahead_courses', 'ahead_seconds']))));

$status = $queue->status();
$check($status['runs'] === 2 && $status['running'] === 0 && $status['courses'] === 5 && $status['unknown_runs'] === 0 && $status['seconds'] > 0,
    'The queue status did not count the runs and courses waiting: ' . json_encode($status));

// A page helping (no worker) steps the first run in line, even from the second run's page,
// and never starts the second run while the first is being stepped.
$clock->now += 1;
$check($queue->stepNext(0.1, 10.0) === $first['id'] && $queue->view($first['id'])['status'] === 'running' && $queue->view($second['id'])['last_step_at'] === 0,
    'A page step did not go to the first run in line.');
$headLock = fopen($queueDir . '/' . $first['id'] . '.lock', 'c');
flock($headLock, LOCK_EX);
$check($queue->stepNext(2.0, 10.0) === null && $queue->view($second['id'])['last_step_at'] === 0, 'The second run was started while the first was being stepped.');
flock($headLock, LOCK_UN);
fclose($headLock);

// A step that another step holds is refused, not run twice.
$held = fopen($queueDir . '/' . $first['id'] . '.lock', 'c');
flock($held, LOCK_EX);
$check($queue->step($first['id'], 5.0, 10.0) === 'busy', 'A run being stepped could be stepped again at the same time.');
flock($held, LOCK_UN);
fclose($held);

// Only one worker at a time.
$heldWorker = fopen($queueDir . '/worker.lock', 'c');
flock($heldWorker, LOCK_EX);
$check($queue->work(30.0, 5.0, 10.0)['worker'] === 'busy', 'A second worker ran while one was running.');
flock($heldWorker, LOCK_UN);
fclose($heldWorker);
$check(!$queue->workerFresh(), 'The worker counted as running before it ever ran.');

$summary = $queue->work(120.0, 5.0, 10.0);
$check($summary['worker'] === 'ran' && $queue->workerFresh(), 'The worker did not run: ' . json_encode($summary));
foreach ([[$first, 3], [$second, 2]] as [$job, $courses]) {
    $view = $queue->view($job['id']);
    $raw = json_decode((string)file_get_contents($queueDir . '/' . $job['id'] . '.json'), true);
    $check($view['status'] === 'done' && $view['counts']['processed'] === $courses && $view['results_total'] === $courses,
        'A queued run did not finish with one row per course: ' . json_encode([$view['status'], $view['counts']['processed'] ?? null, $view['results_total']]));
    $check($raw['store'] === null && $raw['run_key'] === null, 'A finished run kept its token or key.');
}
$check(count($canvas->state['courses'][1001]['tools']) === 1 && $canvas->state['courses'][1008]['tools'] === [], 'The worker did not deploy the real run, or wrote during the dry run.');

// First come, first served: the first run finished before the second had a single step.
$stepOrder = array_column(array_map(static fn(array $entry): array => $entry[1], array_filter($queueLog, static fn(array $entry): bool => $entry[0] === 'run_step')), 'run_id');
$lastFirst = max(array_keys($stepOrder, $first['id'], true));
$firstSecond = min(array_keys($stepOrder, $second['id'], true) ?: [PHP_INT_MAX]);
$check($lastFirst < $firstSecond, 'Runs were interleaved instead of one at a time: ' . implode(',', array_map(static fn(string $id): string => $id === $first['id'] ? 'A' : 'B', $stepOrder)));

// Every decision is logged: queued, each step and why it stopped, how the run ended.
$firstLog = $eventsFor($first['id']);
$names = array_column($firstLog, 0);
$steps = array_values(array_filter($firstLog, static fn(array $entry): bool => $entry[0] === 'run_step'));
$ended = array_values(array_filter($firstLog, static fn(array $entry): bool => $entry[0] === 'run_ended'))[0][1] ?? [];
$check($names[0] === 'run_queued' && end($names) === 'run_ended' && $steps !== [], 'A run\'s log did not go queued, steps, ended: ' . implode(',', $names));
$check(array_sum(array_column(array_column($steps, 1), 'courses')) === 3
    && array_filter(array_column($steps, 1), static fn(array $step): bool => $step['by'] === 'worker' && $step['stopped'] !== '' && isset($step['why']['detail'], $step['target'], $step['deadline'])) !== [],
    'A step was logged without who took it, how many courses, or why it stopped: ' . json_encode($steps[0][1] ?? null));
$check(($ended['status'] ?? '') === 'done' && ($ended['reason'] ?? '') !== '' && ($ended['token_deleted'] ?? false) === true, 'How the run ended was not logged: ' . json_encode($ended));
$check(in_array('worker_run', array_column($queueLog, 0), true) && in_array('worker_skipped', array_column($queueLog, 0), true)
    && in_array('run_step_skipped', array_column($queueLog, 0), true), 'Worker runs, a skipped worker, or a skipped step were not logged.');
$check(!str_contains(json_encode($queueLog), FakeCanvas::TOKEN), 'The token reached the queue log.');
$check(count($queue->owned('https://canvas.test', 1)) === 2 && $queue->owned('https://canvas.test', 2) === [] && $queue->owned('https://other.test', 1) === [],
    'Runs were not listed by their Canvas owner only.');
$check(count($queue->view($first['id'], 2)['results']) === 1, 'A view did not start at the requested row.');

// Result rows live in their own file, not in the run file the queue checks keep reading.
$check(!str_contains((string)file_get_contents($queueDir . '/' . $first['id'] . '.json'), '"course_id"')
    && count(file($queueDir . '/' . $first['id'] . '.rows')) === 3, 'Result rows were kept in the run file instead of the rows file.');

// A step that wrote rows but crashed before saving the run leaves extra rows behind; the next
// step cuts them off, so no course appears twice.
$crashy = $enqueue(['scope' => 'course', 'scope_value' => '1001,1002,1003']);
$queue->step($crashy['id'], 0.1, 10.0, 'test');
file_put_contents($queueDir . '/' . $crashy['id'] . '.rows', json_encode(['course_id' => 1002, 'left_over' => true]) . "\n", FILE_APPEND);
for ($i = 0; $i < 10 && $queue->view($crashy['id'])['status'] !== 'done'; $i++) {
    $queue->step($crashy['id'], 0.1, 10.0, 'test');
}
$crashRows = $queue->view($crashy['id'])['results'];
$check(count($crashRows) === 3 && array_column($crashRows, 'course_id') === array_unique(array_column($crashRows, 'course_id'))
    && !in_array(true, array_column($crashRows, 'left_over'), true), 'Rows left by a crashed step were shown or duplicated: ' . json_encode(array_column($crashRows, 'course_id')));

// Cancel: a run nobody is stepping stops at once and loses its token.
$cancelled = $enqueue(['scope' => 'course', 'scope_value' => '1005']);
$queue->cancel($cancelled['id']);
$raw = json_decode((string)file_get_contents($queueDir . '/' . $cancelled['id'] . '.json'), true);
$check($raw['status'] === 'cancelled' && $raw['store'] === null, 'A cancelled run kept going or kept its token.');
$check(array_slice(array_column($eventsFor($cancelled['id']), 0), -2) === ['run_cancel_requested', 'run_ended'], 'A cancel was not logged.');

// A run waiting behind a run that keeps moving does not expire, however long it waits.
$long = $enqueue(['scope' => 'course', 'scope_value' => '1011']);
$behind = $enqueue(['scope' => 'course', 'scope_value' => '1003']);
$clock->now += DeployQueue::STALE_SECONDS - 60;
$queue->stepNext(0.1, 10.0, 'test');
$clock->now += 120;
$queue->maintain();
$check($queue->view($behind['id'])['status'] === 'queued', 'A run waiting behind a moving run expired.');
$queue->cancel($long['id']);
$queue->cancel($behind['id']);

// A queue nothing moves for two hours expires its runs; finished runs go after seven days.
$stale = $enqueue(['scope' => 'course', 'scope_value' => '1009']);
$clock->now += DeployQueue::STALE_SECONDS + 60;
$queue->maintain();
$raw = json_decode((string)file_get_contents($queueDir . '/' . $stale['id'] . '.json'), true);
$check($raw['status'] === 'expired' && $raw['store'] === null && $raw['run_key'] === null, 'A stale run did not expire, or kept its token.');
$staleEnd = array_values(array_filter($eventsFor($stale['id']), static fn(array $entry): bool => $entry[0] === 'run_ended'))[0][1] ?? [];
$check(($staleEnd['status'] ?? '') === 'expired' && ($staleEnd['by'] ?? '') === 'maintenance' && str_contains((string)($staleEnd['reason'] ?? ''), '2 hours'), 'An expiry was not logged with its reason.');
$clock->now += DeployQueue::KEEP_SECONDS + 60;
$queue->maintain();
$check(!is_file($queueDir . '/' . $first['id'] . '.json') && !is_file($queueDir . '/' . $first['id'] . '.rows') && !is_file($queueDir . '/' . $stale['id'] . '.json'),
    'Old finished runs (or their result rows) were not deleted.');
$check(in_array('run_deleted', array_column($eventsFor($first['id']), 0), true), 'Deleting an old run was not logged.');

// A wrong queue key cannot read the run keys: the run stops instead of guessing.
$orphan = $enqueue(['scope' => 'course', 'scope_value' => '1010']);
$otherKey = new DeployQueue($queueDir, Encryption::generateKey(), $queueEngine, $clock, [$clock, 'sleep']);
$check($otherKey->step($orphan['id'], 5.0, 10.0) === 'stepped' && $otherKey->view($orphan['id'])['status'] === 'failed', 'A run was not stopped when its key could not be read.');

// Addresses work with or without https://.
$check(CanvasClient::normalizeBaseUrl('coastdistrict.instructure.com') === 'https://coastdistrict.instructure.com'
    && CanvasClient::normalizeBaseUrl(' coastdistrict.instructure.com/ ') === 'https://coastdistrict.instructure.com'
    && CanvasClient::normalizeBaseUrl('https://coastdistrict.instructure.com') === 'https://coastdistrict.instructure.com'
    && CanvasClient::withScheme('') === '' && CanvasClient::withScheme('http://127.0.0.1:8768') === 'http://127.0.0.1:8768',
    'A Canvas URL without https:// was not accepted, or one with a scheme was changed.');
$check($throws(static fn() => CanvasClient::normalizeBaseUrl('ftp://college.instructure.com'), CanvasApiException::class), 'A non-https scheme was accepted.');
$store = [];
$bare = $newService($store, $canvas, $clock)->forQueue()->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1001', 'dry_run' => '1', 'lti_launch_url' => preg_replace('#^https://#', '', $launch)]));
$check($bare['status'] === 200, 'A launch URL without https:// was refused: ' . json_encode($bare['body']));

// Nav settings: validated, passed to each course, and counted.
$navValid = CanvasDeployRun::validateSettings($settings(['create_nav' => '1', 'update_nav' => '1', 'nav_default' => 'disabled']))['settings'];
$check($navValid['update_nav'] === true && $navValid['nav_default'] === 'disabled', 'Nav settings were not read.');
$navNoCreate = CanvasDeployRun::validateSettings($settings(['create_nav' => '0', 'update_nav' => '1', 'nav_default' => 'bogus']))['settings'];
$check($navNoCreate['update_nav'] === false && $navNoCreate['nav_default'] === 'enabled', 'Nav update was allowed without the nav link, or a bad default kept.');
$clock = new FakeClock();
$canvas = new FakeCanvas(FakeCanvas::scenario(['fresh_courses' => 0]), static function (float $seconds) use ($clock): void { $clock->now += $seconds; });
$store = [];
$navService = $newService($store, $canvas, $clock)->forQueue();
$navRun = $navService->dispatch('run_start', $settings(['scope' => 'course', 'scope_value' => '1002,1001', 'create_module' => '0', 'create_nav' => '1', 'update_nav' => '1', 'nav_default' => 'disabled']));
$batches = [];
$rows = $runAll($navService, $navRun['body']['run_id'], $navRun['body']['run_key'], $batches);
$navRows = array_column(array_filter($rows, 'is_array'), null, 'course_id');
$navCounts = end($batches)['counts'] ?? [];
$check(($navRows[1002]['nav'] ?? '') === 'updated' && ($navRows[1001]['nav'] ?? '') === 'added' && ($navCounts['nav_updated'] ?? 0) === 1 && ($navCounts['nav_added'] ?? 0) === 1,
    'A run did not update one nav link and add another: ' . json_encode([$navRows[1002]['nav'] ?? null, $navRows[1001]['nav'] ?? null, $navCounts]));
$check(($canvas->state['courses'][1001]['tools'][0]['course_navigation']['default'] ?? '') === 'disabled', 'A run did not create the nav link hidden.');
$navService->dispatch('run_finish', ['run_id' => $navRun['body']['run_id'], 'run_key' => $navRun['body']['run_key']]);

// The queue key is generated once and reused.
$keyDir = $lockRoot . '/keys';
$made = DeployQueue::key('', $keyDir);
$check(preg_match('/^[0-9a-f]{64}$/', $made) === 1 && DeployQueue::key('', $keyDir) === $made && DeployQueue::key(str_repeat('ab', 32), $keyDir) === str_repeat('ab', 32),
    'The queue key was not generated once, or a configured key was not used.');

foreach (glob($queueDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($queueDir);
array_map('unlink', glob($keyDir . '/*') ?: []);
@rmdir($keyDir);

array_map('unlink', glob($lockRoot . '/*') ?: []);
@rmdir($lockRoot);

if ($failures !== []) {
    fwrite(STDERR, "Canvas deploy tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Canvas deploy tests passed.\n";
