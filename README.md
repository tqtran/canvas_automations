# Canvas automations

## Deploy to Canvas

A web page that installs (or removes) the SLO Cloud LTI 1.1 tool in Canvas courses.
There is no login: the person pastes their Canvas API token, the page lists the courses
that token can deploy to (teacher, TA or designer enrollments), and they tick which ones.
A Canvas account-admin token also gets the term / all active / all courses options.

- Dry run first: it reports what would change and writes nothing.
- Courses that already have the tool are left alone, unless "update tools that are already
  in the course" is ticked, which gives them the key and secret of this run.
- Runs go into a queue on the server and are done one at a time, first come, first served:
  a run started while another is going waits its turn, and the page shows how many runs are
  ahead. A scheduled worker moves the queue along; while no worker is running, open pages
  do it instead, each stepping the first run in line (about 5 seconds a step, never over 14,
  inside a gateway's 20-second limit). Either way only one run talks to Canvas at a time.
  With the worker running, the tab can be closed: connecting again with the same Canvas
  token lists that person's runs. Finished runs are kept for 7 days.
- The token stays in the browser tab, and on the server only encrypted for the length of a
  run: with a key of its own, which is in turn encrypted with the queue key. Both are
  deleted when the run ends, and if nothing moves the queue for 2 hours its runs are ended. The token is
  never written to the log.
- People enter their own Canvas URL (prefilled from the config). It must be https, and a
  host that resolves to a private or internal address is refused, so the server cannot be
  used to reach its own network. `canvas_allowed_hosts` can limit it further.
- The LTI key and secret in the config are only ever sent to the configured Canvas. For any
  other Canvas the person types their own.
- Rejected tokens are throttled: 10 per address per 15 minutes.
- Everything a run does is logged to `var/audit.log`, one JSON line per event: who started
  it and with what settings (`run_queued`), each step with who took it (worker or page), how
  many courses, how long, and why it stopped with the numbers behind it (`run_step`), failed
  steps and whether they are retried (`run_step_failed`), each course's result
  (`course_result`), cancels, and how and why the run ended (`run_ended`). Worker runs that
  did something are logged too (`worker_run`). Past 20 MB the log moves to `audit.log.1`.
  On the page, "View this run's log" shows one run's lines (`log.php?run=<id>`).

### Requirements

PHP 8.2 or newer with the `curl` or `openssl` extension, and `mbstring`.

The page uses AdminLTE 4.10 (with Bootstrap 5.3.8), Bootstrap Icons and the Source Sans 3
font, loaded by the browser from cdn.jsdelivr.net with integrity checks; the server itself
does not need to reach the CDN.

### Setup

Step by step for a Windows/IIS server, with commands: [docs/DEPLOY_CHECKLIST.md](docs/DEPLOY_CHECKLIST.md).

1. Point the web server's document root at `public/`. Nothing outside it should be served.
2. Copy `config.example.php` to `config.local.php`. Optionally set `canvas_base_url`,
   `canvas_allowed_hosts`, and the LTI launch URL, consumer key and shared secret. Without them the
   person running the tool types their own for each run. Environment variables
   (`CANVAS_BASE_URL`, `CANVAS_ALLOWED_HOSTS`, `LTI_LAUNCH_URL`, `LTI_CONSUMER_KEY`, `LTI_CONSUMER_SECRET`,
   `CANVAS_AUTOMATIONS_DATA`) work too.
3. Make `var/` (or `data_dir`) writable by the web server.
4. Set `CANVAS_AUTOMATIONS_ENV=production` in production. Plain http is then refused even for
   loopback Canvas URLs.
5. Set up the queue worker (below).
6. On IIS, keep `public/web.config`. It sets `httpErrors existingResponse="PassThrough"` so
   the app's own error messages reach the page; without it IIS replaces them with "The custom
   error module does not recognize this error."

### Queue worker

Have Windows Task Scheduler (or cron) start the worker **every minute**. Each start works
through the queue for about 45 seconds and exits; if the last one is still going, the new one
exits at once. Either way works:

The quickest setup: in an elevated PowerShell on the web server, run

```powershell
.\bin\install-task.ps1
```

It runs the worker once to check the settings, then creates the task "Deploy to Canvas queue
worker" (every minute, as SYSTEM; `-User DOMAIN\account` to use another account,
`-PhpPath` if php.exe is not on PATH, `-Mode Url -Url localhost/cron.php` for the web
worker, `-Uninstall` to remove it). The task runs `bin\run-worker.ps1`, which you can also
run by hand; each run adds one line to `var\worker.log`.

To set the task up yourself instead:

- **php.exe (recommended).** Runs outside IIS and the gateway, so no web timeouts apply.
  Action: `C:\PHP\php.exe`, arguments: `C:\path\to\canvas_automations\bin\worker.php`.
  Run it as an account that can write to `var/` (or `data_dir`).
- **A URL.** Set `worker_key` in `config.local.php`, then have the task call the server
  itself, so the request skips the Azure gateway:

  ```powershell
  Invoke-WebRequest -UseBasicParsing -Headers @{ 'X-Worker-Key' = '<worker_key>' } https://localhost/cron.php
  ```

  If the site's certificate does not cover `localhost`, use the name it does cover with a
  hosts-file entry pointing at 127.0.0.1, or use php.exe instead.
  `?key=<worker_key>` works too, but ends up in the IIS access log. Each call takes up to
  about a minute, inside IIS's FastCGI limits.

In Task Scheduler: trigger daily, repeat every 1 minute indefinitely; under Settings, "If the
task is already running: Do not start a new instance". The page's Server checks card shows
whether the worker has checked in during the last few minutes.

### Tests

```bash
php tests/canvas_deploy_test.php
```

For a browser check against a fake Canvas (accepts only the token `mock-canvas-token-0001`):

```bash
php -S 127.0.0.1:8768 tests/fixtures/mock_canvas_server.php
```

Then set `canvas_base_url` to `http://127.0.0.1:8768` in `config.local.php` and run
`php -S 127.0.0.1:8767 -t public`.
