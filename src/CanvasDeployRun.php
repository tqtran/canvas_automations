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
 * Runs a Canvas deployment as a series of short batches. The web app keeps runs in the
 * shared queue (DeployQueue, forQueue()), which calls batch() from the worker or the page.
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
    /** Most accounts chosen in one run, and pages read when listing an account's sub-accounts. */
    public const MAX_ACCOUNT_IDS = 50;
    public const MAX_ACCOUNT_PAGES = 20;
    /** Canvas enrollment type => label, for the roles that can add a course tool. */
    private const COURSE_ROLES = ['teacher' => 'Teacher', 'ta' => 'TA', 'designer' => 'Designer'];
    public const MAX_DELAY_MS = 10000;
    public const SCOPES = ['course', 'account', 'term', 'active', 'all'];
    public const DEFAULTS = [
        'base_url' => '',
        'link_name' => 'External Tool',
        'create_module' => true,
        'module_name' => 'External Tool',
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
        'tool_copied', 'tool_would_copy', 'nav_updated', 'nav_would_update',
    ];

    private array $store;
    private \Closure $clock;
    private \Closure $sleeper;
    private ?\Closure $sisInspector;
    private ?\Closure $audit;
    private ?\Closure $log;
    private ?\Closure $clientFactory;
    private bool $installLock = true;
    private bool $idleExpires = true;

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

    /**
     * For runs kept in the shared queue (DeployQueue): several runs may exist at once, so
     * there is no install-wide lock, and a run waiting its turn does not expire here (the
     * queue expires runs itself).
     */
    public function forQueue(): self
    {
        $this->installLock = false;
        $this->idleExpires = false;
        return $this;
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
        } elseif ($settings['scope'] === 'account') {
            $ids = preg_split('/[\s,]+/', trim((string)($input['scope_value'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $valid = array_values(array_unique(array_filter($ids, static fn(string $id): bool => ctype_digit($id) && (int)$id > 0)));
            if ($ids === [] || count($valid) !== count(array_unique($ids))) {
                $errors['scope_value'] = 'Choose one or more accounts.';
            } elseif (count($valid) > self::MAX_ACCOUNT_IDS) {
                $errors['scope_value'] = 'Choose at most ' . self::MAX_ACCOUNT_IDS . ' accounts in one run.';
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
        // Installing on accounts sets their nav link directly, so this course-run option is off.
        $settings['fix_account_nav'] = $settings['operation'] === 'deploy' && $settings['scope'] !== 'account'
            && filter_var($input['fix_account_nav'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings['create_nav'] = $settings['operation'] === 'deploy'
            && filter_var($input['create_nav'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // "Update if found" for the nav link, separate from update_existing (the tool's key and
        // secret). It belongs to the nav choice, so it only applies when nav links are wanted.
        $settings['update_nav'] = $settings['create_nav']
            && filter_var($input['update_nav'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settings['nav_visibility'] = CanvasLtiDeployer::navVisibility((string)($input['nav_visibility'] ?? 'admins'));
        $settings['privacy_level'] = CanvasLtiDeployer::privacyLevel((string)($input['privacy_level'] ?? 'public'));
        $settings['module_published'] = $settings['create_module']
            && filter_var($input['module_published'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
                case 'accounts':
                    return self::ok($this->accounts((string)($input['base_url'] ?? ''), (string)($input['canvas_token'] ?? '')));
                case 'account_flags':
                    return self::ok($this->accountFlags(
                        (string)($input['base_url'] ?? ''),
                        (string)($input['canvas_token'] ?? ''),
                        (string)($input['lti_launch_url'] ?? ''),
                        (string)($input['account_ids'] ?? '')
                    ));
                case 'account_courses':
                    return self::ok($this->accountCourseCounts(
                        (string)($input['base_url'] ?? ''),
                        (string)($input['canvas_token'] ?? ''),
                        (string)($input['account_ids'] ?? '')
                    ));
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
                    $received = isset($input['received']) && is_numeric($input['received']) ? max(0, (int)$input['received']) : null;
                    return self::ok($this->batch((string)($input['run_id'] ?? ''), (string)($input['run_key'] ?? ''), $received));
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
            'user_id' => is_array($self) ? (int)($self['id'] ?? 0) : 0,
            'is_admin' => $accounts !== [],
            'accounts' => array_map(
                static fn(array $account): array => ['id' => (int)$account['id'], 'name' => (string)($account['name'] ?? '')],
                $accounts
            ),
            'transport' => CanvasClient::availableTransport(),
        ];
    }

    /** Most courses checked per course_flags call. */
    public const FLAGS_PER_CALL = 5;
    /** No new course is started after this many seconds, so a call ends well before proxy or gateway timeouts. */
    public const FLAGS_SOFT_SECONDS = 12.0;
    /** Hard cap for a single course_flags call. */
    public const FLAGS_HARD_SECONDS = 20.0;

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
        $started = $this->now();
        $client = $this->client($baseUrl, $token, $started + self::FLAGS_HARD_SECONDS);
        $flags = [];
        $pending = [];
        foreach ($ids as $index => $id) {
            // Stop starting new courses after the soft limit; the page asks again for the rest.
            if ($index > 0 && $this->now() - $started > self::FLAGS_SOFT_SECONDS) {
                $pending = array_slice($ids, $index);
                break;
            }
            try {
                $flags[(string)$id] = CanvasLtiDeployer::inspectCourse($client, $id, $launchUrl);
            } catch (CanvasDeadlineExceeded) {
                $pending = array_slice($ids, $index);
                break;
            } catch (CanvasApiException $exception) {
                if ($exception->status() === 401) {
                    throw $exception;
                }
                $flags[(string)$id] = ['app' => 'error', 'nav' => 'error', 'module' => 'error', 'tool' => $exception->getMessage()];
            }
        }
        return ['flags' => $flags, 'pending' => $pending, 'launch_url_known' => true];
    }

    /**
     * Every account the token administers, sub-accounts included, in tree order with a depth
     * and how many sub-accounts sit below each. Canvas, not this list, decides what the token
     * may change: seeing an account does not mean its role can manage apps there.
     */
    public function accounts(string $baseUrl, string $token): array
    {
        $client = $this->client($baseUrl, $token, $this->now() + self::HARD_DEADLINE);
        $admin = $this->adminAccounts($client, false);
        if ($admin === []) {
            throw self::notAdmin();
        }
        $byId = [];
        foreach ($admin as $account) {
            $byId[(int)$account['id']] = $account;
        }
        $partial = false;
        foreach (self::topAccounts($admin) as $top) {
            try {
                $rows = $client->getAll('/api/v1/accounts/' . (int)$top['id'] . '/sub_accounts', ['recursive' => 'true'], null, self::MAX_ACCOUNT_PAGES);
            } catch (CanvasDeadlineExceeded $exception) {
                throw $exception;
            } catch (CanvasApiException $exception) {
                if ($exception->status() === 401) {
                    throw $exception;
                }
                $partial = true;
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $byId[(int)$row['id']] ??= $row;
                }
            }
        }
        $children = [];
        foreach ($byId as $id => $account) {
            $parent = isset($account['parent_account_id']) ? (int)$account['parent_account_id'] : 0;
            $children[isset($byId[$parent]) ? $parent : 0][] = $id;
        }
        foreach ($children as &$list) {
            usort($list, static fn(int $a, int $b): int => strcasecmp((string)($byId[$a]['name'] ?? ''), (string)($byId[$b]['name'] ?? '')));
        }
        unset($list);
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children, $byId): int {
            $count = 0;
            foreach ($children[$parent] ?? [] as $id) {
                $index = count($out);
                $out[] = [
                    'id' => $id,
                    'name' => (string)($byId[$id]['name'] ?? ('Account ' . $id)),
                    'parent_id' => $depth === 0 ? null : $parent,
                    'root' => empty($byId[$id]['parent_account_id']),
                    'depth' => $depth,
                    'below' => 0,
                ];
                $below = $walk($id, $depth + 1);
                $out[$index]['below'] = $below;
                $count += 1 + $below;
            }
            return $count;
        };
        $walk(0, 0);
        return ['accounts' => $out, 'partial' => $partial, 'max_selectable' => self::MAX_ACCOUNT_IDS];
    }

    /**
     * Whether each account has the tool installed on it, inherits it from a parent account, or
     * neither; read-only, up to FLAGS_PER_CALL accounts per call.
     */
    public function accountFlags(string $baseUrl, string $token, string $launchUrl, string $accountIds): array
    {
        $launchUrl = trim($launchUrl) !== '' ? trim($launchUrl) : trim((string)($this->lti['launch_url'] ?? ''));
        if ($launchUrl === '') {
            return ['flags' => [], 'launch_url_known' => false];
        }
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $accountIds, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id): bool => $id > 0
        ))), 0, self::FLAGS_PER_CALL);
        $started = $this->now();
        $client = $this->client($baseUrl, $token, $started + self::FLAGS_HARD_SECONDS);
        $flags = [];
        $pending = [];
        foreach ($ids as $index => $id) {
            if ($index > 0 && $this->now() - $started > self::FLAGS_SOFT_SECONDS) {
                $pending = array_slice($ids, $index);
                break;
            }
            try {
                $flags[(string)$id] = CanvasLtiDeployer::inspectAccount($client, $id, $launchUrl);
            } catch (CanvasDeadlineExceeded) {
                $pending = array_slice($ids, $index);
                break;
            } catch (CanvasApiException $exception) {
                if ($exception->status() === 401) {
                    throw $exception;
                }
                $flags[(string)$id] = ['app' => 'error', 'nav' => 'error', 'tool' => $exception->getMessage()];
            }
        }
        return ['flags' => $flags, 'pending' => $pending, 'launch_url_known' => true];
    }

    /** Pages read when counting one account's courses; past this the count shows as "N+". */
    public const COURSE_COUNT_PAGES = 10;

    /**
     * How many courses each account holds, its sub-accounts' courses included, which is what an
     * install on that account reaches. Read-only, up to FLAGS_PER_CALL accounts per call; a count
     * stops at COURSE_COUNT_PAGES pages and is then marked as more.
     */
    public function accountCourseCounts(string $baseUrl, string $token, string $accountIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,]+/', $accountIds, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn(int $id): bool => $id > 0
        ))), 0, self::FLAGS_PER_CALL);
        $started = $this->now();
        $client = $this->client($baseUrl, $token, $started + self::FLAGS_HARD_SECONDS);
        $counts = [];
        $pending = [];
        foreach ($ids as $index => $id) {
            if ($index > 0 && $this->now() - $started > self::FLAGS_SOFT_SECONDS) {
                $pending = array_slice($ids, $index);
                break;
            }
            try {
                $count = 0;
                $page = $client->getPage("/api/v1/accounts/{$id}/courses", ['per_page' => CanvasClient::PER_PAGE]);
                for ($read = 1; ; $read++) {
                    $count += count($page['items']);
                    if ($page['next'] === null || $read >= self::COURSE_COUNT_PAGES) {
                        break;
                    }
                    $page = $client->getPage($page['next']);
                }
                $counts[(string)$id] = ['courses' => $count, 'more' => $page['next'] !== null];
            } catch (CanvasDeadlineExceeded) {
                $pending = array_slice($ids, $index);
                break;
            } catch (CanvasApiException $exception) {
                if ($exception->status() === 401) {
                    throw $exception;
                }
                $counts[(string)$id] = ['error' => $exception->getMessage()];
            }
        }
        return ['counts' => $counts, 'pending' => $pending];
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
            if ($key === 'launch_url') {
                $typed = CanvasClient::withScheme($typed);
            }
            $effective[$key] = $typed !== '' ? $typed : ($key === 'consumer_secret' ? (string)($this->lti[$key] ?? '') : trim((string)($this->lti[$key] ?? '')));
        }
        $ltiErrors = [];
        $launchOk = filter_var($effective['launch_url'], FILTER_VALIDATE_URL) !== false
            && strtolower((string)parse_url($effective['launch_url'], PHP_URL_SCHEME)) === 'https';
        if (!$launchOk) {
            $ltiErrors['lti_launch_url'] = 'Enter the tool\'s LTI launch URL (an https address).';
        }
        // The key and secret are only needed to create a tool in a course that has none (a tool
        // pushed from the account needs nothing), or to rewrite existing tools. Without them,
        // courses that would need a new tool are skipped and say so.
        // Installing always needs the real key and secret: a tool with the right launch URL but
        // wrong credentials looks installed and fails at launch. Removing needs only the URL.
        if ($settings['operation'] === 'deploy') {
            if ($effective['consumer_key'] === '') {
                $ltiErrors['lti_consumer_key'] = 'Enter the LTI consumer key.';
            }
            if ($effective['consumer_secret'] === '') {
                $ltiErrors['lti_consumer_secret'] = 'Enter the LTI shared secret.';
            }
        }
        if ($ltiErrors !== []) {
            throw new CanvasDeployException('Fill in the tool\'s LTI settings.', 'lti_required', 422, $ltiErrors);
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
        // Opt-in: give the account's own copy of the tool a Course Navigation link, so courses
        // under it get the link. Needs no key or secret.
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
                $label = 'Account "' . (string)($account['name'] ?? $account['id']) . '": the tool ' . CanvasLtiDeployer::describeTool($match);
                if (empty($settings['dry_run'])) {
                    $client->put('/api/v1/accounts/' . (int)$account['id'] . '/external_tools/' . (int)$match['id'], [
                        'course_navigation' => CanvasLtiDeployer::navPlacement($settings['link_name'], ...CanvasLtiDeployer::navFieldsFrom($settings)),
                    ]);
                    $notes[] = $label . ': Course Navigation link added.';
                } else {
                    $notes[] = $label . ': would get a Course Navigation link.';
                }
                $accountNavFixed = true;
            }
            if ($accounts !== [] && !$accountNavFixed) {
                $notes[] = 'No account-level copy of the tool without a nav link was found in your admin accounts, so no account tool was changed.';
            }
        }

        // For an account run each chosen account is checked on its own, so this course-run check
        // is skipped.
        $accountScope = $settings['scope'] === 'account';
        $accountTool = $accounts !== [] && !$accountScope;
        foreach ($accountScope ? [] : $accounts as $account) {
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
                    . ($doubt ?? 'The tool ' . CanvasLtiDeployer::describeTool($match) . ' has no Course Navigation link; each course is checked on its own.');
                continue;
            }
            foreach (CanvasLtiDeployer::toolWarnings($match, $effective['consumer_key'], $settings['privacy_level']) as $warning) {
                $notes[] = 'Account "' . (string)($account['name'] ?? $account['id']) . '": ' . $warning;
            }
        }
        if ($accountTool) {
            $notes[] = 'The tool is already installed at the account level, so per-course tool checks are skipped.';
            if (!empty($settings['update_nav'])) {
                $notes[] = 'Its nav link comes from the account tool, so "Update the nav link" changes nothing in these courses.';
            }
        }

        $runId = bin2hex(random_bytes(16));
        $this->acquireLock($runId);

        try {
            $runKey = Encryption::generateKey();
            $accountIds = array_map(static fn(array $account): int => (int)$account['id'], $accounts);
            $isCourseScope = $settings['scope'] === 'course' || $accountScope;
            $now = $this->nowInt();
            $this->store['run'] = [
                'id' => $runId,
                'user_id' => $this->userId,
                'token' => (new Encryption($runKey))->encrypt(trim($token)),
                // The consumer key and secret are kept encrypted with the run key, like the token,
                // while the run waits in the queue.
                'lti' => ['launch_url' => $effective['launch_url']],
                'lti_key' => $effective['consumer_key'] === '' ? '' : (new Encryption($runKey))->encrypt($effective['consumer_key']),
                'lti_secret' => $effective['consumer_secret'] === '' ? '' : (new Encryption($runKey))->encrypt($effective['consumer_secret']),
                'settings' => $settings,
                'account_tool' => $accountTool,
                'account_nav_fixed' => $accountNavFixed,
                'accounts' => $accountIds,
                'account_index' => 0,
                'phase' => $accountScope ? 'accounts' : ($isCourseScope ? 'ids' : 'main'),
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
            'create_nav' => $settings['create_nav'],
            'update_nav' => $settings['update_nav'],
            'nav_visibility' => $settings['nav_visibility'],
            'privacy_level' => $settings['privacy_level'],
            'module_published' => $settings['module_published'],
            'create_module' => $settings['create_module'],
            'account_tool' => $accountTool,
        ]);

        return [
            'run_id' => $runId, 'run_key' => $runKey, 'notes' => $notes, 'account_tool' => $accountTool,
            'origin' => $client->origin(),
            'canvas_user' => ['id' => is_array($self) ? (int)($self['id'] ?? 0) : 0, 'name' => is_array($self) ? (string)($self['name'] ?? '') : ''],
        ];
    }

    /**
     * $received is how many result rows the page already has. Rows it has not confirmed are
     * sent again, so a reply lost on the way (a proxy timeout, a dropped connection) loses
     * no rows. Without it, every row sent before is taken as received.
     *
     * $timeTarget and $hardDeadline (seconds) default to TIME_TARGET and HARD_DEADLINE; the
     * queue passes shorter ones so a step fits under a gateway's timeout.
     */
    public function batch(string $runId, string $runKey, ?int $received = null, ?float $timeTarget = null, ?float $hardDeadline = null): array
    {
        $timeTarget ??= self::TIME_TARGET;
        $hardDeadline ??= self::HARD_DEADLINE;
        $started = $this->now();
        $run = &$this->loadRun($runId);
        $token = $this->decryptToken($run, $runKey);
        $this->touchLock($runId);

        // Runs started before unconfirmed rows were kept have no such keys yet.
        $run += ['results_from' => 0, 'unconfirmed' => []];
        $sent = $run['results_from'] + count($run['unconfirmed']);
        $confirmed = min($received ?? $sent, $sent) - $run['results_from'];
        if ($confirmed > 0) {
            array_splice($run['unconfirmed'], 0, $confirmed);
            $run['results_from'] += $confirmed;
        }

        $client = $this->client($run['settings']['base_url'], $token, $started + $hardDeadline);
        unset($token);
        $secret = $run['lti_secret'] === '' ? '' : (new Encryption($runKey))->decrypt((string)$run['lti_secret']);
        // Runs queued before the key was encrypted still have it under lti.
        $consumerKey = isset($run['lti_key'])
            ? ($run['lti_key'] === '' ? '' : (new Encryption($runKey))->decrypt((string)$run['lti_key']))
            : (string)($run['lti']['consumer_key'] ?? '');
        $deployer = new CanvasLtiDeployer($client, ['launch_url' => (string)$run['lti']['launch_url'], 'consumer_key' => $consumerKey, 'consumer_secret' => $secret] + [
            'link_name' => $run['settings']['link_name'],
            'create_module' => $run['settings']['create_module'],
            'module_name' => $run['settings']['module_name'],
            'dry_run' => $run['settings']['dry_run'],
            'update_existing' => $run['settings']['update_existing'] ?? false,
            'account_tool' => $run['account_tool'],
            'account_nav_fixed' => $run['account_nav_fixed'] ?? false,
            'create_nav' => $run['settings']['create_nav'] ?? false,
            'update_nav' => $run['settings']['update_nav'] ?? false,
            'privacy_level' => $run['settings']['privacy_level'] ?? 'public',
            'module_published' => $run['settings']['module_published'] ?? false,
        ] + array_intersect_key($run['settings'], ['nav_visibility' => true, 'nav_default' => true]), $this->sisInspector);

        $delay = $run['settings']['course_delay_ms'] / 1000;
        $results = [];
        $processed = 0;
        $stop = 'end_of_stream';
        // Why the step stopped, with the numbers behind it, for the log.
        $why = [];
        $elapsed = fn(): float => round($this->now() - $started, 3);

        // This run's own course times (lookup plus work, without the delay): the longest one
        // for the look-ahead, and an average, weighted to recent courses, for time left.
        $average = isset($run['course_seconds']) ? (float)$run['course_seconds'] : null;
        $longest = isset($run['course_seconds_max']) ? (float)$run['course_seconds_max'] : null;

        while (true) {
            // Look ahead: don't start a course if one as slow as this run's slowest so far would
            // end past the target. At least one course per step, so a slow run still moves; and
            // none of this once nothing is left, so the step can mark the run done.
            if ($processed > 0 && $longest !== null && self::remaining($run) !== 0
                && $this->now() - $started + $longest + $delay > $timeTarget) {
                $stop = 'estimate';
                $why = ['detail' => 'The next course could end past the target', 'elapsed' => $elapsed(), 'slowest_course' => round($longest, 3), 'delay' => $delay, 'target' => $timeTarget];
                break;
            }
            $itemStarted = $this->now();
            $slept = 0.0;
            try {
                $item = $this->nextItem($run, $client);
            } catch (CanvasDeadlineExceeded $exception) {
                $stop = 'deadline';
                $why = ['detail' => 'Reading the course list reached the hard deadline', 'elapsed' => $elapsed(), 'deadline' => $hardDeadline, 'error' => $exception->getMessage()];
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
                $why = ['detail' => 'No courses left', 'elapsed' => $elapsed()];
                break;
            }

            if ($processed > 0 && $delay > 0) {
                if ($this->now() + $delay >= $started + $hardDeadline - 1) {
                    array_unshift($run['queue'], $item);
                    $stop = 'deadline';
                    $why = ['detail' => 'The delay before the next course would pass the hard deadline', 'elapsed' => $elapsed(), 'delay' => $delay, 'deadline' => $hardDeadline];
                    break;
                }
                ($this->sleeper)($delay);
                $slept = $delay;
            }

            if ($item['kind'] === 'result') {
                $row = $item['result'];
            } else {
                $target = $item['kind'] === 'account' ? $item['account'] : $item['course'];
                $courseId = (int)$target['id'];
                $removing = ($run['settings']['operation'] ?? 'deploy') === 'remove';
                try {
                    $row = match (true) {
                        $item['kind'] === 'account' && $removing => $deployer->removeAccount($target),
                        $item['kind'] === 'account' => $deployer->deployAccount($target),
                        $removing => $deployer->removeCourse($target),
                        default => $deployer->deployCourse($target),
                    };
                    unset($run['attempts'][$courseId]);
                    $took = max(0.0, $this->now() - $itemStarted - $slept);
                    $average = $average === null ? $took : 0.7 * $average + 0.3 * $took;
                    $longest = max($longest ?? 0.0, $took);
                    $run['course_seconds'] = round($average, 3);
                    $run['course_seconds_max'] = round($longest, 3);
                } catch (CanvasDeadlineExceeded) {
                    $hits = ($run['attempts'][$courseId] ?? 0) + 1;
                    $run['attempts'][$courseId] = $hits;
                    $stop = 'deadline';
                    $why = ['detail' => 'A course reached the hard deadline', 'course_id' => $courseId, 'attempt' => $hits, 'max_attempts' => self::MAX_DEADLINE_HITS, 'elapsed' => $elapsed(), 'deadline' => $hardDeadline];
                    if ($hits < self::MAX_DEADLINE_HITS) {
                        $why['then'] = 'Put back to start again next step';
                        array_unshift($run['queue'], $item);
                        break;
                    }
                    $why['then'] = 'Marked failed after ' . $hits . ' attempts';
                    unset($run['attempts'][$courseId]);
                    $row = CanvasLtiDeployer::failedRow(
                        $target,
                        'Timed out: Canvas did not finish this ' . ($item['kind'] === 'account' ? 'account' : 'course') . ' within the batch deadline three times.'
                    ) + ($item['kind'] === 'account' ? ['kind' => 'account', 'module' => ''] : []);
                    $this->record($run, $row, $results, $processed);
                    break;
                }
            }

            $this->record($run, $row, $results, $processed);
            if ($this->now() - $started >= $timeTarget) {
                $stop = 'time_target';
                $why = ['detail' => 'The target time was reached', 'elapsed' => $elapsed(), 'target' => $timeTarget];
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
            'stop_detail' => $why,
            'done' => $run['phase'] === 'done' && $run['queue'] === [],
            // Every row the page has not confirmed yet, starting at row number results_from.
            'results' => $run['unconfirmed'],
            'results_from' => $run['results_from'],
            'counts' => $run['counts'],
            'delay_ms' => $run['settings']['course_delay_ms'],
            'course_seconds' => $run['course_seconds'] ?? null,
            'remaining' => self::remaining($run),
        ];
    }

    /** Courses still to do, when known: a list of course IDs is; a term or account listing is not. */
    private static function remaining(array $run): ?int
    {
        return match ($run['phase']) {
            'ids', 'accounts' => count($run['ids']) + count($run['queue']),
            'done' => count($run['queue']),
            default => null,
        };
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
        // Kept until the page confirms it, even if this batch ends in an error.
        $run['unconfirmed'][] = $row;
        $processed++;
        $counts = &$run['counts'];
        $counts['processed']++;
        // Runs started before these counts existed have no such keys yet.
        $counts += array_fill_keys(['tool_copied', 'tool_would_copy'], 0);
        // A course copy made for the nav link is counted with "already present" (the course had
        // the tool from its account) and on its own line, not as a new install.
        match ($row['tool']) {
            'created' => $counts['tool_created']++,
            'would_create' => $counts['tool_would_create']++,
            'present', 'present_account', 'present_parent', 'created_for_nav', 'would_create_for_nav' => $counts['tool_present']++,
            'removed' => $counts['tool_removed']++,
            'updated' => $counts['tool_updated']++,
            'would_update' => $counts['tool_would_update']++,
            'would_remove' => $counts['tool_would_remove']++,
            'absent' => $counts['tool_absent']++,
            default => null,
        };
        match ($row['tool']) {
            'created_for_nav' => $counts['tool_copied']++,
            'would_create_for_nav' => $counts['tool_would_copy']++,
            default => null,
        };
        // Runs started before the nav counts existed have no such keys yet.
        $counts += array_fill_keys(['nav_added', 'nav_would_add', 'nav_present', 'nav_not_added', 'nav_updated', 'nav_would_update'], 0);
        match ($row['nav'] ?? '') {
            'added' => $counts['nav_added']++,
            'would_add' => $counts['nav_would_add']++,
            'updated' => $counts['nav_updated']++,
            'would_update' => $counts['nav_would_update']++,
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

            if ($run['phase'] === 'accounts') {
                if ($run['ids'] === []) {
                    $run['phase'] = 'done';
                    return null;
                }
                $accountId = (int)$run['ids'][0];
                try {
                    $account = $client->get("/api/v1/accounts/{$accountId}");
                } catch (CanvasDeadlineExceeded $exception) {
                    throw $exception;
                } catch (CanvasApiException $exception) {
                    array_shift($run['ids']);
                    return ['kind' => 'result', 'result' => CanvasLtiDeployer::failedRow(
                        ['id' => $accountId, 'name' => 'Account ' . $accountId],
                        'Account lookup failed: ' . $exception->getMessage()
                    ) + ['kind' => 'account', 'module' => '']];
                }
                array_shift($run['ids']);
                return ['kind' => 'account', 'account' => [
                    'id' => $accountId,
                    'name' => is_array($account) ? (string)($account['name'] ?? ('Account ' . $accountId)) : 'Account ' . $accountId,
                    'parent_account_id' => is_array($account) ? ($account['parent_account_id'] ?? null) : null,
                ]];
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
        if ($this->idleExpires && $this->nowInt() - (int)$run['touched_at'] > self::IDLE_EXPIRY) {
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

    /**
     * The accounts the token administers (only the top ones unless $topOnly is false); empty for
     * a token that is not an account admin.
     */
    private function adminAccounts(CanvasClient $client, bool $topOnly = true): array
    {
        try {
            $accounts = array_values(array_filter($client->getAll('/api/v1/accounts'), static fn($account): bool => is_array($account) && isset($account['id'])));
            return $topOnly ? self::topAccounts($accounts) : $accounts;
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
        if (!$this->installLock) {
            return;
        }
        $now = $this->nowInt();
        $this->withLock(function (array $holder) use ($runId, $now): array {
            if (($holder['run_id'] ?? '') !== '' && $holder['run_id'] !== $runId
                && $now - (int)($holder['touched_at'] ?? 0) <= self::IDLE_EXPIRY) {
                throw new CanvasDeployException(
                    'Another Canvas deployment is running on this server (started '
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
        if (!$this->installLock) {
            return;
        }
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
        if (!$this->installLock) {
            return;
        }
        $this->withLock(static fn(array $holder): ?array => ($holder['run_id'] ?? '') === $runId ? null : ($holder ?: null));
    }
}
