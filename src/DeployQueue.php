<?php
declare(strict_types=1);

namespace CanvasAutomations;

require_once __DIR__ . '/CanvasDeployRun.php';

/**
 * A shared queue of deployment runs, one JSON file per run in $dir.
 *
 * A run moves forward in short steps. Steps come from the scheduled worker (bin/worker.php
 * or public/cron.php), and from the page watching the run when no worker has checked in
 * lately. Each run has its own lock file, so two steps never work on the same run at once.
 *
 * The Canvas token stays encrypted with the run's own key, as in CanvasDeployRun. That key
 * is kept here encrypted with the queue key, so the worker can continue without the browser.
 * Both the token and the keys are deleted as soon as the run ends.
 */
final class DeployQueue
{
    public const ACTIVE = ['queued', 'running'];
    /** A run nothing has moved for this long is ended and its token deleted. */
    public const STALE_SECONDS = 7200;
    /** Finished runs (results only, no token) are kept this long for review and CSV. */
    public const KEEP_SECONDS = 7 * 86400;
    /** Consecutive failed steps before a run is stopped. */
    public const MAX_ERRORS = 5;
    /** The page helps only when the worker has not checked in for this long. */
    public const WORKER_FRESH_SECONDS = 150;
    /** Most result rows sent in one view. */
    public const MAX_ROWS_PER_VIEW = 500;

    private \Closure $engine;
    private \Closure $clock;
    private ?\Closure $sleeper;
    private ?\Closure $audit;

    /**
     * @param callable $engine fn(array &$store): CanvasDeployRun, already in queue mode
     * @param callable|null $audit fn(string $event, array $data): writes one audit.log line.
     *                             Every decision is logged: each step and why it stopped,
     *                             failures and retries, cancels, and how each run ended.
     */
    public function __construct(
        private string $dir,
        private string $queueKey,
        callable $engine,
        ?callable $clock = null,
        ?callable $sleeper = null,
        ?callable $audit = null,
    ) {
        $this->engine = \Closure::fromCallable($engine);
        $this->audit = $audit !== null ? \Closure::fromCallable($audit) : null;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
        $this->sleeper = $sleeper !== null ? \Closure::fromCallable($sleeper) : null;
    }

