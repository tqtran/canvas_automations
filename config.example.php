<?php
// Copy to config.local.php (not committed) and fill in. Environment variables with the
// names in brackets are used for any key left out.
return [
    // Optional. Fills in the Canvas URL box; people can change it [CANVAS_BASE_URL].
    // The LTI settings below are only ever sent to this Canvas.
    'canvas_base_url' => 'https://college.instructure.com',

    // Optional. Limit which Canvas sites people may connect to [CANVAS_ALLOWED_HOSTS,
    // comma separated]. Empty means any public https host.
    // 'canvas_allowed_hosts' => ['*.instructure.com'],

    // Optional. Used for the Canvas above when the person running the tool leaves the LTI boxes blank
    // [LTI_LAUNCH_URL, LTI_CONSUMER_KEY, LTI_CONSUMER_SECRET].
    'lti_launch_url' => '',
    'lti_consumer_key' => '',
    'lti_consumer_secret' => '',

    // Where the run lock, throttle files and audit.log go [CANVAS_AUTOMATIONS_DATA].
    // Keep it outside public/.
    // 'data_dir' => __DIR__ . '/var',

    // Encrypts the run keys kept in the run queue, so the worker can finish a run after the
    // browser closes [CANVAS_QUEUE_KEY]. 64 hex characters. If left out, one is generated
    // into data_dir/queue.key. Keeping it here instead means the queue files alone are not
    // enough to read a token.
    // 'queue_key' => '',

    // Secret for public/cron.php, the worker address for a scheduled task that calls a URL
    // [CANVAS_WORKER_KEY]. 16 characters or more. Leave empty to turn that address off;
    // bin/worker.php works without it.
    // 'worker_key' => '',

    // Optional form defaults.
    // 'canvas.link_name' => 'Course Reports',
    // 'canvas.module_name' => 'Course Reports',
];
