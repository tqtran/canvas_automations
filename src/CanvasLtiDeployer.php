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
 * create_module, module_name, dry_run, account_tool, update_existing.
 */
final class CanvasLtiDeployer
{
    public const BLUEPRINT_NOTE = 'Blueprint child course: deploy to its Blueprint course and let sync apply the change.';

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
    public static function toolPayload(array $settings): array
    {
        return [
            'name' => (string)$settings['link_name'],
            'privacy_level' => 'public',
            'consumer_key' => (string)$settings['consumer_key'],
            'shared_secret' => (string)$settings['consumer_secret'],
            'url' => (string)$settings['launch_url'],
            'course_navigation' => [
                'enabled' => 'true',
                'visibility' => 'admins',
                'default' => 'enabled',
                'text' => (string)$settings['link_name'],
            ],
        ];
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
            'module' => '',
            'status' => 'ok',
            'notes' => [],
        ];
    }

    public static function failedRow(array $course, string $message): array
    {
        return ['tool' => 'failed', 'module' => 'skipped', 'status' => 'failed', 'notes' => [$message]]
            + self::resultRow($course);
    }

    public static function blueprintRow(array $course): array
    {
        return ['tool' => 'skipped', 'module' => 'skipped', 'status' => 'skipped', 'notes' => [self::BLUEPRINT_NOTE]]
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
            $row['tool'] = $this->ensureTool($courseId, $row['notes']);
        } catch (CanvasDeadlineExceeded $exception) {
            throw $exception;
        } catch (CanvasApiException $exception) {
            $row['tool'] = 'failed';
            $row['module'] = empty($this->settings['create_module']) ? 'off' : 'skipped';
            $row['status'] = 'failed';
            $row['notes'][] = 'Tool step failed: ' . $exception->getMessage();
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

    private function ensureTool(int $courseId, array &$notes): string
    {
        if (!empty($this->settings['account_tool'])) {
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
        if ($match !== null) {
            if (!empty($this->settings['update_existing'])) {
                // Only a tool that belongs to this course can be changed from it; Canvas never
                // returns the shared secret, so every matching course tool is set again.
                $own = $this->client->getAll("/api/v1/courses/{$courseId}/external_tools");
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
        if (!empty($this->settings['dry_run'])) {
            return 'would_create';
        }
        $this->client->post("/api/v1/courses/{$courseId}/external_tools", self::toolPayload($this->settings));
        return 'created';
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
            $named = $this->client->post("/api/v1/courses/{$courseId}/modules", ['module' => ['name' => $moduleName]]);
            $created = true;
        }
        $moduleId = (int)(is_array($named) ? ($named['id'] ?? 0) : 0);
        if ($moduleId <= 0) {
            throw new CanvasApiException('Canvas did not return the module that was created.', 0, 'response');
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
