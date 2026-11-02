# Canvas automations

## Deploy to Canvas

A web page that installs (or removes) the SLO Cloud LTI 1.1 tool in Canvas courses.
There is no login: the person pastes their Canvas API token, the page lists the courses
that token can deploy to (teacher, TA or designer enrollments), and they tick which ones.
A Canvas account-admin token also gets the term / all active / all courses options.

- Dry run first: it reports what would change and writes nothing.
- Courses that already have the tool are left alone, unless "update tools that are already
  in the course" is ticked, which gives them the key and secret of this run.
- The token stays in the browser tab. The server keeps it only encrypted for the length of
  a run, with the key held by the browser. It is never written to disk or the log.
- People enter their own Canvas URL (prefilled from the config). It must be https, and a
  host that resolves to a private or internal address is refused, so the server cannot be
  used to reach its own network. `canvas_allowed_hosts` can limit it further.
- The LTI key and secret in the config are only ever sent to the configured Canvas. For any
  other Canvas the person types their own.
- Rejected tokens are throttled: 10 per address per 15 minutes.
- Every run is logged to `var/audit.log` (who in Canvas, which courses, counts).

### Requirements

PHP 8.2 or newer with the `curl` or `openssl` extension, and `mbstring`.

### Setup

1. Point the web server's document root at `public/`. Nothing outside it should be served.
2. Copy `config.example.php` to `config.local.php`. Optionally set `canvas_base_url`,
   `canvas_allowed_hosts`, and the LTI launch URL, consumer key and shared secret. Without them the
   person running the tool types their own for each run. Environment variables
   (`CANVAS_BASE_URL`, `CANVAS_ALLOWED_HOSTS`, `LTI_LAUNCH_URL`, `LTI_CONSUMER_KEY`, `LTI_CONSUMER_SECRET`,
   `CANVAS_AUTOMATIONS_DATA`) work too.
3. Make `var/` (or `data_dir`) writable by the web server.
4. Set `CANVAS_AUTOMATIONS_ENV=production` in production. Plain http is then refused even for
   loopback Canvas URLs.

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
