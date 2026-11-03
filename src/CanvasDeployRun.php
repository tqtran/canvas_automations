<?php
declare(strict_types=1);

namespace CanvasAutomations;

require_once __DIR__ . '/CanvasClient.php';
require_once __DIR__ . '/CanvasLtiDeployer.php';
require_once __DIR__ . '/Encryption.php';

final class CanvasDeployException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 400,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

/**
 * Runs a Canvas deployment as a series of short, browser-driven batches.
 *
 * Token protection (split key): the Canvas token is posted once. The server
 * encrypts it with a random per-run key, keeps only the ciphertext in the
 * session, and hands the key to the browser, which returns it with each batch.
 * Neither the session file nor the browser alone can recover the token.
 *
 * Timing: a batch returns once 20 seconds have elapsed after finishing a course.
 * A 45-second hard deadline is enforced inside every Canvas call, so no request
 * can approach the IIS FastCGI limits whatever the course mix.
 */
final class CanvasDeployRun
{
    public const TIME_TARGET = 20.0;
    public const HARD_DEADLINE = 45.0;
    public const IDLE_EXPIRY = 1800;
    public const MAX_DEADLINE_HITS = 3;
    public const MAX_STALLED_BATCHES = 3;
    public const MAX_COURSE_IDS = 500;
    public const MAX_COURSE_PAGES = 30;
    /** Canvas enrollment type => label, for the roles that can add a course tool. */
    private const COURSE_ROLES = ['teacher' => 'Teacher', 'ta' => 'TA', 'designer' => 'Designer'];
    public const MAX_DELAY_MS = 10000;
    public const SCOPES = ['course', 'term', 'active', 'all'];
    public const DEFAULTS = [
        'base_url' => '',
        'link_name' => 'SLO Cloud Reporting',
        'create_module' => false,
        'module_name' => 'SLO Cloud',
        'course_delay_ms' => 250,
        'scope' => 'active',
        'scope_value' => '',
    ];
    /** Setting name => key under the canvas: config section. */
    public const CONFIG_KEYS = [
        'base_url' => 'base_url',
        'link_name' => 'link_name',
        'create_module' => 'create_module',
        'module_name' => 'module_name',
        'course_delay_ms' => 'course_delay_ms',
        'scope' => 'default_scope',
        'scope_value' => 'default_scope_value',
    ];
    private const COUNT_KEYS = [
        'processed', 'tool_created', 'tool_would_create', 'tool_present', 'module_created', 'module_added',
        'module_would_add', 'module_present', 'warnings', 'failed', 'skipped', 'out_of_scope',
        'tool_removed', 'tool_would_remove', 'tool_absent', 'module_removed', 'module_would_remove',
        'tool_updated', 'tool_would_update', 'nav_added', 'nav_would_add', 'nav_present', 'nav_not_added',
    ];

    private array $store;
    private \Closure $clock;
    private \Closure $sleeper;
    private ?\Closure $sisInspector;
    private ?\Closure $audit;
    private ?\Closure $log;
    private ?\Closure $clientFactory;

