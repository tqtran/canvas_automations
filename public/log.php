<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Shows the raw log lines of failed requests made from this browser session, newest
// first. Only this session's lines: the page has no login, so the whole log is not shown.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$file = $appConfig['data_dir'] . '/audit.log';
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
