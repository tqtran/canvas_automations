<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Shows the raw log lines of failed requests made from this browser session, newest
// first. Only this session's lines: the page has no login, so the whole log is not shown.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$file = $appConfig['data_dir'] . '/audit.log';

// log.php?run=<id>: everything logged about one run, oldest first: when it was queued, each
// step (who took it, how many courses, why it stopped), failures, each course's result, and
// how it ended. Only for runs this browser session may see (see "Your runs").
if (isset($_GET['run'])) {
    $runId = (string)$_GET['run'];
    if (!CanvasAutomations\DeployQueue::validId($runId) || !in_array($runId, (array)($_SESSION['canvas_jobs'] ?? []), true)) {
        http_response_code(403);
        echo "This run was not started from this browser. Connect with the Canvas token that started it, then open its log again.\n";
        exit;
    }
    $found = 0;
    foreach ([$file . '.1', $file] as $source) {
        $handle = is_file($source) ? @fopen($source, 'rb') : false;
        if ($handle === false) {
            continue;
        }
        while (($line = fgets($handle)) !== false) {
            if (!str_contains($line, $runId)) {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry) || ($entry['run_id'] ?? '') !== $runId) {
                continue;
            }
            $at = (string)($entry['at'] ?? '');
            $event = (string)($entry['event'] ?? '');
            unset($entry['at'], $entry['event'], $entry['run_id'], $entry['ip']);
            echo $at, '  ', str_pad($event, 20), ' ', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            $found++;
        }
        fclose($handle);
    }
    if ($found === 0) {
        echo "Nothing logged for this run yet.\n";
    }
    exit;
}

$lines = [];
if (is_file($file) && ($handle = @fopen($file, 'rb')) !== false) {
    $size = (int)filesize($file);
    $chunk = 1024 * 1024;
    if ($size > $chunk) {
        fseek($handle, $size - $chunk);
        fgets($handle); // skip the partial first line
    }
    while (($line = fgets($handle)) !== false) {
        $entry = json_decode($line, true);
        if (is_array($entry) && ($entry['event'] ?? '') === 'request_failed' && ($entry['session'] ?? '') === $sessionTag) {
            $lines[] = rtrim($line, "\r\n");
        }
    }
    fclose($handle);
} elseif (!is_file($file)) {
    echo "No log file yet at the configured data_dir.\n";
    exit;
}

if ($lines === []) {
    echo "No failed requests from this browser session in the log.\n";
    exit;
}
echo 'Failed requests from this browser session, newest first (', count($lines), "):\n\n";
foreach (array_reverse(array_slice($lines, -20)) as $line) {
    echo $line, "\n\n";
}