    /**
     * @param array $store         session-backed storage for this administrator's run
     * @param array $lti           launch_url, consumer_key, consumer_secret (read from config per request)
     * @param string $lockDirectory where the install-wide single-run lock lives
     */
    public function __construct(
        array &$store,
        private array $lti,
        private string $lockDirectory,
        private int $userId,
        ?callable $sisInspector = null,
        ?callable $audit = null,
        ?callable $log = null,
        ?callable $clientFactory = null,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->store = &$store;
        $this->sisInspector = $sisInspector !== null ? \Closure::fromCallable($sisInspector) : null;
        $this->audit = $audit !== null ? \Closure::fromCallable($audit) : null;
        $this->log = $log !== null ? \Closure::fromCallable($log) : null;
        $this->clientFactory = $clientFactory !== null ? \Closure::fromCallable($clientFactory) : null;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
        $this->sleeper = $sleeper !== null
            ? \Closure::fromCallable($sleeper)
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int)round($seconds * 1_000_000));
                }
            };
    }

    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public static function prerequisites(array $lti): array
    {
        $launchUrl = trim((string)($lti['launch_url'] ?? ''));
        $launchOk = filter_var($launchUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($launchUrl, PHP_URL_SCHEME)) === 'https';
        $transport = CanvasClient::availableTransport();
        return [
            [
                'key' => 'launch_url',
                'label' => 'LTI launch URL',
                'ok' => $launchOk,
                'detail' => $launchOk ? $launchUrl : 'Set an absolute https launch URL under LTI settings.',
            ],
            [
                'key' => 'consumer_key',
                'label' => 'LTI consumer key',
                'ok' => trim((string)($lti['consumer_key'] ?? '')) !== '',
                'detail' => trim((string)($lti['consumer_key'] ?? '')) !== '' ? 'Configured' : 'Set the consumer key under LTI settings.',
            ],
            [
                'key' => 'consumer_secret',
                'label' => 'LTI shared secret',
                'ok' => (string)($lti['consumer_secret'] ?? '') !== '',
                'detail' => (string)($lti['consumer_secret'] ?? '') !== '' ? 'Configured' : 'Set the shared secret under LTI settings.',
            ],
            [
                'key' => 'transport',
                'label' => 'Outbound HTTPS',
                'ok' => $transport !== null,
                'detail' => match ($transport) {
                    'curl' => 'PHP curl extension',
                    'streams' => 'PHP streams (allow_url_fopen)',
                    default => 'Enable the PHP curl extension or allow_url_fopen.',
                },
            ],
        ];
    }

    public static function prerequisitesMet(array $lti): bool
    {
        foreach (self::prerequisites($lti) as $check) {
            if (!$check['ok']) {
                return false;
            }
        }
        return true;
    }

    /** Saved defaults from a config reader: fn(string $key, mixed $default): mixed. */
    public static function defaultsFromConfig(callable $get): array
    {
        $defaults = [];
        foreach (self::DEFAULTS as $key => $fallback) {
            $value = $get('canvas.' . self::CONFIG_KEYS[$key], $fallback);
            $defaults[$key] = match (true) {
                is_bool($fallback) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_int($fallback) => is_numeric($value) ? (int)$value : $fallback,
                default => $value === null ? $fallback : (string)$value,
            };
        }
        if ($defaults['link_name'] === '') {
            $defaults['link_name'] = self::DEFAULTS['link_name'];
        }
        if ($defaults['module_name'] === '') {
            $defaults['module_name'] = self::DEFAULTS['module_name'];
        }
        if (!in_array($defaults['scope'], self::SCOPES, true)) {
            $defaults['scope'] = self::DEFAULTS['scope'];
        }
        return $defaults;
    }

    /** @return array{settings: array, errors: array<string, string>} */
    public static function validateSettings(array $input): array
    {
        $errors = [];
        $settings = [];

        try {
            $settings['base_url'] = CanvasClient::normalizeBaseUrl((string)($input['base_url'] ?? ''));
        } catch (CanvasApiException $exception) {
            $errors['base_url'] = $exception->getMessage();
            $settings['base_url'] = '';
        }

        $settings['link_name'] = trim((string)($input['link_name'] ?? ''));
        if ($settings['link_name'] === '' || mb_strlen($settings['link_name']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $settings['link_name'])) {
            $errors['link_name'] = 'Enter a link name of 1 to 100 characters.';
        }

        $settings['create_module'] = filter_var($input['create_module'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings['module_name'] = trim((string)($input['module_name'] ?? ''));
        if ($settings['module_name'] === '') {
            $settings['module_name'] = self::DEFAULTS['module_name'];
        }
        if (mb_strlen($settings['module_name']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $settings['module_name'])) {
            $errors['module_name'] = 'Enter a module name of 1 to 100 characters.';
        }

        $delay = trim((string)($input['course_delay_ms'] ?? ''));
        if ($delay === '' || !ctype_digit($delay) || (int)$delay > self::MAX_DELAY_MS) {
            $errors['course_delay_ms'] = 'Enter a delay from 0 to 10,000 milliseconds.';
            $settings['course_delay_ms'] = self::DEFAULTS['course_delay_ms'];
        } else {
            $settings['course_delay_ms'] = (int)$delay;
        }

        $settings['scope'] = (string)($input['scope'] ?? '');
        $settings['scope_value'] = '';
        if (!in_array($settings['scope'], self::SCOPES, true)) {
            $errors['scope'] = 'Choose which courses to include.';
        } elseif ($settings['scope'] === 'course') {
            $ids = preg_split('/[\s,]+/', trim((string)($input['scope_value'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $valid = array_values(array_unique(array_filter($ids, static fn(string $id): bool => ctype_digit($id) && (int)$id > 0)));
            if ($ids === [] || count($valid) !== count(array_unique($ids))) {
                $errors['scope_value'] = 'Enter one or more Canvas course IDs (numbers), separated by commas.';
            } elseif (count($valid) > self::MAX_COURSE_IDS) {
                $errors['scope_value'] = 'Enter at most ' . self::MAX_COURSE_IDS . ' course IDs; use a term for larger groups.';
            }
            $settings['scope_value'] = implode(',', array_map('intval', $valid));
        } elseif ($settings['scope'] === 'term') {
            $term = trim((string)($input['scope_value'] ?? ''));
            if (!ctype_digit($term) || (int)$term <= 0) {
                $errors['scope_value'] = 'Choose a term, or enter its Canvas term ID.';
            }
            $settings['scope_value'] = $term;
        }

        $settings['dry_run'] = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings['operation'] = ($input['operation'] ?? 'deploy') === 'remove' ? 'remove' : 'deploy';
        $settings['update_existing'] = $settings['operation'] === 'deploy'
            && filter_var($input['update_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings['fix_account_nav'] = $settings['operation'] === 'deploy'
            && filter_var($input['fix_account_nav'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return ['settings' => $settings, 'errors' => $errors];
    }

    /** The subset of settings stored as defaults, keyed by setting name. */
    public static function defaultsToSave(array $settings): array
    {
        return array_intersect_key($settings, self::DEFAULTS);
    }

    /** Settings keyed by their canvas.* config path, ready for Config::set(). */
    public static function configValues(array $settings): array
    {
        $values = [];
        foreach (self::defaultsToSave($settings) as $key => $value) {
            $values['canvas.' . self::CONFIG_KEYS[$key]] = $value;
        }
        return $values;
    }

    /**
     * Route one API action. Saving defaults is supplied by the caller because
     * it depends on the application bootstrap.
     *
     * @return array{status: int, body: array}
     */
    public function dispatch(string $action, array $input, ?callable $saveDefaults = null): array
    {
        try {
            switch ($action) {
                case 'test_connection':
                    return self::ok($this->testConnection((string)($input['base_url'] ?? ''), (string)($input['canvas_token'] ?? '')));
                case 'terms':
                    return self::ok($this->terms((string)($input['base_url'] ?? ''), (string)($input['canvas_token'] ?? '')));
                case 'courses':
                    return self::ok($this->courses((string)($input['base_url'] ?? ''), (string)($input['canvas_token'] ?? '')));
                case 'course_flags':
                    return self::ok($this->courseFlags(
                        (string)($input['base_url'] ?? ''),
                        (string)($input['canvas_token'] ?? ''),
                        (string)($input['lti_launch_url'] ?? ''),
                        (string)($input['course_ids'] ?? '')
                    ));
                case 'save_defaults':
                    $settings = self::requireValid($input);
                    if ($saveDefaults === null) {
                        throw new CanvasDeployException('Saving defaults is not available here.', 'save_unavailable', 500);
                    }
                    $saveDefaults(self::defaultsToSave($settings));
                    return self::ok(['saved' => true, 'defaults' => self::defaultsToSave($settings)]);
                case 'run_start':
                    return self::ok($this->start(self::requireValid($input), (string)($input['canvas_token'] ?? ''), [
                        'launch_url' => (string)($input['lti_launch_url'] ?? ''),
                        'consumer_key' => (string)($input['lti_consumer_key'] ?? ''),
                        'consumer_secret' => (string)($input['lti_consumer_secret'] ?? ''),
                    ]));
                case 'run_batch':
                    return self::ok($this->batch((string)($input['run_id'] ?? ''), (string)($input['run_key'] ?? '')));
                case 'run_finish':
                    return self::ok($this->finish((string)($input['run_id'] ?? ''), (string)($input['run_key'] ?? ''), false));
                case 'run_cancel':
                    return self::ok($this->finish((string)($input['run_id'] ?? ''), (string)($input['run_key'] ?? ''), true));
                default:
                    throw new CanvasDeployException('Unknown action.', 'unknown_action', 400);
            }
        } catch (CanvasDeployException $exception) {
            $body = ['ok' => false, 'error' => $exception->errorCode(), 'message' => $exception->getMessage()];
            if ($exception->fieldErrors() !== []) {
                $body['fields'] = $exception->fieldErrors();
            }
            return ['status' => $exception->httpStatus(), 'body' => $body];
        } catch (CanvasApiException $exception) {
            return ['status' => 502, 'body' => [
                'ok' => false,
                'error' => 'canvas_' . $exception->kind(),
                'message' => $exception->getMessage(),
                'canvas_status' => $exception->status(),
            ]];
        }
    }

    public function testConnection(string $baseUrl, string $token): array
    {
        $client = $this->client($baseUrl, $token, $this->now() + self::HARD_DEADLINE);
        $self = $client->get('/api/v1/users/self');
        $accounts = $this->adminAccounts($client);
        return [
            'origin' => $client->origin(),
            'user' => is_array($self) ? (string)($self['name'] ?? '') : '',
            'is_admin' => $accounts !== [],
            'accounts' => array_map(
                static fn(array $account): array => ['id' => (int)$account['id'], 'name' => (string)($account['name'] ?? '')],
                $accounts
            ),
            'transport' => CanvasClient::availableTransport(),
        ];
    }

    /** Most courses checked per course_flags call, to stay well inside the request time. */
    public const FLAGS_PER_CALL = 10;

    /**
     * App / nav / module flags for up to FLAGS_PER_CALL courses, read-only. The launch URL
     * falls back to the one saved on the server.
     */
    public function courseFlags(string $baseUrl, string $token, string $launchUrl, string $courseIds): array
    {
        $launchUrl = trim($launchUrl) !== '' ? trim($launchUrl) : trim((string)($this->lti['launch_url'] ?? ''));
        if ($launchUrl === '') {
            return ['flags' => [], 'launch_url_known' => false];
        }
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $courseIds, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id): bool => $id > 0
        ))), 0, self::FLAGS_PER_CALL);
        $client = $this->client($baseUrl, $token, $this->now() + self::HARD_DEADLINE);
        $flags = [];
        foreach ($ids as $id) {
            try {
                $flags[(string)$id] = CanvasLtiDeployer::inspectCourse($client, $id, $launchUrl);
            } catch (CanvasDeadlineExceeded) {
                break;
            } catch (CanvasApiException $exception) {
                if ($exception->status() === 401) {
                    throw $exception;
                }
                $flags[(string)$id] = ['app' => 'error', 'nav' => 'error', 'module' => 'error', 'tool' => $exception->getMessage()];
            }
        }
        return ['flags' => $flags, 'launch_url_known' => true];
    }

    /**
     * The courses this token can deploy to: those where its owner is a teacher,
     * TA or designer. Any token works; an account admin also gets the account-wide scopes.
     */
    public function courses(string $baseUrl, string $token): array
    {
        $client = $this->client($baseUrl, $token, $this->now() + self::HARD_DEADLINE);
        $rows = $client->getAll('/api/v1/courses', [
            'include' => ['term'],
            'state' => ['unpublished', 'available', 'completed'],
            'per_page' => CanvasClient::PER_PAGE,
        ], null, self::MAX_COURSE_PAGES);
        $now = $this->nowInt();
        $courses = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $roles = [];
            foreach (is_array($row['enrollments'] ?? null) ? $row['enrollments'] : [] as $enrollment) {
                $type = is_array($enrollment) ? (string)($enrollment['type'] ?? '') : '';
                if (isset(self::COURSE_ROLES[$type])) {
                    $roles[self::COURSE_ROLES[$type]] = true;
                }
            }
            if ($roles === [] && !empty($row['enrollments'])) {
                continue;
            }
            $course = CanvasLtiDeployer::compactCourse($row);
            $courses[] = [
                'id' => $course['id'],
                'name' => $course['name'],
                'course_code' => $course['course_code'],
                'sis_course_id' => $course['sis_course_id'],
                'term' => $course['term']['name'],
                'concluded' => CanvasLtiDeployer::isConcluded($course, $now),
                'roles' => array_keys($roles),
            ];
        }
        usort($courses, static fn(array $a, array $b): int => [$a['concluded'], $a['term'] === '', strcasecmp($a['name'], $b['name'])]
            <=> [$b['concluded'], $b['term'] === '', 0]);
        return ['courses' => $courses, 'max_selectable' => self::MAX_COURSE_IDS];
    }

    public function terms(string $baseUrl, string $token): array
    {
        $client = $this->client($baseUrl, $token, $this->now() + self::HARD_DEADLINE);
        $accounts = $this->adminAccounts($client);
        if ($accounts === []) {
            throw self::notAdmin();
        }
        $rootIds = array_values(array_unique(array_map(
            static fn(array $account): int => (int)($account['root_account_id'] ?? 0) ?: (int)$account['id'],
            $accounts
        )));
        $terms = [];
        foreach ($rootIds as $rootId) {
            try {
                $rows = $client->getAll("/api/v1/accounts/{$rootId}/terms", [], 'enrollment_terms');
            } catch (CanvasDeadlineExceeded $exception) {
                throw $exception;
            } catch (CanvasApiException $exception) {
                if (in_array($exception->status(), [401, 403, 404], true)) {
                    return ['terms' => [], 'fallback' => true];
                }
                throw $exception;
            }
            foreach ($rows as $term) {
                $terms[(int)$term['id']] = [
                    'id' => (int)$term['id'],
                    'name' => (string)($term['name'] ?? ('Term ' . $term['id'])),
                    'start_at' => $term['start_at'] ?? null,
                    'end_at' => $term['end_at'] ?? null,
                ];
            }
        }
        $terms = array_values($terms);
        usort($terms, static fn(array $a, array $b): int => strcmp((string)$b['start_at'], (string)$a['start_at']) ?: $b['id'] <=> $a['id']);
        return ['terms' => $terms, 'fallback' => false];
    }

    /**
     * @param array $lti launch_url, consumer_key and consumer_secret typed by the user. Anything
     *                   left blank falls back to the LTI settings this service was built with.
     */
    public function start(array $settings, string $token, array $lti = []): array
    {
        if (trim($token) === '') {
            throw new CanvasDeployException('Enter a Canvas API token.', 'token_required', 422, ['canvas_token' => 'Enter a Canvas API token.']);
        }
        if (CanvasClient::availableTransport() === null) {
            throw new CanvasDeployException('Enable the PHP curl extension or allow_url_fopen before deploying.', 'prerequisites', 409);
        }
        $effective = [];
        foreach (['launch_url', 'consumer_key', 'consumer_secret'] as $key) {
            $typed = $key === 'consumer_secret' ? (string)($lti[$key] ?? '') : trim((string)($lti[$key] ?? ''));
            $effective[$key] = $typed !== '' ? $typed : ($key === 'consumer_secret' ? (string)($this->lti[$key] ?? '') : trim((string)($this->lti[$key] ?? '')));
        }
        $ltiErrors = [];
        $launchOk = filter_var($effective['launch_url'], FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($effective['launch_url'], PHP_URL_SCHEME)) === 'https';
        if (!$launchOk) {
            $ltiErrors['lti_launch_url'] = 'Enter the SLO Cloud LTI launch URL (an https address).';
        }
        // The key and secret are only needed to create a tool in a course that has none (a tool
        // pushed from the account needs nothing), or to rewrite existing tools. Without them,
        // courses that would need a new tool are skipped and say so.
        if ($settings['operation'] === 'deploy' && !empty($settings['update_existing'])) {
            if ($effective['consumer_key'] === '') {
                $ltiErrors['lti_consumer_key'] = 'Enter the LTI consumer key to update existing tools.';
            }
            if ($effective['consumer_secret'] === '') {
                $ltiErrors['lti_consumer_secret'] = 'Enter the LTI shared secret to update existing tools.';
            }
        }
        if ($ltiErrors !== []) {
            throw new CanvasDeployException('Fill in the SLO Cloud LTI settings.', 'lti_required', 422, $ltiErrors);
        }
        // Starting again in the same session replaces this administrator's previous run.
        if (isset($this->store['run']['id'])) {
            $this->closeRun($this->store['run'], 'canvas_deploy_replaced');
        }

        $client = $this->client($settings['base_url'], $token, $this->now() + self::HARD_DEADLINE);
        $self = $client->get('/api/v1/users/self');
        $accounts = $this->adminAccounts($client);
        // Only account-wide scopes need an account admin. Any token can deploy to
        // courses it can edit, and Canvas refuses the ones it cannot.
        if ($accounts === [] && $settings['scope'] !== 'course') {
            throw self::notAdmin();
        }

        $notes = [];
        // Opt-in: give the account's own SLO Cloud tool a Course Navigation link, so every
        // course under it shows SLO Cloud. Needs no key or secret.
        $accountNavFixed = false;
        if (!empty($settings['fix_account_nav']) && $settings['operation'] === 'deploy') {
            if ($accounts === []) {
                $notes[] = 'Adding the nav link to the account tool needs an account admin token; skipped.';
            }
            foreach ($accounts as $account) {
                $own = $client->getAll('/api/v1/accounts/' . (int)$account['id'] . '/external_tools');
                $match = CanvasLtiDeployer::findMatchingTool($own, $effective['launch_url']);
                if ($match === null || CanvasLtiDeployer::doubtfulMatch($match) !== null || !CanvasLtiDeployer::lacksNav($match)) {
                    continue;
                }
                $label = 'Account "' . (string)($account['name'] ?? $account['id']) . '": SLO Cloud ' . CanvasLtiDeployer::describeTool($match);
                if (empty($settings['dry_run'])) {
                    $client->put('/api/v1/accounts/' . (int)$account['id'] . '/external_tools/' . (int)$match['id'], [
                        'course_navigation' => CanvasLtiDeployer::navPlacement($settings['link_name']),
                    ]);
                    $notes[] = $label . ': Course Navigation link added.';
                } else {
                    $notes[] = $label . ': would get a Course Navigation link.';
                }
                $accountNavFixed = true;
            }
            if ($accounts !== [] && !$accountNavFixed) {
                $notes[] = 'No account-level SLO Cloud tool without a nav link was found in your admin accounts, so no account tool was changed.';
            }
        }

        $accountTool = $accounts !== [];
        foreach ($accounts as $account) {
            $tools = $client->getAll('/api/v1/accounts/' . (int)$account['id'] . '/external_tools', ['include_parents' => 'true']);
            $match = CanvasLtiDeployer::findMatchingTool($tools, $effective['launch_url']);
            if ($match === null) {
                $accountTool = false;
                continue;
            }
            if (($doubt = CanvasLtiDeployer::doubtfulMatch($match)) !== null || CanvasLtiDeployer::lacksNav($match)) {
                // Each course is then checked and reported on its own.
                $accountTool = false;
                $notes[] = 'Account "' . (string)($account['name'] ?? $account['id']) . '": '
                    . ($doubt ?? 'SLO Cloud ' . CanvasLtiDeployer::describeTool($match) . ' has no Course Navigation link; each course is checked on its own.');
                continue;
            }
            foreach (CanvasLtiDeployer::toolWarnings($match, $effective['consumer_key']) as $warning) {
                $notes[] = 'Account "' . (string)($account['name'] ?? $account['id']) . '": ' . $warning;
            }
        }
        if ($accountTool) {
            $notes[] = 'SLO Cloud is already installed at the account level, so per-course tool checks are skipped.';
        }

        $runId = bin2hex(random_bytes(16));
        $this->acquireLock($runId);

        try {
            $runKey = Encryption::generateKey();
            $accountIds = array_map(static fn(array $account): int => (int)$account['id'], $accounts);
            $isCourseScope = $settings['scope'] === 'course';
            $now = $this->nowInt();
            $this->store['run'] = [
                'id' => $runId,
                'user_id' => $this->userId,
                'token' => (new Encryption($runKey))->encrypt(trim($token)),
                'lti' => ['launch_url' => $effective['launch_url'], 'consumer_key' => $effective['consumer_key']],
                'lti_secret' => $effective['consumer_secret'] === '' ? '' : (new Encryption($runKey))->encrypt($effective['consumer_secret']),
                'settings' => $settings,
                'account_tool' => $accountTool,
                'account_nav_fixed' => $accountNavFixed,
                'accounts' => $accountIds,
                'account_index' => 0,
                'phase' => $isCourseScope ? 'ids' : 'main',
                'cursor' => $isCourseScope ? null : $client->url(
                    '/api/v1/accounts/' . $accountIds[0] . '/courses',
                    $this->courseListQuery($settings, false)
                ),
                'ids' => $isCourseScope ? array_map('intval', explode(',', $settings['scope_value'])) : [],
                'queue' => [],
                'attempts' => [],
                'stalls' => 0,
                'counts' => array_fill_keys(self::COUNT_KEYS, 0),
                'started_at' => $now,
                'touched_at' => $now,
            ];
        } catch (\Throwable $exception) {
            $this->releaseLock($runId);
            unset($this->store['run']);
            throw $exception;
        }

        $this->auditEvent('canvas_deploy_start', [
            'run_id' => $runId,
            'canvas' => $settings['base_url'],
            'canvas_user' => is_array($self) ? trim(($self['name'] ?? '') . ' (' . ($self['id'] ?? '?') . ')') : '',
            'scope' => $settings['scope'],
            'scope_value' => $settings['scope_value'],
            'dry_run' => $settings['dry_run'],
            'operation' => $settings['operation'],
            'update_existing' => $settings['update_existing'],
            'create_module' => $settings['create_module'],
            'account_tool' => $accountTool,
        ]);

        return ['run_id' => $runId, 'run_key' => $runKey, 'notes' => $notes, 'account_tool' => $accountTool];
    }

    public function batch(string $runId, string $runKey): array
    {
        $started = $this->now();
        $run = &$this->loadRun($runId);
        $token = $this->decryptToken($run, $runKey);
        $this->touchLock($runId);

        $client = $this->client($run['settings']['base_url'], $token, $started + self::HARD_DEADLINE);
        unset($token);
        $secret = $run['lti_secret'] === '' ? '' : (new Encryption($runKey))->decrypt((string)$run['lti_secret']);
        $deployer = new CanvasLtiDeployer($client, $run['lti'] + ['consumer_secret' => $secret] + [
            'link_name' => $run['settings']['link_name'],
            'create_module' => $run['settings']['create_module'],
            'module_name' => $run['settings']['module_name'],
            'dry_run' => $run['settings']['dry_run'],
            'update_existing' => $run['settings']['update_existing'] ?? false,
            'account_tool' => $run['account_tool'],
            'account_nav_fixed' => $run['account_nav_fixed'] ?? false,
        ], $this->sisInspector);

        $delay = $run['settings']['course_delay_ms'] / 1000;
        $results = [];
        $processed = 0;
        $stop = 'end_of_stream';

        while (true) {
            try {
                $item = $this->nextItem($run, $client);
            } catch (CanvasDeadlineExceeded) {
                $stop = 'deadline';
                break;
            } catch (CanvasApiException $exception) {
                $run['touched_at'] = $this->nowInt();
                throw new CanvasDeployException(
                    'Canvas stopped answering the course list: ' . $exception->getMessage() . ' Retry to continue from the same place.',
                    'canvas_' . $exception->kind(),
                    502
                );
            }
            if ($item === null) {
                $stop = 'end_of_stream';
                break;
            }

            if ($processed > 0 && $delay > 0) {
                if ($this->now() + $delay >= $started + self::HARD_DEADLINE - 1) {
                    array_unshift($run['queue'], $item);
                    $stop = 'deadline';
                    break;
                }
                ($this->sleeper)($delay);
            }

            if ($item['kind'] === 'result') {
                $row = $item['result'];
            } else {
                $courseId = (int)$item['course']['id'];
                try {
                    $row = ($run['settings']['operation'] ?? 'deploy') === 'remove'
                        ? $deployer->removeCourse($item['course'])
                        : $deployer->deployCourse($item['course']);
                    unset($run['attempts'][$courseId]);
                } catch (CanvasDeadlineExceeded) {
                    $hits = ($run['attempts'][$courseId] ?? 0) + 1;
                    $run['attempts'][$courseId] = $hits;
                    $stop = 'deadline';
                    if ($hits < self::MAX_DEADLINE_HITS) {
                        array_unshift($run['queue'], $item);
                        break;
                    }
                    unset($run['attempts'][$courseId]);
                    $row = CanvasLtiDeployer::failedRow(
                        $item['course'],
                        'Timed out: Canvas did not finish this course within the batch deadline three times.'
                    );
                    $this->record($run, $row, $results, $processed);
                    break;
                }
            }

            $this->record($run, $row, $results, $processed);
            if ($this->now() - $started >= self::TIME_TARGET) {
                $stop = 'time_target';
                break;
            }
        }

        $run['stalls'] = $processed === 0 && $stop === 'deadline' ? $run['stalls'] + 1 : 0;
        $run['touched_at'] = $this->nowInt();
        if ($run['stalls'] >= self::MAX_STALLED_BATCHES) {
            $run['stalls'] = 0;
            throw new CanvasDeployException(
                'Canvas is responding too slowly to make progress. Wait a few minutes, then retry.',
                'canvas_stalled',
                504
            );
        }

        return [
            'processed' => $processed,
            'elapsed_ms' => (int)round(($this->now() - $started) * 1000),
            'stop_reason' => $stop,
            'done' => $run['phase'] === 'done' && $run['queue'] === [],
            'results' => $results,
            'counts' => $run['counts'],
            'delay_ms' => $run['settings']['course_delay_ms'],
        ];
    }

    public function finish(string $runId, string $runKey, bool $cancelled): array
    {
        $run = &$this->loadRun($runId);
        $this->decryptToken($run, $runKey);
        $counts = $run['counts'];
        $this->closeRun($run, $cancelled ? 'canvas_deploy_cancel' : 'canvas_deploy_finish');
        return ['counts' => $counts, 'cancelled' => $cancelled];
    }

    private function record(array &$run, array $row, array &$results, int &$processed): void
    {
        $results[] = $row;
        $processed++;
        $counts = &$run['counts'];
        $counts['processed']++;
        match ($row['tool']) {
            'created', 'created_for_nav' => $counts['tool_created']++,
            'would_create', 'would_create_for_nav' => $counts['tool_would_create']++,
            'present', 'present_account' => $counts['tool_present']++,
            'removed' => $counts['tool_removed']++,
            'updated' => $counts['tool_updated']++,
            'would_update' => $counts['tool_would_update']++,
            'would_remove' => $counts['tool_would_remove']++,
            'absent' => $counts['tool_absent']++,
            default => null,
        };
        // Runs started before the nav counts existed have no such keys yet.
        $counts += array_fill_keys(['nav_added', 'nav_would_add', 'nav_present', 'nav_not_added'], 0);
        match ($row['nav'] ?? '') {
            'added' => $counts['nav_added']++,
            'would_add', 'would_try' => $counts['nav_would_add']++,
            'present' => $counts['nav_present']++,
            'not_added' => $counts['nav_not_added']++,
            default => null,
        };
        match ($row['module']) {
            'created' => $counts['module_created']++,
            'added' => $counts['module_added']++,
            'would_create', 'would_add' => $counts['module_would_add']++,
            'present' => $counts['module_present']++,
            'removed' => $counts['module_removed']++,
            'would_remove' => $counts['module_would_remove']++,
            default => null,
        };
        match ($row['status']) {
            'warning' => $counts['warnings']++,
            'failed' => $counts['failed']++,
            'skipped' => $counts['skipped']++,
            default => null,
        };
        if ($this->log !== null) {
            ($this->log)('Canvas deploy course result', [
                'run_id' => $run['id'],
                'course_id' => $row['course_id'],
                'tool' => $row['tool'],
                'module' => $row['module'],
                'status' => $row['status'],
                'notes' => implode(' | ', $row['notes']),
                'dry_run' => $run['settings']['dry_run'],
            ]);
        }
    }

    /**
     * The next course to process, pulling a page from Canvas when the queue is
     * empty. Returns null once every phase is exhausted.
     */
    private function nextItem(array &$run, CanvasClient $client): ?array
    {
        while (true) {
            if ($run['queue'] !== []) {
                return array_shift($run['queue']);
            }

            if ($run['phase'] === 'ids') {
                if ($run['ids'] === []) {
                    $run['phase'] = 'done';
                    return null;
                }
                $courseId = (int)$run['ids'][0];
                try {
                    try {
                        $course = $client->get("/api/v1/courses/{$courseId}", ['include' => ['term']]);
                    } catch (CanvasDeadlineExceeded $exception) {
                        throw $exception;
                    } catch (CanvasApiException $exception) {
                        array_shift($run['ids']);
                        return ['kind' => 'result', 'result' => CanvasLtiDeployer::failedRow(
                            ['id' => $courseId],
                            'Course lookup failed: ' . $exception->getMessage()
                        )];
                    }
                    try {
                        $subscriptions = $client->get("/api/v1/courses/{$courseId}/blueprint_subscriptions");
                    } catch (CanvasDeadlineExceeded $exception) {
                        throw $exception;
                    } catch (CanvasApiException) {
                        $subscriptions = [];
                    }
                } catch (CanvasDeadlineExceeded $exception) {
                    // A lookup that keeps timing out counts toward the same three strikes as a course.
                    $hits = ($run['attempts'][$courseId] ?? 0) + 1;
                    $run['attempts'][$courseId] = $hits;
                    if ($hits < self::MAX_DEADLINE_HITS) {
                        throw $exception;
                    }
                    unset($run['attempts'][$courseId]);
                    array_shift($run['ids']);
                    return ['kind' => 'result', 'result' => CanvasLtiDeployer::failedRow(
                        ['id' => $courseId],
                        'Timed out: Canvas did not return this course within the batch deadline three times.'
                    )];
                }
                $compact = CanvasLtiDeployer::compactCourse(is_array($course) ? $course : ['id' => $courseId]);
                array_shift($run['ids']);
                if (is_array($subscriptions) && $subscriptions !== []) {
                    return ['kind' => 'result', 'result' => CanvasLtiDeployer::blueprintRow($compact)];
                }
                return ['kind' => 'course', 'course' => $compact];
            }

            if ($run['phase'] === 'main' || $run['phase'] === 'blueprint') {
                if ($run['cursor'] === null) {
                    $blueprint = $run['phase'] === 'blueprint';
                    if ($run['account_index'] + 1 < count($run['accounts'])) {
                        $run['account_index']++;
                    } elseif (!$blueprint) {
                        $run['phase'] = 'blueprint';
                        $run['account_index'] = 0;
                        $blueprint = true;
                    } else {
                        $run['phase'] = 'done';
                        return null;
                    }
                    $run['cursor'] = $client->url(
                        '/api/v1/accounts/' . $run['accounts'][$run['account_index']] . '/courses',
                        $this->courseListQuery($run['settings'], $blueprint)
                    );
                    continue;
                }

                $page = $client->getPage($run['cursor']);
                $run['cursor'] = $page['next'];
                $now = $this->nowInt();
                foreach ($page['items'] as $course) {
                    $compact = CanvasLtiDeployer::compactCourse($course);
                    if ($run['settings']['scope'] === 'active' && CanvasLtiDeployer::isConcluded($compact, $now)) {
                        $run['counts']['out_of_scope']++;
                        continue;
                    }
                    $run['queue'][] = $run['phase'] === 'blueprint'
                        ? ['kind' => 'result', 'result' => CanvasLtiDeployer::blueprintRow($compact)]
                        : ['kind' => 'course', 'course' => $compact];
                }
                continue;
            }

            return null;
        }
    }

    private function courseListQuery(array $settings, bool $blueprintPhase): array
    {
        $query = [
            'include' => ['term'],
            'per_page' => CanvasClient::PER_PAGE,
            'blueprint_associated' => $blueprintPhase ? 'true' : 'false',
        ];
        switch ($settings['scope']) {
            case 'term':
                $query['enrollment_term_id'] = (int)$settings['scope_value'];
                $query['state'] = ['created', 'claimed', 'available', 'completed'];
                break;
            case 'active':
                $query['state'] = ['created', 'claimed', 'available'];
                $query['ends_after'] = gmdate('Y-m-d\TH:i:s\Z', $this->nowInt());
                break;
            default:
                $query['state'] = ['created', 'claimed', 'available', 'completed'];
        }
        return $query;
    }

    private function &loadRun(string $runId): array
    {
        $run = $this->store['run'] ?? null;
        if (!is_array($run) || !preg_match('/^[0-9a-f]{32}$/', $runId) || !hash_equals((string)$run['id'], $runId)) {
            throw new CanvasDeployException(
                'This run is no longer active (it finished, was cancelled, expired after 30 minutes idle, or you signed out). '
                . 'Start a new run; courses already done are skipped.',
                'run_missing',
                410
            );
        }
        if ((int)$run['user_id'] !== $this->userId) {
            throw new CanvasDeployException('This run belongs to another user.', 'run_forbidden', 403);
        }
        if ($this->nowInt() - (int)$run['touched_at'] > self::IDLE_EXPIRY) {
            $this->closeRun($this->store['run'], 'canvas_deploy_expired');
            throw new CanvasDeployException(
                'This run expired after 30 minutes without activity. Start a new run; courses already done are skipped.',
                'run_missing',
                410
            );
        }
        return $this->store['run'];
    }

    private function decryptToken(array $run, string $runKey): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $runKey)) {
            throw new CanvasDeployException('The run key was rejected.', 'run_key_rejected', 403);
        }
        try {
            return (new Encryption($runKey))->decrypt((string)$run['token']);
        } catch (\Throwable) {
            throw new CanvasDeployException('The run key was rejected.', 'run_key_rejected', 403);
        }
    }

    private function closeRun(array $run, string $event): void
    {
        $this->releaseLock((string)$run['id']);
        $this->auditEvent($event, [
            'run_id' => $run['id'],
            'scope' => $run['settings']['scope'] ?? '',
            'scope_value' => $run['settings']['scope_value'] ?? '',
            'dry_run' => $run['settings']['dry_run'] ?? false,
            'counts' => $run['counts'] ?? [],
        ]);
        unset($this->store['run']);
    }

    private function client(string $baseUrl, string $token, ?float $deadline): CanvasClient
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($baseUrl, $token, $deadline);
        }
        return new CanvasClient($baseUrl, $token, null, $deadline, $this->clock, $this->sleeper);
    }

    /** Top accounts the token administers; empty for a token that is not an account admin. */
    private function adminAccounts(CanvasClient $client): array
    {
        try {
            return self::topAccounts($client->getAll('/api/v1/accounts'));
        } catch (CanvasDeadlineExceeded $exception) {
            throw $exception;
        } catch (CanvasApiException $exception) {
            if (in_array($exception->status(), [401, 403, 404], true)) {
                return [];
            }
            throw $exception;
        }
    }

    /** Accounts the token administers whose parent is not also administered. */
    private static function topAccounts(array $accounts): array
    {
        $ids = [];
        foreach ($accounts as $account) {
            if (is_array($account) && isset($account['id'])) {
                $ids[(int)$account['id']] = true;
            }
        }
        $top = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !isset($account['id'])) {
                continue;
            }
            $parent = $account['parent_account_id'] ?? null;
            if ($parent === null || !isset($ids[(int)$parent])) {
                $top[] = $account;
            }
        }
        return $top;
    }

    private static function notAdmin(): CanvasDeployException
    {
        return new CanvasDeployException(
            'This token is not a Canvas account administrator. Choose specific courses instead; terms and all-course scopes need an account admin token.',
            'not_admin',
            403
        );
    }

    private static function requireValid(array $input): array
    {
        $validated = self::validateSettings($input);
        if ($validated['errors'] !== []) {
            throw new CanvasDeployException('Fix the highlighted settings.', 'invalid_settings', 422, $validated['errors']);
        }
        return $validated['settings'];
    }

    private static function ok(array $data): array
    {
        return ['status' => 200, 'body' => ['ok' => true] + $data];
    }

    private function auditEvent(string $event, array $data): void
    {
        if ($this->audit !== null) {
            ($this->audit)($event, $data);
        }
    }

    private function now(): float
    {
        return (float)($this->clock)();
    }

    private function nowInt(): int
    {
        return (int)floor($this->now());
    }

    private function lockPath(): string
    {
        return rtrim($this->lockDirectory, '/\\') . '/canvas_deploy.lock';
    }

    /**
     * Read-modify-write the install-wide lock under an exclusive file lock.
     * $change receives the current holder (or []) and returns the new holder,
     * or null to clear it.
     */
    private function withLock(callable $change): void
    {
        if (!is_dir($this->lockDirectory) && !@mkdir($this->lockDirectory, 0700, true) && !is_dir($this->lockDirectory)) {
            throw new CanvasDeployException('Cannot create the deployment lock directory.', 'lock_unavailable', 500);
        }
        $handle = @fopen($this->lockPath(), 'c+');
        if ($handle === false) {
            throw new CanvasDeployException('Cannot open the deployment lock file.', 'lock_unavailable', 500);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new CanvasDeployException('Cannot lock the deployment lock file.', 'lock_unavailable', 500);
            }
            $current = json_decode((string)stream_get_contents($handle), true);
            $next = $change(is_array($current) ? $current : []);
            ftruncate($handle, 0);
            rewind($handle);
            if ($next !== null) {
                fwrite($handle, (string)json_encode($next));
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function acquireLock(string $runId): void
    {
        $now = $this->nowInt();
        $this->withLock(function (array $holder) use ($runId, $now): array {
            if (($holder['run_id'] ?? '') !== '' && $holder['run_id'] !== $runId
                && $now - (int)($holder['touched_at'] ?? 0) <= self::IDLE_EXPIRY) {
                throw new CanvasDeployException(
                    'Another Canvas deployment is running on this SLO Cloud server (started '
                    . gmdate('Y-m-d H:i', (int)($holder['started_at'] ?? $now)) . ' UTC). Try again when it finishes.',
                    'run_locked',
                    409
                );
            }
            return ['run_id' => $runId, 'user_id' => $this->userId, 'started_at' => $now, 'touched_at' => $now];
        });
    }

    private function touchLock(string $runId): void
    {
        $now = $this->nowInt();
        $this->withLock(function (array $holder) use ($runId, $now): array {
            if (($holder['run_id'] ?? '') === $runId) {
                return ['touched_at' => $now] + $holder;
            }
            if (($holder['run_id'] ?? '') !== '' && $now - (int)($holder['touched_at'] ?? 0) <= self::IDLE_EXPIRY) {
                throw new CanvasDeployException(
                    'Another Canvas deployment took over after this run sat idle. Start a new run when it finishes.',
                    'run_locked',
                    409
                );
            }
            return ['run_id' => $runId, 'user_id' => $this->userId, 'started_at' => $now, 'touched_at' => $now];
        });
    }

    private function releaseLock(string $runId): void
    {
        $this->withLock(static fn(array $holder): ?array => ($holder['run_id'] ?? '') === $runId ? null : ($holder ?: null));
    }
}
