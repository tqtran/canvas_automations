<?php
declare(strict_types=1);

/**
 * In-memory stand-in for the Canvas REST endpoints used by the deployment page.
 * Shared by tests/canvas_deploy_test.php and tests/fixtures/mock_canvas_server.php.
 *
 * Test values only: the mock accepts the token below and nothing else.
 */
final class FakeCanvas
{
    public const TOKEN = 'mock-canvas-token-0001';
    public const LAUNCH_URL = 'https://tool.example.edu/lti/launch.php';
    public const CONSUMER_KEY = 'lti-test-key';
    public const CONSUMER_SECRET = 'lti-test-secret';
    public const PROD_LAUNCH_URL = 'https://tool-prod.example.edu/lti/launch.php';

    /** @var callable(float): void */
    private $latency;

    public function __construct(public array $state, ?callable $latency = null)
    {
        $this->latency = $latency ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int)round($seconds * 1_000_000));
            }
        };
    }

    /** Scenario seed. $options: account_tool (bool), slow (bool), throttle (int), fresh_courses (int). */
    public static function scenario(array $options = []): array
    {
        $now = time();
        $iso = static fn(int $timestamp): string => gmdate('Y-m-d\TH:i:s\Z', $timestamp);
        $state = [
            'next_id' => 5000,
            'throttle' => (int)($options['throttle'] ?? 0),
            'slow_seconds' => !empty($options['slow']) ? 20.0 : 0.0,
            'slow_courses' => !empty($options['slow']) ? [1007] : [],
            'accounts' => !empty($options['non_admin']) ? [] : [['id' => 1, 'name' => 'Example College', 'parent_account_id' => null, 'root_account_id' => null]],
            // Tools on account 1 (the root); account_tools_by holds the other accounts' tools.
            'account_tools' => [],
            'account_tools_by' => [],
            // Sub-accounts under the root: Arts > Music, and Science, where writes are refused.
            'sub_accounts' => [
                ['id' => 2, 'name' => 'Arts', 'parent_account_id' => 1, 'root_account_id' => 1],
                ['id' => 3, 'name' => 'Music', 'parent_account_id' => 2, 'root_account_id' => 1],
                ['id' => 4, 'name' => 'Science', 'parent_account_id' => 1, 'root_account_id' => 1],
            ],
            'forbidden_accounts' => [4],
            'terms' => [
                10 => ['id' => 10, 'name' => 'Fall 2026', 'start_at' => $iso($now - 30 * 86400), 'end_at' => $iso($now + 60 * 86400)],
                11 => ['id' => 11, 'name' => 'Spring 2026', 'start_at' => $iso($now - 250 * 86400), 'end_at' => $iso($now - 130 * 86400)],
                12 => ['id' => 12, 'name' => 'Spring 2027', 'start_at' => $iso($now + 100 * 86400), 'end_at' => $iso($now + 220 * 86400)],
            ],
            'courses' => [],
            'calls' => [],
        ];
        if (!empty($options['account_tool'])) {
            $state['account_tools'][] = self::tool(900, 'Course Reports', self::LAUNCH_URL, self::CONSUMER_KEY);
        }

        $add = static function (int $id, int $term, string $name, ?string $sis, array $extra = []) use (&$state): void {
            $state['courses'][$id] = $extra + [
                'id' => $id,
                'name' => $name,
                'course_code' => 'C' . $id,
                'sis_course_id' => $sis,
                'workflow_state' => 'available',
                'enrollment_term_id' => $term,
                'end_at' => null,
                'restrict_enrollments_to_course_dates' => false,
                'blueprint_child' => false,
                'forbidden' => false,
                'tools' => [],
                'modules' => [],
            ];
        };

        $add(1001, 10, 'ENG-101 Fresh course', '10001.202630');
        $add(1002, 10, 'ENG-102 Already installed', '10002.202630', ['tools' => [self::tool(801, 'Course Reports', self::LAUNCH_URL . '/', self::CONSUMER_KEY)]]);
        $add(1003, 10, 'ENG-103 Different key', '10003.202630', ['tools' => [self::tool(802, 'Course Tool', 'HTTPS://TOOL.EXAMPLE.EDU:443/lti/launch.php', 'old-key')]]);
        $add(1004, 10, 'ENG-104 Refreshed from production', '10004.202630', ['tools' => [self::tool(803, 'Course Reports', self::PROD_LAUNCH_URL, self::CONSUMER_KEY)]]);
        $add(1005, 10, 'ENG-105 Blueprint child', '10005.202630', ['blueprint_child' => true]);
        $add(1006, 10, 'ENG-106 No permission', '10006.202630', ['forbidden' => true]);
        $add(1007, 10, 'ENG-107 Slow course', '10007.202630');
        $add(1008, 10, 'ENG-108 No SIS ID', null);
        $add(1009, 10, 'ENG-109 Odd SIS ID', 'SANDBOX-ENG109');
        $add(1010, 10, 'ENG-110 Published module', '10010.202630', ['modules' => [['id' => 701, 'name' => 'Course Tool', 'published' => true, 'items' => []]]]);
        $add(1011, 10, 'ENG-111 Link already there', '10011.202630', ['modules' => [[
            'id' => 702, 'name' => 'Assessment', 'published' => false,
            'items' => [['id' => 7020, 'type' => 'ExternalTool', 'title' => 'Tool link', 'external_url' => self::LAUNCH_URL]],
        ]]]);
        $fresh = (int)($options['fresh_courses'] ?? 119);
        for ($i = 0; $i < $fresh; $i++) {
            $id = 1012 + $i;
            $add($id, 10, 'SEC-' . $id . ' Section', (string)(20000 + $i) . '.202630');
        }
        for ($i = 0; $i < 5; $i++) {
            $add(1200 + $i, 11, 'OLD-' . (1200 + $i) . ' Last spring', (string)(30000 + $i) . '.202610', $i === 0 ? ['workflow_state' => 'completed'] : []);
        }
        for ($i = 0; $i < 3; $i++) {
            $add(1300 + $i, 12, 'NEW-' . (1300 + $i) . ' Next spring', (string)(40000 + $i) . '.202710', ['workflow_state' => 'unpublished']);
        }
        return $state;
    }

    public static function tool(int $id, string $name, ?string $url, string $key, string $privacy = 'public', ?string $domain = null): array
    {
        return ['id' => $id, 'name' => $name, 'url' => $url, 'domain' => $domain, 'consumer_key' => $key, 'privacy_level' => $privacy];
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    public function handle(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        parse_str((string)($parts['query'] ?? ''), $query);
        parse_str((string)$body, $form);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->state['calls'][] = $method . ' ' . $path;

        if (!in_array('Authorization: Bearer ' . self::TOKEN, $headers, true)) {
            return self::json(401, ['errors' => [['message' => 'Invalid access token.']]]);
        }
        if ($this->state['throttle'] > 0) {
            $this->state['throttle']--;
            return ['status' => 403, 'headers' => ['x-rate-limit-remaining' => ['0']], 'body' => '403 Forbidden (Rate Limit Exceeded)'];
        }

        if (preg_match('#^/api/v1/courses/(\d+)#', $path, $match)) {
            $course = $this->state['courses'][(int)$match[1]] ?? null;
            if ($course !== null && in_array($course['id'], $this->state['slow_courses'], true) && $path !== '/api/v1/courses/' . $course['id']) {
                ($this->latency)((float)$this->state['slow_seconds']);
            }
        }

        $route = $method . ' ' . preg_replace('#/\d+#', '/:id', $path);
        return match ($route) {
            'GET /api/v1/users/self' => self::json(200, ['id' => 1, 'name' => 'Mock Admin']),
            'GET /api/v1/accounts' => self::json(200, $this->state['accounts']),
            'GET /api/v1/accounts/:id/terms' => self::json(200, ['enrollment_terms' => array_values($this->state['terms'])]),
            'GET /api/v1/accounts/:id' => (function () use ($path): array {
                $account = $this->account($this->accountId($path));
                return $account === null ? self::json(404, ['errors' => [['message' => 'not found']]]) : self::json(200, $account);
            })(),
            'GET /api/v1/accounts/:id/sub_accounts' => (function () use ($path, $query): array {
                $parent = $this->accountId($path);
                $below = array_values(array_filter($this->state['sub_accounts'] ?? [], fn(array $account): bool => ($query['recursive'] ?? '') === 'true'
                    ? in_array($parent, $this->ancestors((int)$account['id']), true)
                    : (int)$account['parent_account_id'] === $parent));
                return self::json(200, $below);
            })(),
            'GET /api/v1/accounts/:id/external_tools' => (function () use ($path, $query): array {
                $id = $this->accountId($path);
                $tools = $this->accountTools($id);
                if (($query['include_parents'] ?? '') === 'true') {
                    foreach ($this->ancestors($id) as $ancestor) {
                        array_push($tools, ...$this->accountTools($ancestor));
                    }
                }
                return self::json(200, $tools);
            })(),
            'POST /api/v1/accounts/:id/external_tools' => (function () use ($path, $form): array {
                $id = $this->accountId($path);
                if (in_array($id, $this->state['forbidden_accounts'] ?? [], true)) {
                    return self::json(403, ['errors' => [['message' => 'user not authorized to perform that action']]]);
                }
                $tool = self::tool($this->state['next_id']++, (string)$form['name'], (string)$form['url'], (string)$form['consumer_key'], (string)$form['privacy_level']);
                $tool['course_navigation'] = $form['course_navigation'] ?? null;
                $tool['shared_secret_set'] = ($form['shared_secret'] ?? '') !== '';
                $tools = $this->accountTools($id);
                $tools[] = $tool;
                $this->setAccountTools($id, $tools);
                return self::json(200, $tool);
            })(),
            'PUT /api/v1/accounts/:id/external_tools/:id' => (function () use ($path, $form): array {
                $id = $this->accountId($path);
                if (in_array($id, $this->state['forbidden_accounts'] ?? [], true)) {
                    return self::json(403, ['errors' => [['message' => 'user not authorized to perform that action']]]);
                }
                preg_match('#/external_tools/(\d+)#', $path, $toolMatch);
                $tools = $this->accountTools($id);
                foreach ($tools as &$tool) {
                    if ($tool['id'] === (int)$toolMatch[1]) {
                        foreach (['course_navigation', 'consumer_key', 'privacy_level'] as $field) {
                            if (array_key_exists($field, $form)) {
                                $tool[$field] = $form[$field];
                            }
                        }
                        if (array_key_exists('shared_secret', $form)) {
                            $tool['shared_secret_set'] = $form['shared_secret'] !== '';
                        }
                        $updated = $tool;
                        unset($tool);
                        $this->setAccountTools($id, $tools);
                        return self::json(200, $updated);
                    }
                }
                return self::json(404, ['errors' => [['message' => 'tool not found']]]);
            })(),
            'DELETE /api/v1/accounts/:id/external_tools/:id' => (function () use ($path): array {
                $id = $this->accountId($path);
                if (in_array($id, $this->state['forbidden_accounts'] ?? [], true)) {
                    return self::json(403, ['errors' => [['message' => 'user not authorized to perform that action']]]);
                }
                preg_match('#/external_tools/(\d+)#', $path, $toolMatch);
                $tools = $this->accountTools($id);
                $kept = array_values(array_filter($tools, static fn(array $tool): bool => $tool['id'] !== (int)$toolMatch[1]));
                if (count($kept) === count($tools)) {
                    return self::json(404, ['errors' => [['message' => 'tool not found']]]);
                }
                $this->setAccountTools($id, $kept);
                return self::json(200, ['id' => (int)$toolMatch[1]]);
            })(),
            'GET /api/v1/accounts/:id/courses' => $this->listCourses($query, $origin, $path),
            'GET /api/v1/courses' => $this->listUserCourses($query, $origin, $path),
            'GET /api/v1/courses/:id' => $this->course($path, fn(array $course): array => self::json(200, $this->publicCourse($course))),
            'GET /api/v1/courses/:id/blueprint_subscriptions' => $this->course($path, static fn(array $course): array => self::json(200, $course['blueprint_child'] ? [['id' => 1, 'blueprint_course' => ['id' => 999]]] : [])),
            'GET /api/v1/courses/:id/external_tools' => $this->course($path, function (array $course) use ($query): array {
                if ($course['forbidden']) {
                    return self::json(403, ['errors' => [['message' => 'user not authorized to perform that action']]]);
                }
                $tools = $course['tools'];
                if (($query['include_parents'] ?? '') === 'true') {
                    array_push($tools, ...$this->state['account_tools']);
                }
                return self::json(200, $tools);
            }),
            'POST /api/v1/courses/:id/external_tools' => $this->course($path, function (array $course) use ($form): array {
                if (!empty($this->state['reject_blank_secret']) && ($form['shared_secret'] ?? '') === '') {
                    return self::json(400, ['errors' => [['message' => 'shared_secret is required']]]);
                }
                if (!empty($this->state['reject_placeholder_secret']) && ($form['shared_secret'] ?? '') === 'fake') {
                    return self::json(400, ['errors' => [['message' => 'placeholder secret refused']]]);
                }
                $tool = self::tool($this->state['next_id']++, (string)$form['name'], (string)$form['url'], (string)$form['consumer_key'], (string)$form['privacy_level']);
                $tool['course_navigation'] = $form['course_navigation'] ?? null;
                $tool['shared_secret_set'] = ($form['shared_secret'] ?? '') !== '';
                $this->state['courses'][$course['id']]['tools'][] = $tool;
                return self::json(200, $tool);
            }),
            'GET /api/v1/courses/:id/modules' => $this->course($path, static fn(array $course): array => self::json(200, $course['modules'])),
            'POST /api/v1/courses/:id/modules' => $this->course($path, function (array $course) use ($form): array {
                $module = ['id' => $this->state['next_id']++, 'name' => (string)$form['module']['name'], 'published' => false, 'items' => []];
                if ((int)($form['module']['position'] ?? 0) === 1) {
                    array_unshift($this->state['courses'][$course['id']]['modules'], $module);
                } else {
                    $this->state['courses'][$course['id']]['modules'][] = $module;
                }
                return self::json(200, $module);
            }),
            'PUT /api/v1/courses/:id/modules/:id' => $this->course($path, function (array $course) use ($form, $path): array {
                preg_match('#/modules/(\d+)#', $path, $m);
                $modules = &$this->state['courses'][$course['id']]['modules'];
                foreach ($modules as $index => $module) {
                    if ($module['id'] === (int)$m[1]) {
                        if (isset($form['module']['published'])) {
                            $module['published'] = $form['module']['published'] === 'true';
                            $modules[$index] = $module;
                        }
                        if ((int)($form['module']['position'] ?? 0) === 1) {
                            array_splice($modules, $index, 1);
                            array_unshift($modules, $module);
                        }
                        return self::json(200, $module);
                    }
                }
                return self::json(404, ['errors' => [['message' => 'not found']]]);
            }),
            'POST /api/v1/courses/:id/modules/:id/items' => $this->course($path, function (array $course) use ($form, $path): array {
                preg_match('#/modules/(\d+)/items#', $path, $moduleMatch);
                foreach ($this->state['courses'][$course['id']]['modules'] as &$module) {
                    if ($module['id'] === (int)$moduleMatch[1]) {
                        $item = ['id' => $this->state['next_id']++, 'published' => false] + $form['module_item'];
                        $module['items'][] = $item;
                        return self::json(200, $item);
                    }
                }
                return self::json(404, ['errors' => [['message' => 'module not found']]]);
            }),
            'PUT /api/v1/courses/:id/modules/:id/items/:id' => $this->course($path, function (array $course) use ($form, $path): array {
                preg_match('#/modules/(\d+)/items/(\d+)#', $path, $m);
                foreach ($this->state['courses'][$course['id']]['modules'] as &$module) {
                    if ($module['id'] === (int)$m[1]) {
                        foreach ($module['items'] as &$item) {
                            if ($item['id'] === (int)$m[2]) {
                                if (isset($form['module_item']['published'])) {
                                    $item['published'] = $form['module_item']['published'] === 'true';
                                }
                                return self::json(200, $item);
                            }
                        }
                    }
                }
                return self::json(404, ['errors' => [['message' => 'module item not found']]]);
            }),
            'PUT /api/v1/courses/:id/external_tools/:id' => $this->course($path, function (array $course) use ($path, $form): array {
                preg_match('#/external_tools/(\d+)#', $path, $toolMatch);
                foreach ($this->state['courses'][$course['id']]['tools'] as &$tool) {
                    if ($tool['id'] === (int)$toolMatch[1]) {
                        $tool['consumer_key'] = (string)($form['consumer_key'] ?? $tool['consumer_key']);
                        if (array_key_exists('privacy_level', $form)) {
                            $tool['privacy_level'] = (string)$form['privacy_level'];
                        }
                        if (array_key_exists('shared_secret', $form)) {
                            $tool['shared_secret_set'] = $form['shared_secret'] !== '';
                        }
                        if (array_key_exists('course_navigation', $form)) {
                            $tool['course_navigation'] = $form['course_navigation'];
                        }
                        return self::json(200, $tool);
                    }
                }
                return self::json(404, ['errors' => [['message' => 'tool not found']]]);
            }),
            'DELETE /api/v1/courses/:id/external_tools/:id' => $this->course($path, function (array $course) use ($path): array {
                preg_match('#/external_tools/(\d+)#', $path, $toolMatch);
                $tools = $this->state['courses'][$course['id']]['tools'];
                $kept = array_values(array_filter($tools, static fn(array $tool): bool => $tool['id'] !== (int)$toolMatch[1]));
                if (count($kept) === count($tools)) {
                    return self::json(404, ['errors' => [['message' => 'tool not found']]]);
                }
                $this->state['courses'][$course['id']]['tools'] = $kept;
                return self::json(200, ['id' => (int)$toolMatch[1]]);
            }),
            'DELETE /api/v1/courses/:id/modules/:id/items/:id' => $this->course($path, function (array $course) use ($path): array {
                preg_match('#/modules/(\d+)/items/(\d+)#', $path, $m);
                foreach ($this->state['courses'][$course['id']]['modules'] as &$module) {
                    if ($module['id'] === (int)$m[1]) {
                        $module['items'] = array_values(array_filter($module['items'], static fn(array $item): bool => $item['id'] !== (int)$m[2]));
                        return self::json(200, ['id' => (int)$m[2]]);
                    }
                }
                return self::json(404, ['errors' => [['message' => 'module not found']]]);
            }),
            'DELETE /api/v1/courses/:id/modules/:id' => $this->course($path, function (array $course) use ($path): array {
                preg_match('#/modules/(\d+)#', $path, $m);
                $this->state['courses'][$course['id']]['modules'] = array_values(array_filter(
                    $this->state['courses'][$course['id']]['modules'],
                    static fn(array $module): bool => $module['id'] !== (int)$m[1]
                ));
                return self::json(200, ['id' => (int)$m[1]]);
            }),
            default => self::json(404, ['errors' => [['message' => 'No such mock route: ' . $route]]]),
        };
    }

    private function accountId(string $path): int
    {
        preg_match('#^/api/v1/accounts/(\d+)#', $path, $match);
        return (int)($match[1] ?? 0);
    }

    private function account(int $id): ?array
    {
        foreach (array_merge($this->state['accounts'], $this->state['sub_accounts'] ?? []) as $account) {
            if ((int)$account['id'] === $id) {
                return $account;
            }
        }
        // A token that is not an admin still sees the root by id.
        return $id === 1 ? ['id' => 1, 'name' => 'Example College', 'parent_account_id' => null, 'root_account_id' => null] : null;
    }

    /** Parent, grandparent, ... up to the root. */
    private function ancestors(int $id): array
    {
        $chain = [];
        $account = $this->account($id);
        while ($account !== null && !empty($account['parent_account_id'])) {
            $chain[] = (int)$account['parent_account_id'];
            $account = $this->account((int)$account['parent_account_id']);
        }
        return $chain;
    }

    private function accountTools(int $id): array
    {
        return $id === 1 ? $this->state['account_tools'] : ($this->state['account_tools_by'][$id] ?? []);
    }

    private function setAccountTools(int $id, array $tools): void
    {
        if ($id === 1) {
            $this->state['account_tools'] = $tools;
        } else {
            $this->state['account_tools_by'][$id] = $tools;
        }
    }

    private function course(string $path, callable $then): array
    {
        preg_match('#^/api/v1/courses/(\d+)#', $path, $match);
        $course = $this->state['courses'][(int)$match[1]] ?? null;
        return $course === null ? self::json(404, ['errors' => [['message' => 'The specified resource does not exist.']]]) : $then($course);
    }

    private function publicCourse(array $course): array
    {
        $term = $this->state['terms'][$course['enrollment_term_id']] ?? null;
        return [
            'id' => $course['id'],
            'name' => $course['name'],
            'course_code' => $course['course_code'],
            'sis_course_id' => $course['sis_course_id'],
            'workflow_state' => $course['workflow_state'],
            'end_at' => $course['end_at'],
            'restrict_enrollments_to_course_dates' => $course['restrict_enrollments_to_course_dates'],
            'enrollment_term_id' => $course['enrollment_term_id'],
            'term' => $term === null ? null : ['id' => $term['id'], 'name' => $term['name'], 'end_at' => $term['end_at']],
        ];
    }

    private function listCourses(array $query, string $origin, string $path): array
    {
        $states = $query['state'] ?? ['created', 'claimed', 'available', 'unpublished'];
        $matches = [];
        foreach ($this->state['courses'] as $course) {
            $state = $course['workflow_state'] === 'unpublished' ? 'claimed' : $course['workflow_state'];
            if (!in_array($state, $states, true)) {
                continue;
            }
            if (isset($query['enrollment_term_id']) && (int)$query['enrollment_term_id'] !== $course['enrollment_term_id']) {
                continue;
            }
            if (isset($query['blueprint_associated']) && ($query['blueprint_associated'] === 'true') !== $course['blueprint_child']) {
                continue;
            }
            if (isset($query['ends_after'])) {
                $end = $this->state['terms'][$course['enrollment_term_id']]['end_at'] ?? null;
                if ($end !== null && strtotime($end) < strtotime((string)$query['ends_after'])) {
                    continue;
                }
            }
            $matches[] = $this->publicCourse($course);
        }
        $perPage = max(1, (int)($query['per_page'] ?? 10));
        $page = max(1, (int)($query['page'] ?? 1));
        $slice = array_slice($matches, ($page - 1) * $perPage, $perPage);
        $headers = [];
        if ($page * $perPage < count($matches)) {
            $next = $query;
            $next['page'] = $page + 1;
            $headers['link'] = ['<' . $origin . $path . '?' . http_build_query($next) . '>; rel="next"'];
        }
        return ['status' => 200, 'headers' => $headers + ['x-rate-limit-remaining' => ['650.0']], 'body' => (string)json_encode($slice)];
    }

    /** The courses the token's owner is enrolled in (GET /api/v1/courses), each with an enrollments list. */
    private function listUserCourses(array $query, string $origin, string $path): array
    {
        $states = $query['state'] ?? ['unpublished', 'available'];
        $matches = [];
        foreach ($this->state['courses'] as $course) {
            if (!in_array($course['workflow_state'], $states, true)) {
                continue;
            }
            // Every course is taught by the token owner except the "No permission" one, where they are a student.
            $type = $course['forbidden'] ? 'student' : 'teacher';
            $matches[] = $this->publicCourse($course) + ['enrollments' => [['type' => $type, 'role' => ucfirst($type) . 'Enrollment']]];
        }
        $perPage = max(1, (int)($query['per_page'] ?? 10));
        $page = max(1, (int)($query['page'] ?? 1));
        $slice = array_slice($matches, ($page - 1) * $perPage, $perPage);
        $headers = [];
        if ($page * $perPage < count($matches)) {
            $next = $query;
            $next['page'] = $page + 1;
            $headers['link'] = ['<' . $origin . $path . '?' . http_build_query($next) . '>; rel="next"'];
        }
        return ['status' => 200, 'headers' => $headers + ['x-rate-limit-remaining' => ['650.0']], 'body' => (string)json_encode($slice)];
    }

    private static function json(int $status, mixed $data): array
    {
        return ['status' => $status, 'headers' => ['x-rate-limit-remaining' => ['650.0']], 'body' => (string)json_encode($data)];
    }
}
