<?php
declare(strict_types=1);

namespace CanvasAutomations;

require_once __DIR__ . '/CanvasClient.php';

/**
 * Installs the SLO Cloud LTI 1.1 tool (and an optional module link) in one
 * Canvas course. Every step checks before it writes, so re-running a course,
 * or resuming one interrupted mid-way, never creates duplicates.
 *
 * Settings: launch_url, consumer_key, consumer_secret, link_name,
 * create_module, module_name, dry_run, account_tool, update_existing,
 * create_nav, update_nav, nav_default.
 */
final class CanvasLtiDeployer
{
    public const BLUEPRINT_NOTE = 'Blueprint child course: deploy to its Blueprint course and let sync apply the change.';
    public const NEEDS_CREDENTIALS_NOTE = 'SLO Cloud is not in this course or its account. Enter the LTI consumer key and shared secret to install it here.';

    private ?\Closure $sisInspector;

    public function __construct(
        private CanvasClient $client,
        private array $settings,
        ?callable $sisInspector = null,
    ) {
        $this->sisInspector = $sisInspector !== null ? \Closure::fromCallable($sisInspector) : null;
    }

    /**
     * Canonical form for comparing launch URLs: lowercase scheme and host,
     * default ports dropped, trailing slash removed, query kept.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        $url = trim((string)$url);
        $parts = $url === '' ? false : parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        return $scheme . '://' . strtolower($parts['host'])
            . ($port !== null && $port !== $defaultPort ? ':' . $port : '')
            . $path
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /** The existing tool that already launches SLO Cloud, if any. */
    public static function findMatchingTool(array $tools, string $launchUrl): ?array
    {
        $launch = self::normalizeUrl($launchUrl);
        $host = strtolower((string)parse_url($launchUrl, PHP_URL_HOST));
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $toolUrl = self::normalizeUrl($tool['url'] ?? null);
            if ($toolUrl !== null && $toolUrl === $launch) {
                return $tool;
            }
            $domain = strtolower(trim((string)($tool['domain'] ?? '')));
            if ($toolUrl === null && $domain !== '' && $domain === $host) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Other SLO Cloud installs in the course: same name or consumer key, but a
     * different launch URL (typical after a Canvas test-instance refresh).
     */
    public static function findForeignTools(array $tools, string $launchUrl, string $linkName, string $consumerKey): array
    {
        $match = self::findMatchingTool($tools, $launchUrl);
        $name = mb_strtolower(trim($linkName));
        $foreign = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || ($match !== null && ($tool['id'] ?? null) === ($match['id'] ?? null))) {
                continue;
            }
            if (self::findMatchingTool([$tool], $launchUrl) !== null) {
                continue;
            }
            $sameName = $name !== '' && mb_strtolower(trim((string)($tool['name'] ?? ''))) === $name;
            $sameKey = $consumerKey !== '' && (string)($tool['consumer_key'] ?? '') === $consumerKey;
            if ($sameName || $sameKey) {
                $foreign[] = $tool;
            }
        }
        return $foreign;
    }

    /** "NAME" (id N, URL or domain), for notes. */
    public static function describeTool(array $tool): string
    {
        return '"' . (string)($tool['name'] ?? '') . '" (id ' . (int)($tool['id'] ?? 0) . ', '
            . ((string)($tool['url'] ?? '') !== '' ? (string)$tool['url'] : 'domain ' . (string)($tool['domain'] ?? '')) . ')';
    }

    /**
     * Why a matched tool cannot be trusted as SLO Cloud, or null when it can: matched only
     * by domain, so it may be another app on the same server.
     */
    public static function doubtfulMatch(array $tool): ?string
    {
        if (self::normalizeUrl($tool['url'] ?? null) === null) {
            return 'Matched tool ' . self::describeTool($tool) . ' only by its domain, so it may not be SLO Cloud. Nothing was added; check it in Canvas.';
        }
        return null;
    }

    /** Canvas sends course_navigation as null when the tool has no nav placement. */
    public static function lacksNav(array $tool): bool
    {
        return array_key_exists('course_navigation', $tool) && empty($tool['course_navigation']);
    }

    /**
     * The Course Navigation placement this tool installs: visible to teachers and admins.
     * $default 'disabled' puts the link in the hidden list under Settings > Navigation,
     * where a teacher can turn it on.
     */
    public static function navPlacement(string $linkName, string $default = 'enabled'): array
    {
        return ['enabled' => 'true', 'visibility' => 'admins', 'default' => self::navDefault($default), 'text' => $linkName];
    }

    /** 'enabled' or 'disabled'; anything else counts as 'enabled', as Canvas does. */
    public static function navDefault(mixed $value): string
    {
        return $value === 'disabled' ? 'disabled' : 'enabled';
    }

    /** Whether a tool's existing nav link differs from the one this run would set. */
    public static function navDiffers(array $tool, string $linkName, string $default): bool
    {
        $nav = is_array($tool['course_navigation'] ?? null) ? $tool['course_navigation'] : [];
        $enabled = $nav['enabled'] ?? true;
        return trim((string)($nav['text'] ?? '')) !== trim($linkName)
            || self::navDefault($nav['default'] ?? 'enabled') !== self::navDefault($default)
            || (string)($nav['visibility'] ?? '') !== 'admins'
            || $enabled === false || $enabled === 'false';
    }

    /**
     * What a course already has, without changing anything: app (yes, no, doubtful), nav
     * (yes, no) and module (yes, no). Used to flag courses in the course list.
     */
    public static function inspectCourse(CanvasClient $client, int $courseId, string $launchUrl): array
    {
        $tools = $client->getAll("/api/v1/courses/{$courseId}/external_tools", ['include_parents' => 'true']);
        $match = self::findMatchingTool($tools, $launchUrl);
        $app = $match === null ? 'no' : (self::doubtfulMatch($match) !== null ? 'doubtful' : 'yes');
        $nav = 'no';
        foreach ($tools as $tool) {
            if (is_array($tool) && self::findMatchingTool([$tool], $launchUrl) !== null
                && self::doubtfulMatch($tool) === null && !self::lacksNav($tool)) {
                $nav = 'yes';
                break;
            }
        }
        $launch = self::normalizeUrl($launchUrl);
        $module = 'no';
        foreach ($client->getAll("/api/v1/courses/{$courseId}/modules", ['include' => ['items']]) as $item) {
            $items = is_array($item['items'] ?? null) ? $item['items']
                : $client->getAll("/api/v1/courses/{$courseId}/modules/" . (int)($item['id'] ?? 0) . '/items');
            foreach ($items as $entry) {
                if (is_array($entry) && ($entry['type'] ?? '') === 'ExternalTool' && self::normalizeUrl($entry['external_url'] ?? null) === $launch) {
                    $module = 'yes';
                    break 2;
                }
            }
        }
        return [
            'app' => $app,
            'nav' => $nav,
            'module' => $module,
            'tool' => $match === null ? '' : self::describeTool($match),
        ];
    }

    /** Warnings about an existing SLO Cloud tool that is left alone. */
    public static function toolWarnings(array $tool, string $consumerKey): array
    {
        $warnings = [];
        $toolKey = (string)($tool['consumer_key'] ?? '');
        if ($toolKey !== '' && $consumerKey !== '' && $toolKey !== $consumerKey) {
            $warnings[] = 'The existing SLO Cloud tool uses a different consumer key, so its launches will fail signature checks.';
        }
        $privacy = (string)($tool['privacy_level'] ?? '');
        if ($privacy !== '' && $privacy !== 'public') {
            $warnings[] = 'The existing SLO Cloud tool has privacy level "' . $privacy . '", so names and email are not sent.';
        }
        return $warnings;
    }

    /** Form fields for creating the course-level external tool. */
    /** The course nav placement is included unless create_nav is set to false. */
    public static function toolPayload(array $settings): array
    {
        $payload = [
            'name' => (string)$settings['link_name'],
            'privacy_level' => 'public',
            'consumer_key' => (string)$settings['consumer_key'],
            'shared_secret' => (string)$settings['consumer_secret'],
            'url' => (string)$settings['launch_url'],
        ];
        if ($settings['create_nav'] ?? true) {
            $payload['course_navigation'] = self::navPlacement((string)$settings['link_name'], self::navDefault($settings['nav_default'] ?? 'enabled'));
        }
        return $payload;
    }

    /** Concluded by state, by a course end date that governs access, or by its term's end. */
    public static function isConcluded(array $course, int $now): bool
    {
        if (($course['workflow_state'] ?? '') === 'completed') {
            return true;
        }
        $end = null;
        if (!empty($course['restrict_enrollments_to_course_dates']) && !empty($course['end_at'])) {
            $end = (string)$course['end_at'];
        } elseif (!empty($course['term']['end_at'])) {
            $end = (string)$course['term']['end_at'];
        }
        if ($end === null) {
            return false;
        }
        $timestamp = strtotime($end);
        return $timestamp !== false && $timestamp < $now;
    }

    /** Keep only the course fields a run needs between batches. */
    public static function compactCourse(array $course): array
    {
        $term = is_array($course['term'] ?? null) ? $course['term'] : [];
        return [
            'id' => (int)($course['id'] ?? 0),
            'name' => (string)($course['name'] ?? ''),
            'course_code' => (string)($course['course_code'] ?? ''),
            'sis_course_id' => isset($course['sis_course_id']) ? (string)$course['sis_course_id'] : null,
            'workflow_state' => (string)($course['workflow_state'] ?? ''),
            'end_at' => $course['end_at'] ?? null,
            'restrict_enrollments_to_course_dates' => !empty($course['restrict_enrollments_to_course_dates']),
            'term' => [
                'id' => isset($term['id']) ? (int)$term['id'] : null,
                'name' => (string)($term['name'] ?? ''),
                'end_at' => $term['end_at'] ?? null,
            ],
        ];
    }

    public static function resultRow(array $course): array
    {
        return [
            'course_id' => (int)($course['id'] ?? 0),
            'name' => (string)($course['name'] ?? ''),
            'sis_course_id' => (string)($course['sis_course_id'] ?? ''),
            'term' => (string)($course['term']['name'] ?? ''),
            'tool' => '',
            'nav' => '',
            'module' => '',
            'status' => 'ok',
            'notes' => [],
        ];
    }

    public static function failedRow(array $course, string $message): array
    {
        return ['tool' => 'failed', 'nav' => 'skipped', 'module' => 'skipped', 'status' => 'failed', 'notes' => [$message]]
            + self::resultRow($course);
    }

    public static function blueprintRow(array $course): array
    {
        return ['tool' => 'skipped', 'nav' => 'skipped', 'module' => 'skipped', 'status' => 'skipped', 'notes' => [self::BLUEPRINT_NOTE]]
            + self::resultRow($course);
    }

    /**
     * Check and, unless this is a dry run, install the tool and module link.
     *
     * @throws CanvasDeadlineExceeded when the batch deadline arrives mid-course;
     *         the caller requeues the course and the next attempt resumes safely.
     */
    public function deployCourse(array $course): array
    {
        $row = self::resultRow($course);
        $courseId = $row['course_id'];
        if ($this->sisInspector !== null) {
            foreach (($this->sisInspector)($course['sis_course_id'] ?? null) as $note) {
                $row['notes'][] = (string)$note;
            }
        }

        try {
            $nav = 'skipped';
            $row['tool'] = $this->ensureTool($courseId, $row['notes'], $nav);
            $row['nav'] = $nav;
        } catch (CanvasDeadlineExceeded $exception) {
            throw $exception;
        } catch (CanvasApiException $exception) {
            $row['tool'] = 'failed';
            $row['nav'] = 'skipped';
            $row['module'] = empty($this->settings['create_module']) ? 'off' : 'skipped';
            $row['status'] = 'failed';
            $row['notes'][] = 'Tool step failed: ' . $exception->getMessage();
            return $row;
        }
        if (in_array($row['tool'], ['needs_credentials', 'doubtful'], true)) {
            // No trustworthy tool to link to, so nothing is added to the course.
            $row['module'] = empty($this->settings['create_module']) ? 'off' : 'skipped';
            $row['status'] = 'skipped';
            return $row;
        }

        if (empty($this->settings['create_module'])) {
            $row['module'] = 'off';
        } else {
            try {
                $row['module'] = $this->ensureModuleItem($courseId, $row['notes']);
            } catch (CanvasDeadlineExceeded $exception) {
                throw $exception;
            } catch (CanvasApiException $exception) {
                $row['module'] = 'failed';
                $row['status'] = 'failed';
                $row['notes'][] = 'Module step failed: ' . $exception->getMessage();
                return $row;
            }
        }

        $row['status'] = $row['notes'] === [] ? 'ok' : 'warning';
        return $row;
    }

    /**
     * Remove the course-level tool and the module link to it, unless this is a dry run.
     * A tool installed at the account level cannot be removed from one course, so it is
     * reported instead. An emptied, unpublished module with the configured name goes too.
     *
     * @throws CanvasDeadlineExceeded as deployCourse() does
     */
    public function removeCourse(array $course): array
    {
        $row = self::resultRow($course);
        $courseId = $row['course_id'];
        $launchUrl = (string)$this->settings['launch_url'];
        $dryRun = !empty($this->settings['dry_run']);

        try {
            $own = $this->client->getAll("/api/v1/courses/{$courseId}/external_tools");
            $match = self::findMatchingTool($own, $launchUrl);
            if ($match !== null) {
                if (!$dryRun) {
                    $this->client->delete("/api/v1/courses/{$courseId}/external_tools/" . (int)$match['id']);
                }
                $row['tool'] = $dryRun ? 'would_remove' : 'removed';
            } else {
                $inherited = $this->client->getAll("/api/v1/courses/{$courseId}/external_tools", ['include_parents' => 'true']);
                if (self::findMatchingTool($inherited, $launchUrl) !== null) {
                    $row['tool'] = 'present_account';
                    $row['notes'][] = 'SLO Cloud is installed at the account level, so it cannot be removed from one course. Remove it in Canvas under Admin > Settings > Apps.';
                } else {
                    $row['tool'] = 'absent';
                }
            }
        } catch (CanvasDeadlineExceeded $exception) {
            throw $exception;
        } catch (CanvasApiException $exception) {
            $row['tool'] = 'failed';
            $row['module'] = 'skipped';
            $row['status'] = 'failed';
            $row['notes'][] = 'Tool step failed: ' . $exception->getMessage();
            return $row;
        }

        try {
            $row['module'] = $this->removeModuleItems($courseId, $launchUrl, $dryRun);
        } catch (CanvasDeadlineExceeded $exception) {
            throw $exception;
        } catch (CanvasApiException $exception) {
            $row['module'] = 'failed';
            $row['status'] = 'failed';
            $row['notes'][] = 'Module step failed: ' . $exception->getMessage();
            return $row;
        }

        $row['status'] = $row['notes'] === [] ? 'ok' : 'warning';
        return $row;
    }

    private function removeModuleItems(int $courseId, string $launchUrl, bool $dryRun): string
    {
        $launch = self::normalizeUrl($launchUrl);
        $moduleName = trim((string)($this->settings['module_name'] ?? ''));
        $removed = 0;
        foreach ($this->client->getAll("/api/v1/courses/{$courseId}/modules", ['include' => ['items']]) as $module) {
            $moduleId = (int)($module['id'] ?? 0);
            $items = $module['items'] ?? null;
            if (!is_array($items)) {
                $items = $this->client->getAll("/api/v1/courses/{$courseId}/modules/{$moduleId}/items");
            }
            $remaining = count($items);
            foreach ($items as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'ExternalTool'
                    && self::normalizeUrl($item['external_url'] ?? null) === $launch) {
                    if (!$dryRun) {
                        $this->client->delete("/api/v1/courses/{$courseId}/modules/{$moduleId}/items/" . (int)$item['id']);
                    }
                    $removed++;
                    $remaining--;
                }
            }
            $ours = $moduleName !== '' && strcasecmp(trim((string)($module['name'] ?? '')), $moduleName) === 0;
            if ($removed > 0 && $remaining === 0 && $ours && empty($module['published']) && $moduleId > 0) {
                if (!$dryRun) {
                    $this->client->delete("/api/v1/courses/{$courseId}/modules/{$moduleId}");
                }
            }
        }
        if ($removed === 0) {
            return 'none';
        }
        return $dryRun ? 'would_remove' : 'removed';
    }

    /**
     * Returns what happened to the tool; $nav gets what happened to its Course Navigation
     * link: present, added, would_add, missing or skipped.
     */
    private function ensureTool(int $courseId, array &$notes, string &$nav): string
    {
        $nav = 'skipped';
        // The course's own tools, fetched once when a step needs them.
        $own = null;
        if (!empty($this->settings['account_tool'])) {
            $nav = 'present';
            return 'present_account';
        }
        $launchUrl = (string)$this->settings['launch_url'];
        $tools = $this->client->getAll("/api/v1/courses/{$courseId}/external_tools", ['include_parents' => 'true']);
        $foreignTools = self::findForeignTools(
            $tools,
            $launchUrl,
            (string)$this->settings['link_name'],
            (string)$this->settings['consumer_key']
        );
        foreach ($foreignTools as $foreign) {
            $target = (string)($foreign['url'] ?? '') !== '' ? (string)$foreign['url'] : (string)($foreign['domain'] ?? 'another address');
            $notes[] = 'Another SLO Cloud tool "' . (string)($foreign['name'] ?? '') . '" points to ' . $target
                . '; instructors may see two tabs.';
        }

        $match = self::findMatchingTool($tools, $launchUrl);
        if ($match !== null && ($doubt = self::doubtfulMatch($match)) !== null) {
            $notes[] = $doubt;
            return 'doubtful';
        }
        if ($match !== null && self::lacksNav($match)) {
            // SLO Cloud is there but has no nav link. A tool that belongs to this course can get
            // one (no key or secret needed); one from the account cannot be changed from here.
            $own ??= $this->client->getAll("/api/v1/courses/{$courseId}/external_tools");
            $ownMatch = self::findMatchingTool($own, $launchUrl);
            if ($ownMatch === null) {
                // The run is adding the nav link to the account tool itself (dry run: would).
                if (!empty($this->settings['account_nav_fixed'])) {
                    $nav = !empty($this->settings['dry_run']) ? 'would_add' : 'added';
                    return 'present_account';
                }
                if (empty($this->settings['create_nav'])) {
                    $nav = 'off';
                    return 'present_account';
                }
                // The account tool cannot be changed from a course, so the nav link comes from a
                // course-level copy, made with the key and secret the run requires for "Add navigation".
                $accountKey = (string)($match['consumer_key'] ?? '');
                if ($accountKey !== '' && $accountKey !== (string)($this->settings['consumer_key'] ?? '')) {
                    $notes[] = 'The consumer key entered does not match the account SLO Cloud tool ' . self::describeTool($match)
                        . ', so no nav link was added. Enter the same key and secret as the account tool.';
                    $nav = 'not_added';
                    return 'present_account';
                }
                if (!empty($this->settings['dry_run'])) {
                    $nav = 'would_add';
                    return 'would_create_for_nav';
                }
                $this->client->post("/api/v1/courses/{$courseId}/external_tools", self::toolPayload($this->settings));
                $nav = 'added';
                return 'created_for_nav';
            }
            if (self::lacksNav($ownMatch) && !empty($this->settings['create_nav'])) {
                if (!empty($this->settings['dry_run'])) {
                    $nav = 'would_add';
                    return 'present';
                }
                $this->client->put("/api/v1/courses/{$courseId}/external_tools/" . (int)$ownMatch['id'], [
                    'course_navigation' => self::navPlacement((string)$this->settings['link_name'], $this->navDefaultSetting()),
                ]);
                $nav = 'added';
                return 'present';
            }
            $match = $ownMatch;
        }
        if ($match !== null) {
            $nav = self::lacksNav($match) ? 'off' : 'present';
            if ($nav === 'present' && !empty($this->settings['create_nav']) && !empty($this->settings['update_nav'])) {
                $own ??= $this->client->getAll("/api/v1/courses/{$courseId}/external_tools");
                $nav = $this->updateNav($courseId, self::findMatchingTool($own, $launchUrl), $notes);
            }
        }
        if ($match !== null) {
            if (!empty($this->settings['update_existing'])) {
                // Only a tool that belongs to this course can be changed from it; Canvas never
                // returns the shared secret, so every matching course tool is set again.
                $own ??= $this->client->getAll("/api/v1/courses/{$courseId}/external_tools");
                $ownMatch = self::findMatchingTool($own, $launchUrl);
                if ($ownMatch !== null) {
                    if (!empty($this->settings['dry_run'])) {
                        return 'would_update';
                    }
                    $this->client->put("/api/v1/courses/{$courseId}/external_tools/" . (int)$ownMatch['id'], [
                        'consumer_key' => (string)$this->settings['consumer_key'],
                        'shared_secret' => (string)$this->settings['consumer_secret'],
                    ]);
                    return 'updated';
                }
                $notes[] = 'SLO Cloud comes from the account, so its credentials cannot be updated from this course.';
            }
            array_push($notes, ...self::toolWarnings($match, (string)$this->settings['consumer_key']));
            return 'present';
        }
        if ((string)($this->settings['consumer_key'] ?? '') === '' || (string)($this->settings['consumer_secret'] ?? '') === '') {
            $notes[] = self::NEEDS_CREDENTIALS_NOTE;
            return 'needs_credentials';
        }
        $withNav = !empty($this->settings['create_nav']);
        if (!empty($this->settings['dry_run'])) {
            $nav = $withNav ? 'would_add' : 'off';
            return 'would_create';
        }
        $this->client->post("/api/v1/courses/{$courseId}/external_tools", self::toolPayload($this->settings));
        $nav = $withNav ? 'added' : 'off';
        return 'created';
    }

    private function navDefaultSetting(): string
    {
        return self::navDefault($this->settings['nav_default'] ?? 'enabled');
    }

    /**
     * "Update if found" for the nav link: give the course's own tool this run's link name and
     * shown/hidden default. Returns updated, would_update or present (already the same, or the
     * link comes from the account, which cannot be changed from a course).
     */
    private function updateNav(int $courseId, ?array $ownTool, array &$notes): string
    {
        if ($ownTool === null) {
            $notes[] = 'The nav link comes from the account SLO Cloud tool, so it cannot be updated from this course.';
            return 'present';
        }
        if (self::lacksNav($ownTool)) {
            return 'present';
        }
        $linkName = (string)$this->settings['link_name'];
        $default = $this->navDefaultSetting();
        if (!self::navDiffers($ownTool, $linkName, $default)) {
            return 'present';
        }
        if (!empty($this->settings['dry_run'])) {
            return 'would_update';
        }
        $this->client->put("/api/v1/courses/{$courseId}/external_tools/" . (int)$ownTool['id'], [
            'course_navigation' => self::navPlacement($linkName, $default),
        ]);
        return 'updated';
    }

    private function ensureModuleItem(int $courseId, array &$notes): string
    {
        $launch = self::normalizeUrl((string)$this->settings['launch_url']);
        $moduleName = trim((string)$this->settings['module_name']);
        $modules = $this->client->getAll("/api/v1/courses/{$courseId}/modules", ['include' => ['items']]);

        $named = null;
        foreach ($modules as $module) {
            $items = $module['items'] ?? null;
            if (!is_array($items)) {
                // Canvas omits items for very large modules.
                $items = $this->client->getAll("/api/v1/courses/{$courseId}/modules/" . (int)($module['id'] ?? 0) . '/items');
            }
            foreach ($items as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'ExternalTool'
                    && self::normalizeUrl($item['external_url'] ?? null) === $launch) {
                    return 'present';
                }
            }
            if ($named === null && strcasecmp(trim((string)($module['name'] ?? '')), $moduleName) === 0) {
                $named = $module;
            }
        }

        if ($named !== null && !empty($named['published'])) {
            $notes[] = 'Module "' . $moduleName . '" is published; confirm the SLO Cloud link stays hidden from students.';
        }
        if (!empty($this->settings['dry_run'])) {
            return $named === null ? 'would_create' : 'would_add';
        }

        $created = false;
        if ($named === null) {
            // Canvas creates modules unpublished, so students never see them.
            // Position 1 puts it at the top of the course's modules.
            $named = $this->client->post("/api/v1/courses/{$courseId}/modules", ['module' => ['name' => $moduleName, 'position' => 1]]);
            $created = true;
        }
        $moduleId = (int)(is_array($named) ? ($named['id'] ?? 0) : 0);
        if ($moduleId <= 0) {
            throw new CanvasApiException('Canvas did not return the module that was created.', 0, 'response');
        }
        if (!$created) {
            $this->client->put("/api/v1/courses/{$courseId}/modules/{$moduleId}", ['module' => ['position' => 1]]);
        }
        $this->client->post("/api/v1/courses/{$courseId}/modules/{$moduleId}/items", [
            'module_item' => [
                'type' => 'ExternalTool',
                'title' => (string)$this->settings['link_name'],
                'external_url' => (string)$this->settings['launch_url'],
            ],
        ]);
        return $created ? 'created' : 'added';
    }
}