    /**
     * The configured queue key, or one generated once and kept in the data directory.
     */
    public static function key(string $configured, string $dataDir): string
    {
        if (preg_match('/^[0-9a-f]{64}$/i', trim($configured))) {
            return strtolower(trim($configured));
        }
        $file = rtrim($dataDir, '/\\') . '/queue.key';
        $existing = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        if (preg_match('/^[0-9a-f]{64}$/i', $existing)) {
            return strtolower($existing);
        }
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new \RuntimeException('Cannot create the data directory for the queue key.');
        }
        $key = Encryption::generateKey();
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            // Another request made it first.
            $existing = trim((string)@file_get_contents($file));
            if (preg_match('/^[0-9a-f]{64}$/i', $existing)) {
                return strtolower($existing);
            }
            throw new \RuntimeException('Cannot write the queue key file.');
        }
        fwrite($handle, $key);
        fclose($handle);
        @chmod($file, 0600);
        return $key;
    }

    /**
     * Queue a run that CanvasDeployRun::start() just set up in $store.
     *
     * @param array $started the start() reply (run_id, run_key, notes, origin, canvas_user)
     */
    public function create(array $store, array $started): array
    {
        $this->ensureDir();
        $now = $this->nowInt();
        $run = $store['run'];
        $job = [
            'id' => (string)$run['id'],
            'status' => 'queued',
            'created_at' => $now,
            'last_step_at' => 0,
            'finished_at' => 0,
            'owner' => [
                'origin' => (string)($started['origin'] ?? ''),
                'user_id' => (int)($started['canvas_user']['id'] ?? 0),
                'user_name' => (string)($started['canvas_user']['name'] ?? ''),
            ],
            'settings' => self::publicSettings($run['settings']),
            'notes' => array_values((array)($started['notes'] ?? [])),
            'counts' => $run['counts'],
            'results' => [],
            'errors' => 0,
            'message' => '',
            'store' => $store,
            'run_key' => (new Encryption($this->queueKey))->encrypt((string)$started['run_key']),
        ];
        $this->write($job);
        $this->log('run_queued', $job, [
            'canvas' => $job['owner']['origin'],
            'canvas_user' => $job['owner']['user_name'] . ' (' . $job['owner']['user_id'] . ')',
            'settings' => $job['settings'],
        ]);
        return $job;
    }

    /**
     * Move one run forward by one step. Returns 'stepped', 'busy' (another step holds it),
     * 'final' (already ended) or 'missing'.
     */
    public function step(string $id, float $timeTarget, float $hardDeadline, string $by = 'worker'): string
    {
        if (!self::validId($id)) {
            return 'missing';
        }
        $lock = $this->lock($id);
        if ($lock === null) {
            $this->log('run_step_skipped', ['id' => $id], ['by' => $by, 'reason' => 'Another step is working on this run']);
            return 'busy';
        }
        try {
            $job = $this->read($id);
            if ($job === null) {
                return 'missing';
            }
            if (!in_array($job['status'], self::ACTIVE, true)) {
                return 'final';
            }
            $runKey = $this->runKey($job);
            if ($runKey === null) {
                $this->finalize($job, 'failed', 'The run could not be read with this server\'s queue key.', null, $by);
                $this->write($job);
                return 'stepped';
            }
            if (is_file($this->cancelPath($id))) {
                $this->finalize($job, 'cancelled', 'Cancelled at the start of a step.', $runKey, $by);
                $this->write($job);
                return 'stepped';
            }

            $store = $job['store'];
            $engine = ($this->engine)($store);
            try {
                $batch = $engine->batch($id, $runKey, count($job['results']), $timeTarget, $hardDeadline);
                array_push($job['results'], ...array_slice($batch['results'], count($job['results']) - (int)$batch['results_from']));
                $job['store'] = $store;
                $job['counts'] = $batch['counts'];
                $job['course_seconds'] = $batch['course_seconds'];
                $job['remaining'] = $batch['remaining'];
                $job['status'] = 'running';
                $job['errors'] = 0;
                $job['message'] = '';
                $this->log('run_step', $job, [
                    'by' => $by,
                    'courses' => $batch['processed'],
                    'seconds' => round($batch['elapsed_ms'] / 1000, 3),
                    'target' => $timeTarget,
                    'deadline' => $hardDeadline,
                    'stopped' => $batch['stop_reason'],
                    'why' => $batch['stop_detail'] ?? [],
                    'course_seconds_avg' => $batch['course_seconds'],
                    'course_seconds_max' => $store['run']['course_seconds_max'] ?? null,
                    'remaining' => $batch['remaining'],
                    'processed_total' => $batch['counts']['processed'] ?? 0,
                ]);
                if ($batch['done']) {
                    $this->finalize($job, 'done', '', $runKey, $by);
                }
            } catch (CanvasDeployException | CanvasApiException $exception) {
                // The engine keeps rows it recorded before the error; the next step sends them.
                $job['store'] = $store;
                $job['counts'] = $store['run']['counts'] ?? $job['counts'];
                $job['errors']++;
                $job['message'] = $exception->getMessage();
                $fatal = ($exception instanceof CanvasApiException && $exception->status() === 401)
                    || ($exception instanceof CanvasDeployException && in_array($exception->errorCode(), ['run_missing', 'run_key_rejected', 'run_forbidden'], true));
                $stopping = $fatal || $job['errors'] >= self::MAX_ERRORS;
                $this->log('run_step_failed', $job, [
                    'by' => $by,
                    'error' => $exception instanceof CanvasDeployException ? $exception->errorCode() : 'canvas_' . $exception->kind(),
                    'message' => $exception->getMessage(),
                    'failures_in_a_row' => $job['errors'],
                    'max_failures' => self::MAX_ERRORS,
                    'then' => $stopping ? ($fatal ? 'Stopping the run: this error cannot be retried' : 'Stopping the run: too many failures in a row') : 'Trying again next step',
                ]);
                if ($stopping) {
                    $this->finalize($job, 'failed', $fatal && $exception instanceof CanvasApiException
                        ? 'Canvas refused the token (it may have expired or been deleted): ' . $exception->getMessage()
                        : 'Stopped after ' . $job['errors'] . ' failed attempts in a row. Last error: ' . $exception->getMessage(), $runKey, $by);
                }
            }
            $job['last_step_at'] = $this->nowInt();
            $this->write($job);
            return 'stepped';
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * The worker: take turns stepping every active run until $budget seconds have passed.
     * Only one worker runs at a time; a second one returns at once.
     */
    public function work(float $budget, float $timeTarget, float $hardDeadline): array
    {
        $this->ensureDir();
        $handle = @fopen($this->dir . '/worker.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }
            $this->log('worker_skipped', null, ['reason' => 'Another worker is still running']);
            return ['worker' => 'busy', 'steps' => 0, 'runs' => 0];
        }
        $started = $this->now();
        $steps = 0;
        $runs = [];
        $ended = 'Its time budget was used';
        try {
            $this->heartbeat();
            $this->maintain();
            while (($left = $budget - ($this->now() - $started)) > 1) {
                $active = array_filter($this->all(), static fn(array $job): bool => in_array($job['status'], self::ACTIVE, true));
                // Longest-waiting run first, so every run gets turns.
                usort($active, static fn(array $a, array $b): int => [$a['last_step_at'], $a['created_at']] <=> [$b['last_step_at'], $b['created_at']]);
                $moved = false;
                foreach ($active as $job) {
                    $left = $budget - ($this->now() - $started);
                    if ($left <= 1) {
                        break;
                    }
                    if ($this->step($job['id'], min($timeTarget, $left), $hardDeadline) === 'stepped') {
                        $moved = true;
                        $steps++;
                        $runs[$job['id']] = true;
                    }
                    $this->heartbeat();
                }
                if (!$moved) {
                    $ended = $active === [] ? 'No runs waiting' : 'Every waiting run was being worked on elsewhere';
                    break;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        // An idle worker (nothing queued) is not logged every minute; worker.json shows it ran.
        if ($steps > 0 || $ended !== 'No runs waiting') {
            $this->log('worker_run', null, ['budget' => $budget, 'steps' => $steps, 'runs' => count($runs), 'seconds' => round($this->now() - $started, 1), 'ended' => $ended]);
        }
        return ['worker' => 'ran', 'steps' => $steps, 'runs' => count($runs), 'seconds' => round($this->now() - $started, 1)];
    }

    /** Ask a run to stop. It stops now if no step holds it, otherwise at its next step. */
    public function cancel(string $id): void
    {
        if (!self::validId($id) || !is_file($this->path($id))) {
            return;
        }
        @touch($this->cancelPath($id));
        $lock = $this->lock($id);
        if ($lock === null) {
            $this->log('run_cancel_requested', ['id' => $id], ['then' => 'A step is running; the run stops when it ends']);
            return;
        }
        try {
            $job = $this->read($id);
            if ($job !== null && in_array($job['status'], self::ACTIVE, true)) {
                $this->log('run_cancel_requested', $job, ['then' => 'Stopping now']);
                $this->finalize($job, 'cancelled', 'Cancelled from the page.', $this->runKey($job), 'page');
                $this->write($job);
            }
        } finally {
            $this->unlock($lock);
        }
    }

    /** What the page shows for one run: status, counts, and result rows from row $from. */
    public function view(string $id, int $from = 0): ?array
    {
        $job = self::validId($id) ? $this->read($id) : null;
        if ($job === null) {
            return null;
        }
        $from = max(0, min($from, count($job['results'])));
        $active = in_array($job['status'], self::ACTIVE, true);
        $others = 0;
        if ($active) {
            foreach ($this->all() as $other) {
                if ($other['id'] !== $job['id'] && in_array($other['status'], self::ACTIVE, true)) {
                    $others++;
                }
            }
        }
        return self::summary($job) + [
            'cancelling' => $active && is_file($this->cancelPath($id)),
            'other_runs' => $others,
            'notes' => $job['notes'],
            'results_from' => $from,
            'results' => array_slice($job['results'], $from, self::MAX_ROWS_PER_VIEW),
            'worker_seen' => $this->workerSeen(),
            'worker_fresh' => $this->workerFresh(),
            // The server's clock, so the page can show elapsed times despite clock differences.
            'now' => $this->nowInt(),
        ];
    }

    /** Recent runs started by one Canvas user, newest first, without result rows. */
    public function owned(string $origin, int $userId, int $limit = 10): array
    {
        $mine = array_filter($this->all(), static fn(array $job): bool
            => $userId > 0 && $job['owner']['user_id'] === $userId && hash_equals(strtolower($job['owner']['origin']), strtolower($origin)));
        usort($mine, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return array_map([self::class, 'summary'], array_slice(array_values($mine), 0, $limit));
    }

    /** @return list<array> summaries of the given run ids that still exist */
    public function summaries(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $job = self::validId((string)$id) ? $this->read((string)$id) : null;
            if ($job !== null) {
                $out[] = self::summary($job);
            }
        }
        usort($out, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return $out;
    }

    /** Unix time the worker last checked in, or null if it never has. */
    public function workerSeen(): ?int
    {
        $data = json_decode((string)@file_get_contents($this->dir . '/worker.json'), true);
        return is_array($data) && isset($data['seen_at']) ? (int)$data['seen_at'] : null;
    }

    public function workerFresh(): bool
    {
        $seen = $this->workerSeen();
        return $seen !== null && $this->nowInt() - $seen <= self::WORKER_FRESH_SECONDS;
    }

    /** End runs nothing has moved for STALE_SECONDS, and delete old finished runs. */
    public function maintain(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $now = $this->nowInt();
        foreach ($this->all() as $job) {
            $active = in_array($job['status'], self::ACTIVE, true);
            if ($active && $now - max($job['last_step_at'], $job['created_at']) > self::STALE_SECONDS) {
                $lock = $this->lock($job['id']);
                if ($lock === null) {
                    continue;
                }
                try {
                    $fresh = $this->read($job['id']);
                    if ($fresh !== null && in_array($fresh['status'], self::ACTIVE, true)) {
                        $this->finalize($fresh, 'expired', 'Nothing moved this run for 2 hours (no worker running and no page open), so it was stopped and its token deleted. Start it again; courses already done are skipped.', $this->runKey($fresh), 'maintenance');
                        $this->write($fresh);
                    }
                } finally {
                    $this->unlock($lock);
                }
            } elseif (!$active && $now - $job['finished_at'] > self::KEEP_SECONDS) {
                $this->log('run_deleted', $job, ['reason' => 'Finished more than 7 days ago']);
                foreach ([$this->path($job['id']), $this->cancelPath($job['id']), $this->dir . '/' . $job['id'] . '.lock'] as $file) {
                    @unlink($file);
                }
            }
        }
        foreach (glob($this->dir . '/*.tmp') ?: [] as $tmp) {
            if ($now - (int)@filemtime($tmp) > 3600) {
                @unlink($tmp);
            }
        }
    }

    public static function validId(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{32}$/', $id);
    }

    private static function summary(array $job): array
    {
        return [
            'id' => $job['id'],
            'status' => $job['status'],
            'created_at' => $job['created_at'],
            'last_step_at' => $job['last_step_at'],
            'finished_at' => $job['finished_at'],
            'user_name' => $job['owner']['user_name'],
            'settings' => $job['settings'],
            'counts' => $job['counts'],
            'results_total' => count($job['results']),
            'errors' => $job['errors'],
            'message' => $job['message'],
            // This run's average seconds per course, and courses left when that is known.
            'course_seconds' => $job['course_seconds'] ?? null,
            'remaining' => in_array($job['status'], self::ACTIVE, true) ? ($job['remaining'] ?? null) : 0,
        ];
    }

    /** Settings safe to show on the page (there are no secrets in them). */
    private static function publicSettings(array $settings): array
    {
        return array_intersect_key($settings, array_flip([
            'base_url', 'operation', 'dry_run', 'scope', 'scope_value', 'link_name', 'create_nav',
            'create_module', 'module_name', 'update_existing', 'fix_account_nav', 'course_delay_ms',
        ]));
    }

    /** End a run: close it in the engine (audit line) and delete the token and keys. */
    private function finalize(array &$job, string $status, string $message, ?string $runKey = null, string $by = 'worker'): void
    {
        if ($runKey !== null && isset($job['store']['run'])) {
            $store = $job['store'];
            try {
                $closed = ($this->engine)($store)->finish($job['id'], $runKey, $status !== 'done');
                $job['counts'] = $closed['counts'] ?? $job['counts'];
            } catch (\Throwable) {
                // The run is ended either way.
            }
        }
        $job['status'] = $status;
        $job['finished_at'] = $this->nowInt();
        if ($message !== '') {
            $job['message'] = $message;
        }
        $job['store'] = null;
        $job['run_key'] = null;
        @unlink($this->cancelPath($job['id']));
        $this->log('run_ended', $job, [
            'by' => $by,
            'status' => $status,
            'reason' => $message !== '' ? $message : ($status === 'done' ? 'Every course was checked' : ''),
            'counts' => $job['counts'],
            'token_deleted' => true,
        ]);
    }

    /** One audit.log line about a run (or the worker when $job is null). */
    private function log(string $event, ?array $job, array $data): void
    {
        if ($this->audit === null) {
            return;
        }
        $base = $job === null ? [] : ['run_id' => $job['id']];
        try {
            ($this->audit)($event, $base + $data);
        } catch (\Throwable) {
            // Logging never stops a run.
        }
    }

    private function runKey(array $job): ?string
    {
        try {
            return (new Encryption($this->queueKey))->decrypt((string)$job['run_key']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array> every readable run */
    private function all(): array
    {
        $jobs = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (self::validId($id) && ($job = $this->read($id)) !== null) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    private function read(string $id): ?array
    {
        $file = $this->path($id);
        // A write in progress on Windows can briefly block reading; try again.
        for ($try = 0; $try < 5; $try++) {
            if (!is_file($file)) {
                return null;
            }
            $job = json_decode((string)@file_get_contents($file), true);
            if (is_array($job) && isset($job['id'], $job['status'])) {
                return $job;
            }
            $this->pause(0.05);
        }
        return null;
    }

    /** Write via a temporary file and rename, so readers never see half a file. */
    private function write(array $job): void
    {
        $this->ensureDir();
        $file = $this->path($job['id']);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException('Cannot write to the queue directory.');
        }
        @chmod($tmp, 0600);
        // On Windows a rename fails while someone is reading the old file; try again.
        for ($try = 0; $try < 20; $try++) {
            if (@rename($tmp, $file)) {
                return;
            }
            $this->pause(0.05);
        }
        @unlink($tmp);
        throw new \RuntimeException('Cannot save the run in the queue directory.');
    }

    /** @return resource|null the held lock, or null if another step holds it */
    private function lock(string $id)
    {
        $this->ensureDir();
        $handle = @fopen($this->dir . '/' . $id . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function heartbeat(): void
    {
        @file_put_contents($this->dir . '/worker.json', json_encode(['seen_at' => $this->nowInt()]), LOCK_EX);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the queue directory ' . $this->dir . '.');
        }
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function cancelPath(string $id): string
    {
        return $this->dir . '/' . $id . '.cancel';
    }

    private function pause(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
        } else {
            usleep((int)round($seconds * 1_000_000));
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    private function nowInt(): int
    {
        return (int)floor($this->now());
    }
}
