<?php
declare(strict_types=1);

/**
 * Queue worker for Deploy to Canvas. Run it every minute from Windows Task Scheduler
 * (or cron):
 *
 *   php.exe C:\path\to\canvas_automations\bin\worker.php
 *
 * Each run works through the queued deployments for about 45 seconds, taking turns
 * between them, then exits. If the previous run is still going, it exits at once.
 * Options: --budget=SECONDS (default 45, at most 600).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/config.php';

$budget = 45.0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--budget=(\d+)$/', $arg, $match)) {
        $budget = (float)max(5, min(600, (int)$match[1]));
    }
}
// Backstop: a worker that hangs (instead of crashing) would keep the worker lock and make
// every later run skip. Ending it releases the lock. Each step is already capped at 20 s
// by its Canvas deadline, so this only trips if something else gets stuck. (On Windows
// PHP counts real time here; elsewhere it counts CPU time.)
@set_time_limit((int)$budget + 120);

try {
    $summary = $queue->work($budget, 10.0, 20.0);
} catch (\Throwable $exception) {
    fwrite(STDERR, gmdate('c') . ' worker failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
echo gmdate('c'), ' ', json_encode($summary), "\n";
