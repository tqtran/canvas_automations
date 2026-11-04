<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use CanvasAutomations\CanvasClient;
use CanvasAutomations\CanvasDeployRun;

// No login is needed to open this page: the Canvas token is the credential.
$lti = [
    'launch_url' => $appConfig['lti.launch_url'],
    'consumer_key' => $appConfig['lti.consumer_key'],
    'consumer_secret' => $appConfig['lti.consumer_secret'],
];
$canvasDeploy = [
    'apiUrl' => 'api.php',
    'csrfToken' => (string)$_SESSION['csrf_token'],
    'defaults' => CanvasDeployRun::defaultsFromConfig($configGet),
    'prerequisites' => CanvasDeployRun::prerequisites($lti),
];
// Only outbound HTTPS is required. The LTI settings are optional: any the user leaves blank
// come from the server configuration, when it has them.
$canvasDeploy['ready'] = CanvasClient::availableTransport() !== null;
$cdServerLti = $lti['launch_url'] !== '' && $lti['consumer_key'] !== '' && $lti['consumer_secret'] !== '';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deploy to Canvas</title>
    <!-- AdminLTE 4 (includes Bootstrap 5.3 styles), its font, and Bootstrap Icons. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha384-oXLoUoB5XFLG2VXaPgirj0OzzF7JWUf+9DRdQ4TnfpOxIdWCy0EbfR8h9WeYPvK9" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/css/adminlte.min.css" integrity="sha384-UqRR9PaoK16nbf42Sqcd4FbL2CkbpbZuBJK7HfJZBUhZINJzdCZG/6lFkXWJZoB/" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Keep cards clear of the sticky header when the page scrolls to them. */
        .app-content .card { scroll-margin-top: 4.5rem; }
        /* The floating "Your runs" panel, bottom right (along the bottom on a phone). */
        .runs-panel { position: fixed; right: 1rem; bottom: 1rem; width: 380px; max-width: calc(100vw - 2rem); z-index: 1034; margin: 0; }
        .runs-panel #cdRunsBody { max-height: min(55vh, 520px); overflow-y: auto; }
        .runs-panel.is-folded #cdRunsBody { display: none; }
        .runs-panel #cdRunsToggle .bi { display: inline-block; transition: transform .2s; }
        .runs-panel.is-folded #cdRunsToggle .bi { transform: rotate(180deg); }
        .app-content { padding-bottom: 6rem; }
        @media (max-width: 575.98px) {
            .runs-panel { left: .5rem; right: .5rem; bottom: .5rem; width: auto; max-width: none; }
        }
    </style>
</head>
<body class="layout-fixed fixed-header bg-body-tertiary">
<div class="app-wrapper">
<nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-cloud-upload text-primary" aria-hidden="true"></i>
            <span>SLO Cloud</span>
        </span>
    </div>
</nav>
<main class="app-main" id="cdTop">
<!-- canvas-deploy:view -->
<?php
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$cdDefaults = $canvasDeploy['defaults'];
$cdScopeValue = (string)$cdDefaults['scope_value'];
$cdWorkerSeen = $queue->workerSeen();
// The data folder holds the queue, its key and the logs. Test it by writing a file: on
// Windows is_writable() looks only at the read-only flag, not at folder permissions.
$cdDataDir = (string)$appConfig['data_dir'];
$cdProbe = rtrim($cdDataDir, '/\\') . '/.write-test-' . bin2hex(random_bytes(4));
$cdDataOk = (is_dir($cdDataDir) || @mkdir($cdDataDir, 0700, true)) && @file_put_contents($cdProbe, 'ok') !== false;
if ($cdDataOk) {
    @unlink($cdProbe);
}
$cdWorkerFresh = $queue->workerFresh();
?>
<div class="app-content-header">
    <div class="container-fluid">
        <h1 class="h3 mb-0">Deploy to Canvas</h1>
    </div>
</div>
<div class="app-content">
<div class="container-fluid">
<p class="lead">Install the SLO Cloud LTI 1.1 tool in your Canvas courses. Connect with your Canvas token, pick courses, and run. It's safe to run again: courses only get what they're missing.</p>

<div class="row g-4">
    <div class="col-xl-8">
        <form id="canvasDeployForm" novalidate>
            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">1</span> What should this run do?</h2>
                </div>
                <div class="card-body">
                    <fieldset class="border-0 p-0 m-0">
                        <legend class="visually-hidden">What should this run do?</legend>
                        <div class="btn-group" role="group" aria-label="Install or remove">
                            <input class="btn-check" type="radio" name="operation" id="cdOpDeploy" value="deploy" checked>
                            <label class="btn btn-outline-primary" for="cdOpDeploy"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Install SLO Cloud</label>
                            <input class="btn-check" type="radio" name="operation" id="cdOpRemove" value="remove">
                            <label class="btn btn-outline-danger" for="cdOpRemove"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove SLO Cloud</label>
                        </div>
                        <div class="form-text" id="cdOpHelp">Remove deletes the course tool and its module link, and the module itself if that leaves it empty and unpublished. Account-level tools are reported, not removed.</div>
                    </fieldset>
                    <hr>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdLtiOwn" <?= $cdServerLti ? '' : 'checked disabled' ?>>
                        <label class="form-check-label" for="cdLtiOwn">Enter the SLO Cloud LTI settings for this run</label>
                    </div>
                    <p class="form-text" id="cdLtiHelp"><?= $cdServerLti
                        ? 'Leave unticked to use the settings saved on this server. Tick to enter your own for this run. Nothing is saved.'
                        : 'Install needs all three. Remove needs only the launch URL. Nothing is saved.' ?></p>
                    <div id="cdLtiFields" class="<?= $cdServerLti ? 'd-none' : '' ?>">
                    <div class="mb-3">
                        <label for="cdLtiUrl" class="form-label">LTI launch URL</label>
                        <input type="text" inputmode="url" class="form-control" id="cdLtiUrl" name="lti_launch_url" autocomplete="off" spellcheck="false"
                            placeholder="https://cloud.coastline.edu/SLO" aria-describedby="cdLtiUrlError">
                        <div id="cdLtiUrlError" class="invalid-feedback"></div>
                    </div>
                    <div class="row g-3" id="cdLtiKeyRow">
                        <div class="col-md-6">
                            <label for="cdLtiKey" class="form-label">Consumer key</label>
                            <input type="text" class="form-control" id="cdLtiKey" name="lti_consumer_key" autocomplete="off" spellcheck="false" aria-describedby="cdLtiKeyError">
                            <div id="cdLtiKeyError" class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label for="cdLtiSecret" class="form-label">Shared secret</label>
                            <input type="password" class="form-control" id="cdLtiSecret" name="lti_consumer_secret" autocomplete="off" spellcheck="false" aria-describedby="cdLtiSecretError">
                            <div id="cdLtiSecretError" class="invalid-feedback"></div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">2</span> Connect to Canvas</h2>
                </div>
                <div class="card-body">
                    <div class="d-none" id="cdConnectedBar">
                        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0">
                            <span id="cdConnectedText" role="status" aria-live="polite"><i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i><strong>Connected</strong></span>
                            <button type="button" class="btn btn-outline-success btn-sm" id="cdLogout" aria-describedby="cdLogoutHelp"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Log out</button>
                        </div>
                        <div class="form-text" id="cdLogoutHelp">Log out clears your token, the shared secret and this browser's list of runs. Runs in progress keep going.</div>
                    </div>
                    <div id="cdConnectFields">
                    <div class="mb-3">
                        <label for="cdBaseUrl" class="form-label">Canvas URL</label>
                        <input type="text" inputmode="url" class="form-control" id="cdBaseUrl" name="base_url" value="<?= $h($cdDefaults['base_url']) ?>"
                            placeholder="https://college.instructure.com" autocomplete="url" spellcheck="false" required
                            aria-describedby="cdBaseUrlHelp cdBaseUrlError">
                        <div id="cdBaseUrlHelp" class="form-text">Your institution's Canvas address, for example college.instructure.com (https:// is added if you leave it out).</div>
                        <div id="cdBaseUrlError" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="cdToken" class="form-label">Canvas API token</label>
                        <input type="password" class="form-control" id="cdToken" name="canvas_token" autocomplete="off" spellcheck="false" required
                            aria-describedby="cdTokenHelp cdTokenError">
                        <div id="cdTokenHelp" class="form-text">In Canvas: Account &gt; Settings &gt; New Access Token. A teacher token covers your courses. An admin token also covers whole terms. The token isn't saved: it stays in this page until you log out or reload. During a run the server keeps an encrypted copy, deleted when the run ends.</div>
                        <div id="cdTokenError" class="invalid-feedback"></div>
                    </div>
                    <button type="button" class="btn btn-primary" id="cdTestConnection">Connect and list my courses</button>
                    </div>
                    <div id="cdConnectionStatus" class="mt-3" role="status" aria-live="polite"></div>
                    <p class="form-text mb-0 mt-2 d-none" id="cdSavedNote">This browser remembers your Canvas URL, launch URL and run settings. Never your token, key or secret. <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="cdForgetSaved">Forget them</button></p>
                </div>
            </div>

            <div id="cdAfterConnect" class="d-none">
            <div class="card card-primary card-outline mb-4" id="cdCoursesCard">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">3</span> Choose courses</h2>
                </div>
                <div class="card-body">
                    <fieldset class="border-0 p-0 m-0" aria-describedby="cdScopeError">
                        <legend class="form-label fs-6">Which courses?</legend>
                        <div class="btn-group flex-wrap mb-3" role="group" aria-label="Where the courses come from">
                            <input class="btn-check" type="radio" name="scope" id="cdScopePicked" value="picked" checked>
                            <label class="btn btn-outline-primary" for="cdScopePicked"><i class="bi bi-list-check me-1" aria-hidden="true"></i>My courses <span class="badge text-bg-light" id="cdPickedTotal"></span></label>
                            <input class="btn-check" type="radio" name="scope" id="cdScopeCourse" value="course">
                            <label class="btn btn-outline-primary" for="cdScopeCourse"><i class="bi bi-hash me-1" aria-hidden="true"></i>By Canvas ID</label>
                            <span class="btn-group d-none" id="cdAdminScopes" role="group" aria-label="Canvas account admin options">
                                <input class="btn-check" type="radio" name="scope" id="cdScopeTerm" value="term">
                                <label class="btn btn-outline-secondary" for="cdScopeTerm"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>A term</label>
                                <input class="btn-check" type="radio" name="scope" id="cdScopeActive" value="active">
                                <label class="btn btn-outline-secondary" for="cdScopeActive">All active</label>
                                <input class="btn-check" type="radio" name="scope" id="cdScopeAll" value="all">
                                <label class="btn btn-outline-secondary" for="cdScopeAll">All, incl. concluded</label>
                            </span>
                        </div>
                        <p class="form-text d-none" id="cdAdminNote">Term and all-course options appear because this token is an admin for <span id="cdAdminAccounts">an account</span>.</p>
                        <div class="mb-3" id="cdPickedWrap">
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-md-5">
                                    <label for="cdCourseFilter" class="form-label">Find a course</label>
                                    <input type="search" class="form-control" id="cdCourseFilter" autocomplete="off" placeholder="Name, code or SIS ID">
                                </div>
                                <div class="col-md-4">
                                    <label for="cdCourseTerm" class="form-label">Term</label>
                                    <select class="form-select" id="cdCourseTerm"><option value="">All terms</option></select>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="cdShowConcluded">
                                        <label class="form-check-label" for="cdShowConcluded">Show concluded</label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="cdSelectVisible">Select shown</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="cdClearSelection">Clear selection</button>
                                <span id="cdSelectedCount" class="form-text m-0" role="status" aria-live="polite"></span>
                                <button type="button" class="btn btn-outline-warning btn-sm d-none" id="cdClearHidden">Deselect hidden</button>
                            </div>
                            <div class="table-responsive border rounded" style="max-height: 22rem; overflow: auto;">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <caption class="visually-hidden">Canvas courses you can deploy to</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" style="width: 2.5rem"><span class="visually-hidden">Selected</span></th>
                                            <th scope="col" class="text-center" title="SLO Cloud tool in the course or from its account">App</th>
                                            <th scope="col" class="text-center" title="SLO Cloud link in Course Navigation">Nav</th>
                                            <th scope="col" class="text-center" title="Module link to SLO Cloud">Module</th>
                                            <th scope="col">Course</th>
                                            <th scope="col">Code</th>
                                            <th scope="col">Term</th>
                                            <th scope="col">Role</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cdCourseBody"></tbody>
                                </table>
                            </div>
                            <div id="cdFlagsStatus" class="form-text" role="status" aria-live="polite"></div>
                            <div id="cdPickedEmpty" class="form-text d-none">No courses match.</div>
                            <div id="cdPickedError" class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3" id="cdCourseIdsWrap">
                            <label for="cdCourseIds" class="form-label">Canvas course IDs</label>
                            <input type="text" class="form-control" id="cdCourseIds" inputmode="numeric" spellcheck="false"
                                value="<?= $cdDefaults['scope'] === 'course' ? $h($cdScopeValue) : '' ?>" aria-describedby="cdCourseIdsHelp cdCourseIdsError">
                            <div id="cdCourseIdsHelp" class="form-text">The number in the course address, for example 12345 in /courses/12345. Separate several with commas.</div>
                            <div id="cdCourseIdsError" class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3" id="cdTermWrap">
                                <div class="d-none" id="cdTermSelectWrap">
                                    <label for="cdTermSelect" class="form-label">Term</label>
                                    <select class="form-select" id="cdTermSelect" aria-describedby="cdTermError"></select>
                                </div>
                                <div id="cdTermIdWrap">
                                    <label for="cdTermId" class="form-label">Canvas term ID</label>
                                    <input type="text" class="form-control" id="cdTermId" inputmode="numeric" spellcheck="false"
                                        value="<?= $cdDefaults['scope'] === 'term' ? $h($cdScopeValue) : '' ?>" aria-describedby="cdTermIdHelp cdTermError">
                                    <div id="cdTermIdHelp" class="form-text">Enter the Canvas term ID.</div>
                                </div>
                                <div id="cdTermError" class="invalid-feedback"></div>
                            </div>
                        <p class="form-text mb-0 d-none" id="cdScopeAllNote">Checks every course in the account. Courses that already have SLO Cloud are skipped.</p>
                        <div id="cdScopeError" class="invalid-feedback"></div>
                    </fieldset>
                </div>
            </div>

            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">4</span> What to install</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="cdLinkName" class="form-label">Link name</label>
                        <input type="text" class="form-control" id="cdLinkName" name="link_name" maxlength="100" value="<?= $h($cdDefaults['link_name']) ?>" required
                            aria-describedby="cdLinkNameHelp cdLinkNameError">
                        <div id="cdLinkNameHelp" class="form-text">The course navigation tab name (instructors only) and the module link title.</div>
                        <div id="cdLinkNameError" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdCreateNav" name="create_nav" checked>
                        <label class="form-check-label" for="cdCreateNav">Add a Course Navigation link to SLO Cloud</label>
                        <div class="form-text">Skipped where the link exists. If SLO Cloud comes from the account without a nav link, the course gets its own copy to carry it. Needs the key and secret.</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdCreateModule" name="create_module" <?= $cdDefaults['create_module'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cdCreateModule">Also add an unpublished module with a link to SLO Cloud</label>
                    </div>
                    <div class="mb-3 ms-4">
                        <label for="cdModuleName" class="form-label">Module name</label>
                        <input type="text" class="form-control" id="cdModuleName" name="module_name" maxlength="100" value="<?= $h($cdDefaults['module_name']) ?>"
                            aria-describedby="cdModuleNameHelp cdModuleNameError">
                        <div id="cdModuleNameHelp" class="form-text">An existing module with this name is reused.</div>
                        <div id="cdModuleNameError" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-2" id="cdUpdateWrap">
                        <input class="form-check-input" type="checkbox" id="cdUpdateExisting">
                        <label class="form-check-label" for="cdUpdateExisting">Also update tools that are already in the course to use these credentials</label>
                        <div class="form-text">Gives course tools with this launch URL the key and secret above. Leave off to keep working tools as they are. A dry run lists which would change. Account-level tools can't be changed from a course.</div>
                    </div>
                    <div class="form-check mb-2 d-none" id="cdFixAccountNavWrap">
                        <input class="form-check-input" type="checkbox" id="cdFixAccountNav">
                        <label class="form-check-label" for="cdFixAccountNav">Add the nav link to the account's SLO Cloud tool</label>
                        <div class="form-text">Adds the nav link once on the account's SLO Cloud tool, so every course under it shows SLO Cloud. Needs an admin token. A dry run only reports it.</div>
                    </div>
                    <div class="mb-0 mt-3">
                        <label for="cdDelay" class="form-label">Delay between courses (milliseconds)</label>
                        <input type="number" class="form-control" id="cdDelay" name="course_delay_ms" min="0" max="10000" step="50" inputmode="numeric"
                            value="<?= $h($cdDefaults['course_delay_ms']) ?>" required aria-describedby="cdDelayHelp cdDelayError">
                        <div id="cdDelayHelp" class="form-text">Pause between courses, 0 to 10,000 ms. Raise it to go easy on Canvas at busy times.</div>
                        <div id="cdDelayError" class="invalid-feedback"></div>
                    </div>
                </div>
            </div>

            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">5</span> Review and run</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <caption>Settings for this run compared with the saved defaults</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Setting</th>
                                    <th scope="col">Saved default</th>
                                    <th scope="col">This run</th>
                                </tr>
                            </thead>
                            <tbody id="cdSummaryBody"></tbody>
                        </table>
                    </div>
                    <div id="cdFormError" class="alert alert-danger d-none" role="alert"></div>
                    <div id="cdConfirmNote" class="alert alert-warning d-none" role="alert"></div>
                    <?php if (!$canvasDeploy['ready']): ?>
                        <div class="alert alert-warning" role="alert">
                            This server cannot make HTTPS requests. Enable the PHP curl or openssl extension.
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary" id="cdDryRun" <?= $canvasDeploy['ready'] ? '' : 'disabled' ?>>Dry run</button>
                        <button type="button" class="btn btn-primary" id="cdDeploy" <?= $canvasDeploy['ready'] ? '' : 'disabled' ?>>Deploy</button>
                    </div>
                    <p class="form-text mt-2 mb-0">A dry run checks every course and reports what would change. It writes nothing to Canvas.</p>
                </div>
            </div>
            </div>
        </form>

        <div class="card card-success card-outline mb-4 d-none" id="cdProgressCard">
            <div class="card-header">
                <h2 class="card-title" id="cdProgressTitle" tabindex="-1">Run in progress</h2>
            </div>
            <div class="card-body">
                <p id="cdProgressStatus" class="mb-2" role="status" aria-live="polite"></p>
                <div class="progress mb-3" id="cdProgressBar" role="progressbar" aria-label="Run in progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                </div>
                <div class="row row-cols-2 row-cols-md-3 g-2 mb-3" id="cdTimers" aria-live="off"></div>
                <dl class="row mb-3" id="cdCounts"></dl>
                <div id="cdRunError" class="alert alert-danger d-none" role="alert"></div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-danger" id="cdCancel">Cancel run</button>
                </div>
                <ul id="cdRunNotes" class="mt-3 mb-0"></ul>
                <p class="form-text mb-0 mt-2"><a id="cdRunLog" href="#" target="_blank" rel="noopener">View this run's log</a>: every step and course result.</p>
            </div>
        </div>

        <div class="card card-outline card-secondary mb-4 d-none" id="cdResultsCard">
            <div class="card-header">
                <h2 class="card-title">Results</h2>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="cdOnlyIssues">
                        <label class="form-check-label" for="cdOnlyIssues">Show only warnings, failures, and skipped courses</label>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cdDownloadCsv">Download CSV</button>
                </div>
                <p id="cdResultsNote" class="form-text"></p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <caption>Per-course results</caption>
                        <thead>
                            <tr>
                                <th scope="col">Course ID</th>
                                <th scope="col">Course</th>
                                <th scope="col">SIS ID</th>
                                <th scope="col">Term</th>
                                <th scope="col">Tool</th>
                                <th scope="col">Nav</th>
                                <th scope="col">Module</th>
                                <th scope="col">Status</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody id="cdResultsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card card-outline card-secondary mb-4" id="cdServerCard">
            <div class="card-header">
                <h2 class="card-title">Server checks</h2>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php foreach ($canvasDeploy['prerequisites'] as $check): ?>
                        <?php if ($check['key'] !== 'transport') { continue; } ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between gap-3">
                                <span><?= $h($check['label']) ?></span>
                                <span class="badge text-bg-<?= $check['ok'] ? 'success' : 'danger' ?>"><?= $check['ok'] ? 'Ready' : 'Missing' ?></span>
                            </div>
                            <div class="small text-break"><?= $h($check['detail']) ?></div>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between gap-3">
                            <span>Data folder</span>
                            <span class="badge text-bg-<?= $cdDataOk ? 'success' : 'danger' ?>"><?= $cdDataOk ? 'Writable' : 'Not writable' ?></span>
                        </div>
                        <div class="small text-break"><?= $cdDataOk
                            ? 'Holds runs, logs and the queue key.'
                            : $h("Can't write to " . $cdDataDir . '. Give IIS AppPool\<pool name> Modify permission on it. Runs can\'t start until then.') ?></div>
                    </li>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between gap-3">
                            <span>Queue worker</span>
                            <span class="badge text-bg-<?= $cdWorkerFresh ? 'success' : 'warning' ?>"><?= $cdWorkerFresh ? 'Running' : 'Not running' ?></span>
                        </div>
                        <div class="small text-break"><?= $cdWorkerFresh
                            ? 'Runs continue with the page closed.'
                            : ($cdWorkerSeen === null ? 'Never run. ' : 'Last ran ' . $h(gmdate('Y-m-d H:i', $cdWorkerSeen)) . ' UTC. ')
                              . 'Runs only move while this page is open somewhere. See Queue worker in the README.' ?></div>
                    </li>
                </ul>
            </div>
        </div>
        <div class="card card-outline card-info mb-4" id="cdAboutCard">
            <div class="card-header">
                <h2 class="card-title">What gets installed</h2>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>A course navigation tab that only instructors, TAs, and designers can see.</li>
                    <li>Optionally, an unpublished module with a link to SLO Cloud.</li>
                    <li>Courses only get what they're missing. If SLO Cloud comes from the account without a nav link, the course gets its own copy to carry it.</li>
                    <li>Blueprint child courses are skipped; deploy to the Blueprint course and let sync apply it.</li>
                    <li>Runs are queued and go one at a time, in order. Follow them in Runs and queue.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>
</div>
</main>
<!-- Runs and queue: a floating panel, always on screen: the whole queue, then your runs. -->
<section class="card card-primary card-outline shadow-lg runs-panel" id="cdJobsCard" aria-labelledby="cdRunsTitle">
    <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-clock-history text-primary" aria-hidden="true"></i>
        <h2 class="card-title fs-6 mb-0 me-auto" id="cdRunsTitle">Runs and queue <span class="small fw-normal text-body-secondary" id="cdRunsSummary"></span></h2>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="cdRunsToggle" aria-expanded="true" aria-controls="cdRunsBody">
            <i class="bi bi-chevron-down" aria-hidden="true"></i><span class="visually-hidden">Show or hide runs and queue</span>
        </button>
    </div>
    <div class="card-body p-2" id="cdRunsBody">
        <div class="callout callout-info small mb-2 py-2" id="cdQueueStatus" role="status" aria-live="polite">Checking the queue…</div>
        <div class="small fw-semibold text-body-secondary px-1 mt-2" id="cdYourRunsHead">Your runs</div>
        <ul class="list-group list-group-flush" id="cdJobsList"></ul>
        <p class="form-text mb-0 px-1" id="cdYourRunsNote">Your runs from the last 7 days.</p>
    </div>
</section>
<footer class="app-footer small">
    <span class="text-body-secondary">Deploy to Canvas · installs the SLO Cloud LTI 1.1 tool in Canvas courses.</span>
</footer>
</div>

<script>
(() => {
    const config = <?= json_encode([
        'apiUrl' => $canvasDeploy['apiUrl'],
        'csrfToken' => $canvasDeploy['csrfToken'],
        'defaults' => $cdDefaults,
        'ready' => $canvasDeploy['ready'],
        'serverLti' => $cdServerLti,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    const byId = (id) => document.getElementById(id);
    const form = byId('canvasDeployForm');
    const els = {
        baseUrl: byId('cdBaseUrl'), token: byId('cdToken'), test: byId('cdTestConnection'), connection: byId('cdConnectionStatus'),
        linkName: byId('cdLinkName'), createNav: byId('cdCreateNav'), createModule: byId('cdCreateModule'), moduleName: byId('cdModuleName'), delay: byId('cdDelay'),
        courseIdsWrap: byId('cdCourseIdsWrap'), courseIds: byId('cdCourseIds'), termWrap: byId('cdTermWrap'),
        termSelectWrap: byId('cdTermSelectWrap'), termSelect: byId('cdTermSelect'), termIdWrap: byId('cdTermIdWrap'), termId: byId('cdTermId'),
        summary: byId('cdSummaryBody'), formError: byId('cdFormError'),
        dryRun: byId('cdDryRun'), deploy: byId('cdDeploy'),
        progressCard: byId('cdProgressCard'), progressTitle: byId('cdProgressTitle'), progressStatus: byId('cdProgressStatus'),
        progressBar: byId('cdProgressBar'), counts: byId('cdCounts'), runError: byId('cdRunError'), timers: byId('cdTimers'), jobsCard: byId('cdJobsCard'), jobsList: byId('cdJobsList'),
        cancel: byId('cdCancel'), runNotes: byId('cdRunNotes'),
        resultsCard: byId('cdResultsCard'), onlyIssues: byId('cdOnlyIssues'), csv: byId('cdDownloadCsv'),
        resultsNote: byId('cdResultsNote'), results: byId('cdResultsBody'),
        updateExisting: byId('cdUpdateExisting'), updateWrap: byId('cdUpdateWrap'), ltiOwn: byId('cdLtiOwn'), ltiFields: byId('cdLtiFields'), ltiKeyRow: byId('cdLtiKeyRow'),
        opDeploy: byId('cdOpDeploy'), opRemove: byId('cdOpRemove'), ltiUrl: byId('cdLtiUrl'), ltiKey: byId('cdLtiKey'), ltiSecret: byId('cdLtiSecret'),
        confirmNote: byId('cdConfirmNote'), connectFields: byId('cdConnectFields'), connectedBar: byId('cdConnectedBar'), connectedText: byId('cdConnectedText'),
        logout: byId('cdLogout'), afterConnect: byId('cdAfterConnect'), pickedWrap: byId('cdPickedWrap'), courseBody: byId('cdCourseBody'),
        courseFilter: byId('cdCourseFilter'), courseTerm: byId('cdCourseTerm'), showConcluded: byId('cdShowConcluded'),
        selectVisible: byId('cdSelectVisible'), clearSelection: byId('cdClearSelection'), selectedCount: byId('cdSelectedCount'),
        pickedTotal: byId('cdPickedTotal'), pickedEmpty: byId('cdPickedEmpty'), adminScopes: byId('cdAdminScopes'),
        flagsStatus: byId('cdFlagsStatus'), fixAccountNav: byId('cdFixAccountNav'), fixAccountNavWrap: byId('cdFixAccountNavWrap'),
        adminAccounts: byId('cdAdminAccounts'), scopePicked: byId('cdScopePicked'),
    };
    const fieldInputs = {
        base_url: ['cdBaseUrl', 'cdBaseUrlError'], canvas_token: ['cdToken', 'cdTokenError'], link_name: ['cdLinkName', 'cdLinkNameError'],
        lti_launch_url: ['cdLtiUrl', 'cdLtiUrlError'], lti_consumer_key: ['cdLtiKey', 'cdLtiKeyError'], lti_consumer_secret: ['cdLtiSecret', 'cdLtiSecretError'],
        module_name: ['cdModuleName', 'cdModuleNameError'], course_delay_ms: ['cdDelay', 'cdDelayError'], scope: ['cdScopeCourse', 'cdScopeError'],
    };
    const labels = {
        tool: { updated: 'Updated', would_update: 'Would update', removed: 'Removed', would_remove: 'Would remove', absent: 'Not installed', created: 'Created', would_create: 'Would create', present: 'Already present', present_account: 'Present (account level)', needs_credentials: 'Needs key and secret', doubtful: 'Other tool matched, nothing added', created_for_nav: 'Created (for nav link)', would_create_for_nav: 'Would create (for nav link)', skipped: 'Skipped', failed: 'Failed' },
        nav: { present: 'Already present', added: 'Added', would_add: 'Would add', not_added: 'Not added (see notes)', off: 'Not requested', skipped: 'Skipped' },
        module: { removed: 'Link removed', would_remove: 'Would remove link', none: 'None found', created: 'Module created', added: 'Link added', would_create: 'Would create module', would_add: 'Would add link', present: 'Already present', off: 'Not requested', skipped: 'Skipped', failed: 'Failed' },
        status: { ok: 'OK', warning: 'Warning', failed: 'Failed', skipped: 'Skipped' },
        scope: { picked: 'Courses I choose', course: 'Course by Canvas ID', term: 'Term', active: 'All active courses', all: 'All courses, including concluded' },
    };
    const MAX_TABLE_ROWS = 2000;
    config.defaults.operation = 'deploy';
    config.defaults.update_existing = false;
    config.defaults.create_nav = true;
    const operation = () => (els.opRemove.checked ? 'remove' : 'deploy');
    let terms = [];
    let run = null;
    let courses = [];
    let maxSelectable = 500;
    let connectedAdmin = false;
    const selected = new Set();

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const selectedScope = () => (form.querySelector('input[name="scope"]:checked') || {}).value || '';
    const termListShown = () => !els.termSelectWrap.classList.contains('d-none');

    async function post(action, data) {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', config.csrfToken);
        Object.entries(data || {}).forEach(([key, value]) => body.append(key, value));
        let response;
        try {
            response = await fetch(config.apiUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        } catch (error) {
            throw { error: 'network', message: 'SLO Cloud could not be reached. Check your connection, then retry.' };
        }
        let payload = null;
        let raw = '';
        try { raw = await response.text(); } catch (error) { raw = ''; }
        try { payload = raw ? JSON.parse(raw) : null; } catch (error) { payload = null; }
        if (!payload) {
            if (response.redirected) throw { error: 'session', message: 'Your SLO Cloud session ended. Sign in again in another tab, then retry.' };
            if (response.status === 403) throw { error: 'forbidden', message: 'Access denied.' };
            // Show the start of the reply as plain text, so a firewall or proxy page can be identified.
            const text = raw
                .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            const snippet = text.slice(0, 300);
            console.error('Non-JSON reply from ' + config.apiUrl + ' (HTTP ' + response.status + '):', raw);
            throw {
                error: 'http_' + response.status,
                message: 'SLO Cloud returned an unexpected response (HTTP ' + response.status + '). '
                    + (snippet ? 'The server said: "' + snippet + (text.length > 300 ? '…' : '') + '"' : 'The reply was empty.'),
            };
        }
        if (!response.ok || payload.ok !== true) throw payload;
        return payload;
    }

    // Link to this browser session's raw server log lines (log.php).
    function addLogLink(element) {
        const link = document.createElement('a');
        link.href = config.apiUrl.replace(/api\.php$/, 'log.php');
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'alert-link';
        link.textContent = 'View the server log for this error';
        element.append(' ', link);
    }

    function showAlert(container, kind, text) {
        container.replaceChildren();
        if (!text) return;
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + kind + ' mb-0';
        alert.textContent = text;
        if (kind === 'danger') addLogLink(alert);
        container.appendChild(alert);
    }

    function setFormError(text) {
        els.formError.textContent = text || '';
        if (text) addLogLink(els.formError);
        els.formError.classList.toggle('d-none', !text);
    }

    function clearFieldErrors() {
        form.querySelectorAll('.is-invalid').forEach((input) => { input.classList.remove('is-invalid'); input.removeAttribute('aria-invalid'); });
        form.querySelectorAll('.invalid-feedback').forEach((feedback) => { feedback.textContent = ''; feedback.classList.remove('d-block'); });
    }

    function markFieldErrors(fields) {
        let first = null;
        if (fields && Object.keys(fields).some((field) => field.startsWith('lti_'))) {
            els.ltiOwn.checked = true;
            syncLti();
        }
        Object.entries(fields || {}).forEach(([field, message]) => {
            let target = fieldInputs[field];
            if (field === 'scope_value') {
                if (selectedScope() === 'picked') target = ['cdCourseFilter', 'cdPickedError'];
                else target = selectedScope() === 'course' ? ['cdCourseIds', 'cdCourseIdsError'] : [termListShown() ? 'cdTermSelect' : 'cdTermId', 'cdTermError'];
            }
            if (!target) return;
            const input = byId(target[0]);
            const feedback = byId(target[1]);
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            feedback.textContent = message;
            feedback.classList.add('d-block');
            first = first || input;
        });
        if (first) first.focus();
    }

    function currentSettings() {
        const scope = selectedScope();
        let scopeValue = '';
        if (scope === 'picked') scopeValue = Array.from(selected).join(',');
        if (scope === 'course') scopeValue = els.courseIds.value.trim();
        if (scope === 'term') scopeValue = termListShown() ? els.termSelect.value : els.termId.value.trim();
        return {
            base_url: els.baseUrl.value.trim(),
            link_name: els.linkName.value.trim(),
            create_nav: els.createNav.checked && operation() === 'deploy' ? '1' : '0',
            create_module: els.createModule.checked ? '1' : '0',
            module_name: els.moduleName.value.trim(),
            course_delay_ms: els.delay.value.trim(),
            operation: operation(),
            update_existing: els.updateExisting.checked && operation() === 'deploy' ? '1' : '0',
            fix_account_nav: els.fixAccountNav.checked && operation() === 'deploy' ? '1' : '0',
            scope,
            scope_value: scopeValue,
        };
    }

    // The server knows course lists as the "course" scope; "picked" is only this page's way to build one.
    const wire = (settings) => (settings.scope === 'picked' ? Object.assign({}, settings, { scope: 'course' }) : settings);

    function describe(key, value, scope) {
        if (key === 'create_module' || key === 'create_nav' || key === 'update_existing' || key === 'fix_account_nav') return value === true || value === '1' ? 'Yes' : 'No';
        if (key === 'scope') return labels.scope[value] || 'Not chosen';
        if (key === 'scope_value') {
            if (scope === 'term') {
                const term = terms.find((item) => String(item.id) === String(value));
                return term ? term.name + ' (' + term.id + ')' : (value ? 'Term ' + value : 'Not chosen');
            }
            if (scope === 'picked') return selected.size ? selected.size + ' selected' : 'None selected';
            if (scope === 'course') return value || 'None entered';
            return 'Not needed';
        }
        if (key === 'course_delay_ms') return String(value) + ' ms';
        if (key === 'operation') return value === 'remove' ? 'Remove SLO Cloud' : 'Install SLO Cloud';
        return String(value || '') || 'Not set';
    }

    function comparable(key, value) {
        if (key === 'create_module' || key === 'create_nav' || key === 'update_existing' || key === 'fix_account_nav') return value === true || value === '1' ? '1' : '0';
        if (key === 'base_url') return String(value || '').trim().replace(/\/+$/, '').toLowerCase();
        return String(value ?? '').trim();
    }

    function renderSummary() {
        const current = currentSettings();
        const rows = [
            ['Canvas URL', 'base_url'], ['Action', 'operation'], ['Update existing tools', 'update_existing'], ['Add nav to account tool', 'fix_account_nav'], ['Link name', 'link_name'], ['Add navigation', 'create_nav'], ['Add module', 'create_module'], ['Module name', 'module_name'],
            ['Delay between courses', 'course_delay_ms'], ['Courses', 'scope'], ['Course IDs or term', 'scope_value'],
        ];
        let changed = 0;
        els.summary.replaceChildren();
        const offered = (key) => {
            if (key === 'update_existing') return current.operation === 'deploy';
            if (key === 'fix_account_nav') return connectedAdmin && current.operation === 'deploy';
            if (key === 'create_nav') return current.operation === 'deploy';
            return true;
        };
        rows.filter(([, key]) => offered(key)).forEach(([label, key]) => {
            const saved = config.defaults[key];
            const now = current[key];
            const differs = comparable(key, saved) !== comparable(key, now);
            if (differs) changed++;
            const tr = document.createElement('tr');
            if (differs) tr.className = 'table-warning';
            const th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            const savedCell = document.createElement('td');
            savedCell.textContent = describe(key, saved, config.defaults.scope);
            const nowCell = document.createElement('td');
            nowCell.textContent = describe(key, now, current.scope);
            if (differs) {
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-warning ms-2';
                badge.textContent = 'Changed';
                nowCell.appendChild(badge);
            }
            tr.append(th, savedCell, nowCell);
            els.summary.appendChild(tr);
        });
    }

    function syncScope() {
        const scope = selectedScope();
        els.pickedWrap.classList.toggle('d-none', scope !== 'picked');
        byId('cdScopeAllNote').classList.toggle('d-none', scope !== 'active' && scope !== 'all');
        els.courseIdsWrap.classList.toggle('d-none', scope !== 'course');
        els.termWrap.classList.toggle('d-none', scope !== 'term');
        els.moduleName.disabled = !els.createModule.checked || !!(run && run.active);
        renderSummary();
    }

    function setBusy(button, busy, busyText) {
        if (busy) {
            button.dataset.label = button.textContent;
            button.textContent = busyText;
        } else if (button.dataset.label) {
            button.textContent = button.dataset.label;
        }
        button.disabled = busy;
    }

    function lockForm(locked) {
        form.querySelectorAll('input, select, button').forEach((control) => {
            if (control === els.dryRun || control === els.deploy) {
                control.disabled = locked || !config.ready;
            } else {
                control.disabled = locked;
            }
        });
        if (!locked) {
            els.moduleName.disabled = !els.createModule.checked;
            syncLti();
        }
    }

    async function loadTerms() {
        try {
            const response = await post('terms', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            if (response.fallback || !response.terms.length) {
                els.termSelectWrap.classList.add('d-none');
                els.termIdWrap.classList.remove('d-none');
                byId('cdTermIdHelp').textContent = 'This token cannot list terms. Enter the Canvas term ID.';
                return;
            }
            terms = response.terms;
            const selected = els.termId.value.trim() || (config.defaults.scope === 'term' ? String(config.defaults.scope_value) : '');
            els.termSelect.replaceChildren();
            terms.forEach((term) => {
                const option = document.createElement('option');
                option.value = String(term.id);
                option.textContent = term.name + ' (' + term.id + ')';
                option.selected = String(term.id) === selected;
                els.termSelect.appendChild(option);
            });
            els.termSelectWrap.classList.remove('d-none');
            els.termIdWrap.classList.add('d-none');
            renderSummary();
        } catch (error) {
            byId('cdTermIdHelp').textContent = 'Terms could not be listed (' + (error.message || 'unknown error') + '). Enter the Canvas term ID.';
        }
    }

    const courseLabel = (course) => course.name || ('Course ' + course.id);

    function visibleCourses() {
        const needle = els.courseFilter.value.trim().toLowerCase();
        const term = els.courseTerm.value;
        return courses.filter((course) => {
            if (course.concluded && !els.showConcluded.checked && !selected.has(course.id)) return false;
            if (term !== '' && course.term !== term) return false;
            if (needle === '') return true;
            return [course.name, course.course_code, course.sis_course_id, String(course.id)].some((value) => String(value || '').toLowerCase().includes(needle));
        });
    }

    function updateSelectedCount() {
        const shown = new Set(visibleCourses().map((course) => course.id));
        const hidden = Array.from(selected).filter((id) => !shown.has(id)).length;
        els.selectedCount.textContent = selected.size + ' of ' + courses.length + ' selected'
            + (hidden ? '. ' + hidden + ' hidden by the filter but still included.' : '.');
        byId('cdClearHidden').classList.toggle('d-none', hidden === 0);
    }
    byId('cdClearHidden').addEventListener('click', () => {
        const shown = new Set(visibleCourses().map((course) => course.id));
        Array.from(selected).forEach((id) => { if (!shown.has(id)) selected.delete(id); });
        renderCourses();
        renderSummary();
    });

    // The chosen courses by name, for the deploy confirmation.
    function selectedNames(limit) {
        const names = courses.filter((course) => selected.has(course.id)).map(courseLabel);
        return names.slice(0, limit).join('; ') + (names.length > limit ? '; and ' + (names.length - limit) + ' more' : '');
    }

    function renderCourses() {
        const rows = visibleCourses();
        els.courseBody.replaceChildren(...rows.map((course) => {
            const tr = document.createElement('tr');
            const pick = document.createElement('td');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'form-check-input';
            box.id = 'cdCourse' + course.id;
            box.checked = selected.has(course.id);
            box.dataset.courseId = String(course.id);
            pick.appendChild(box);
            const flagCells = ['app', 'nav', 'module'].map((key) => flagCell(courseFlags.get(course.id), key));
            const name = document.createElement('td');
            const label = document.createElement('label');
            label.htmlFor = box.id;
            label.textContent = courseLabel(course);
            name.appendChild(label);
            if (course.concluded) {
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-secondary ms-2';
                badge.textContent = 'Concluded';
                name.appendChild(badge);
            }
            const cells = [course.course_code, course.term, (course.roles || []).join(', ')].map((value) => {
                const td = document.createElement('td');
                td.textContent = value || '';
                return td;
            });
            tr.append(pick, ...flagCells, name, ...cells);
            return tr;
        }));
        els.pickedEmpty.classList.toggle('d-none', rows.length > 0);
        updateSelectedCount();
    }

    // App / Nav / Module flags per course, filled in the background after the list loads.
    const courseFlags = new Map();
    let flagsGeneration = 0;
    const FLAG_TEXT = {
        yes: ['✓', 'text-success', 'Yes'],
        no: ['✗', 'text-danger', 'No'],
        doubtful: ['?', 'text-warning', 'A tool on the same domain matched; it may not be SLO Cloud'],
        error: ['!', 'text-warning', 'Could not check'],
    };

    function flagCell(flags, key) {
        const td = document.createElement('td');
        td.className = 'text-center';
        if (!flags) {
            td.textContent = '…';
            td.classList.add('text-body-secondary');
            td.title = 'Not checked yet';
            return td;
        }
        const [symbol, css, text] = FLAG_TEXT[flags[key]] || ['', '', ''];
        td.textContent = symbol;
        if (css) td.classList.add(css, 'fw-bold');
        td.title = text + (key === 'app' && flags.tool ? ': ' + flags.tool : '');
        return td;
    }

    async function loadFlags() {
        const generation = ++flagsGeneration;
        courseFlags.clear();
        renderCourses();
        if (!courses.length) { els.flagsStatus.textContent = ''; return; }
        const launch = els.ltiOwn.checked ? els.ltiUrl.value.trim() : '';
        // Small requests (3 courses), 3 at a time. The server stops early and hands back any
        // course it did not reach; a course that keeps failing is marked "!" so the rest go on.
        const queue = courses.map((course) => course.id);
        const attempts = new Map();
        const total = queue.length;
        let stopped = '';
        const progress = () => {
            if (generation === flagsGeneration && !stopped) {
                els.flagsStatus.textContent = 'Checking SLO Cloud in each course: ' + courseFlags.size + ' of ' + total + '…';
            }
        };
        const markError = (ids, message) => ids.forEach((id) => courseFlags.set(id, { app: 'error', nav: 'error', module: 'error', tool: message }));
        const worker = async () => {
            while (queue.length && !stopped && generation === flagsGeneration) {
                const batch = queue.splice(0, 3);
                let result;
                try {
                    result = await post('course_flags', {
                        base_url: els.baseUrl.value.trim(), canvas_token: els.token.value,
                        lti_launch_url: launch, course_ids: batch.join(','),
                    });
                } catch (error) {
                    if (generation !== flagsGeneration) return;
                    if (error.error === 'canvas_token' || error.canvas_status === 401) { stopped = error.message || 'The Canvas token was refused.'; return; }
                    markError(batch, error.message || 'Could not check');
                    renderCourses();
                    progress();
                    continue;
                }
                if (generation !== flagsGeneration) return;
                if (!result.launch_url_known) { stopped = 'launch_url'; return; }
                Object.entries(result.flags || {}).forEach(([id, flags]) => courseFlags.set(Number(id), flags));
                (result.pending || []).forEach((id) => {
                    const tries = (attempts.get(id) || 0) + 1;
                    attempts.set(id, tries);
                    if (tries >= 3) markError([id], 'Canvas was too slow to check this course'); else queue.push(id);
                });
                renderCourses();
                progress();
            }
        };
        progress();
        await Promise.all([worker(), worker(), worker()]);
        if (generation !== flagsGeneration) return;
        if (stopped === 'launch_url') {
            els.flagsStatus.textContent = 'Enter the SLO Cloud LTI launch URL in step 1 to see which courses already have the app, nav link and module.';
        } else if (stopped) {
            els.flagsStatus.textContent = 'Could not check courses: ' + stopped;
        } else {
            els.flagsStatus.textContent = 'App, Nav and Module: ✓ already there, ✗ missing, ? another tool on the same domain matched, ! could not check (hover for why).';
        }
    }
    els.ltiUrl.addEventListener('change', () => { if (courses.length) loadFlags(); });
    els.ltiOwn.addEventListener('change', () => { if (courses.length) loadFlags(); });

    function setCourses(list) {
        courses = list;
        const termNames = Array.from(new Set(list.map((course) => course.term).filter(Boolean)));
        els.courseTerm.replaceChildren(new Option('All terms', ''), ...termNames.map((name) => new Option(name, name)));
        els.pickedTotal.textContent = '(' + list.length + ' available)';
        selected.clear();
        renderCourses();
        renderSummary();
    }

    function toggleCourse(id, on) {
        if (on && selected.size >= maxSelectable) {
            showAlert(els.connection, 'warning', 'You can choose at most ' + maxSelectable + ' courses in one run.');
            return false;
        }
        if (on) selected.add(id); else selected.delete(id);
        return true;
    }

    els.courseBody.addEventListener('change', (event) => {
        const box = event.target;
        if (!(box instanceof HTMLInputElement) || !box.dataset.courseId) return;
        if (!toggleCourse(Number(box.dataset.courseId), box.checked)) box.checked = false;
        updateSelectedCount();
        renderSummary();
    });
    els.selectVisible.addEventListener('click', () => {
        for (const course of visibleCourses()) {
            if (!toggleCourse(course.id, true)) break;
        }
        renderCourses();
        renderSummary();
    });
    els.clearSelection.addEventListener('click', () => { selected.clear(); renderCourses(); renderSummary(); });
    [els.courseFilter, els.courseTerm, els.showConcluded].forEach((control) => control.addEventListener('input', renderCourses));

    els.test.addEventListener('click', async () => {
        clearFieldErrors();
        setBusy(els.test, true, 'Connecting…');
        showAlert(els.connection, 'info', 'Contacting Canvas…');
        try {
            const result = await post('test_connection', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            const listing = await post('courses', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            connectedAdmin = !!result.is_admin;
            saveSettings();
            maxSelectable = listing.max_selectable || maxSelectable;
            setCourses(listing.courses);
            syncOperation();
            loadFlags();
            loadJobs('my_jobs', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            els.afterConnect.classList.remove('d-none');
            els.adminScopes.classList.toggle('d-none', !connectedAdmin);
            byId('cdAdminNote').classList.toggle('d-none', !connectedAdmin);
            let summary = ' to ' + result.origin + ' as ' + (result.user || 'the token owner') + '. ' + listing.courses.length + ' courses can be deployed to with this token.';
            if (connectedAdmin) {
                const names = result.accounts.map((account) => account.name || ('Account ' + account.id)).join(', ');
                els.adminAccounts.textContent = names;
                summary += ' Canvas account admin for: ' + names + '.';
                await loadTerms();
            } else if (['term', 'active', 'all'].includes(selectedScope())) {
                els.scopePicked.checked = true;
            }
            // The token stays in the (now hidden) field for the rest of this page's life; Log out wipes it.
            const icon = document.createElement('i');
            icon.className = 'bi bi-check-circle-fill me-2';
            icon.setAttribute('aria-hidden', 'true');
            const strong = document.createElement('strong');
            strong.textContent = 'Connected';
            els.connectedText.replaceChildren(icon, strong, document.createTextNode(' ' + summary.trim()));
            els.connectFields.classList.add('d-none');
            els.connectedBar.classList.remove('d-none');
            showAlert(els.connection, 'info', '');
            els.logout.focus();
            syncScope();
        } catch (error) {
            showAlert(els.connection, 'danger', error.message || 'The connection failed.');
            markFieldErrors(error.fields);
        } finally {
            setBusy(els.test, false);
        }
    });

    els.logout.addEventListener('click', () => {
        // Forget everything this browser knows: the token, the secret, this session's runs
        // (on the server too), and the results on screen. Runs in progress keep going on the
        // server; connecting with the same Canvas account shows them again.
        post('logout', {}).catch(() => {});
        if (run) {
            run.active = false;
            run = null;
        }
        stopTimers();
        els.progressCard.classList.add('d-none');
        els.resultsCard.classList.add('d-none');
        els.results.replaceChildren();
        els.counts.replaceChildren();
        els.timers.replaceChildren();
        els.runNotes.replaceChildren();
        renderJobs([]);
        els.ltiSecret.value = '';
        lockForm(false);
        els.token.value = '';
        selected.clear();
        courses = [];
        connectedAdmin = false;
        els.courseFilter.value = '';
        els.afterConnect.classList.add('d-none');
        els.adminScopes.classList.add('d-none');
        byId('cdAdminNote').classList.add('d-none');
        els.connectedBar.classList.add('d-none');
        els.connectFields.classList.remove('d-none');
        els.scopePicked.checked = true;
        clearFieldErrors();
        setFormError('');
        showAlert(els.connection, 'info', "Logged out. Your token, the shared secret and this browser's runs were cleared. Runs in progress keep going; connect with the same Canvas account to see them.");
        // Advise deleting the token in Canvas, linking to that Canvas's settings page.
        const revoke = document.createElement('p');
        revoke.className = 'mb-0 mt-2';
        revoke.append('No longer need the token? ');
        let settingsUrl = '';
        try {
            const typed = els.baseUrl.value.trim();
            const origin = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(typed) ? typed : 'https://' + typed).origin;
            if (/^https?:/.test(origin)) settingsUrl = origin + '/profile/settings';
        } catch (error) { /* no usable Canvas address: no link */ }
        if (settingsUrl) {
            const link = document.createElement('a');
            link.href = settingsUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'alert-link';
            link.textContent = 'Delete it in Canvas';
            revoke.append(link, ' under Account > Settings > Approved Integrations.');
        } else {
            revoke.append('Delete it in Canvas under Account > Settings > Approved Integrations.');
        }
        els.connection.querySelector('.alert').appendChild(revoke);
        renderCourses();
        syncScope();
        els.token.focus();
    });

    function renderCounts(counts, dryRun) {
        const removing = !!(run && run.operation === 'remove');
        const items = removing ? [
            ['Courses processed', counts.processed],
            [dryRun ? 'Tools to remove' : 'Tools removed', dryRun ? counts.tool_would_remove : counts.tool_removed],
            ['Tool not installed', counts.tool_absent],
            [dryRun ? 'Module links to remove' : 'Module links removed', dryRun ? counts.module_would_remove : counts.module_removed],
            ['Warnings', counts.warnings],
            ['Failed', counts.failed],
            ['Skipped (see notes)', counts.skipped],
            ['Concluded, not included', counts.out_of_scope],
        ] : [
            ['Courses processed', counts.processed],
            [dryRun ? 'Tools to create' : 'Tools created', dryRun ? counts.tool_would_create : counts.tool_created],
            ['Tool already present (course or account)', counts.tool_present],
            [dryRun ? 'Course copies to make for the nav link' : 'Course copies made for the nav link', (dryRun ? counts.tool_would_copy : counts.tool_copied) || 0],
            [dryRun ? 'Tools to update' : 'Tools updated', dryRun ? counts.tool_would_update : counts.tool_updated],
            [dryRun ? 'Nav links to add' : 'Nav links added', (dryRun ? counts.nav_would_add : counts.nav_added) || 0],
            ['Nav link already present', counts.nav_present || 0],
            ['Nav link not added', counts.nav_not_added || 0],
            [dryRun ? 'Module links to add' : 'Module links added', dryRun ? counts.module_would_add : counts.module_created + counts.module_added],
            ['Module link already present', counts.module_present],
            ['Warnings', counts.warnings],
            ['Failed', counts.failed],
            ['Skipped (see notes)', counts.skipped],
            ['Concluded, not included', counts.out_of_scope],
        ];
        els.counts.replaceChildren();
        items.forEach(([label, value]) => {
            const dt = document.createElement('dt');
            dt.className = 'col-sm-8 fw-normal';
            dt.textContent = label;
            const dd = document.createElement('dd');
            dd.className = 'col-sm-4 mb-1 fw-semibold';
            dd.textContent = String(value || 0);
            els.counts.append(dt, dd);
        });
    }

    const isIssue = (row) => row.status !== 'ok';

    function resultRow(row) {
        const tr = document.createElement('tr');
        [
            String(row.course_id), row.name, row.sis_course_id, row.term,
            labels.tool[row.tool] || row.tool, labels.nav[row.nav] || row.nav || '', labels.module[row.module] || row.module,
            labels.status[row.status] || row.status, (row.notes || []).join(' '),
        ].forEach((value) => {
            const td = document.createElement('td');
            td.textContent = value || '';
            tr.appendChild(td);
        });
        return tr;
    }

    function renderResults() {
        const visible = run.results.filter((row) => !els.onlyIssues.checked || isIssue(row));
        els.results.replaceChildren(...visible.slice(0, MAX_TABLE_ROWS).map(resultRow));
        els.resultsNote.textContent = visible.length > MAX_TABLE_ROWS
            ? 'Showing the first ' + MAX_TABLE_ROWS + ' of ' + visible.length + ' rows. The CSV includes every course.'
            : '';
    }

    function appendResults(rows) {
        run.results.push(...rows);
        renderResults();
    }

    function csvCell(value) {
        let text = String(value ?? '');
        if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    els.csv.addEventListener('click', () => {
        if (!run) return;
        const header = ['course_id', 'course_name', 'sis_course_id', 'term', 'tool', 'nav', 'module', 'status', 'notes'];
        const lines = [header.map(csvCell).join(',')];
        run.results.forEach((row) => {
            lines.push([row.course_id, row.name, row.sis_course_id, row.term, row.tool, row.nav || '', row.module, row.status, (row.notes || []).join(' | ')].map(csvCell).join(','));
        });
        const stamp = new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '-');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        link.download = 'slocloud-canvas-deploy' + (run.dryRun ? '-dry-run' : '') + '-' + stamp + '.csv';
        document.body.appendChild(link);
        link.click();
        setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    });
    els.onlyIssues.addEventListener('change', () => { if (run) renderResults(); });

    const runLabel = (r) => (r.dryRun ? 'Dry run' : (r.operation === 'remove' ? 'Removal' : 'Deployment'));

    function endRun(title, message) {
        run.active = false;
        renderTimers(run);
        stopTimers();
        els.progressTitle.textContent = title;
        els.progressStatus.textContent = message;
        els.progressBar.classList.add('d-none');
        els.cancel.classList.add('d-none');
        lockForm(false);
        els.progressTitle.focus();
        loadJobs('session_jobs');
        loadQueueStatus();
    }

    function workerText(view) {
        return view.worker_fresh
            ? 'You can close this page.'
            : 'Keep this page open.';
    }

    function statusText(view) {
        const counts = view.counts || {};
        const parts = [];
        const plural = (count, word) => count + ' ' + word + (count === 1 ? '' : 's');
        if (view.cancelling) {
            parts.push('Cancelling after the current step.');
        } else if (view.status === 'queued') {
            if (view.ahead) {
                const size = view.ahead_courses === null
                    ? 'size not known yet'
                    : plural(view.ahead_courses, 'course') + (view.ahead_seconds !== null ? ', ' + roughly(view.ahead_seconds) : '');
                parts.push('Queued behind ' + plural(view.ahead, 'run') + ': ' + size + '.');
            } else {
                parts.push('Next in line.');
            }
            parts.push('It starts automatically unless you cancel it.');
        } else {
            parts.push('Running. ' + plural(counts.processed || 0, 'course') + ' done.');
        }
        if (view.errors && view.message) parts.push('Last step failed: ' + view.message + ' Retrying.');
        parts.push(workerText(view));
        return parts.join(' ');
    }

    // Timers on the page side: they only need to show that things are moving, so they come
    // from the browser's clock, corrected by the server's clock in each status reply.
    let timerHandle = null;
    const clock = (seconds) => {
        seconds = Math.max(0, Math.round(seconds));
        const h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : String(m)) + ':' + String(s).padStart(2, '0');
    };
    const shortSeconds = (seconds) => (seconds < 10 ? seconds.toFixed(1) : String(Math.round(seconds))) + ' s';

    function trackTimes(r, view) {
        if (typeof view.now === 'number') r.offset = Date.now() / 1000 - view.now;
        r.createdAt = view.created_at || r.createdAt;
        r.lastStepAt = view.last_step_at || 0;
        r.finishedAt = view.finished_at || 0;
        r.status = view.status;
        r.processed = (view.counts || {}).processed || 0;
        r.remaining = typeof view.remaining === 'number' ? view.remaining : null;
        r.helping = !view.worker_fresh;
        r.ahead = view.ahead || 0;
        r.aheadCourses = view.ahead_courses;
        r.aheadSeconds = view.ahead_seconds;
        r.aheadAt = Date.now();
    }

    const TIMER_ICONS = {
        'Elapsed': ['bi-stopwatch', 'text-bg-primary'],
        'Waiting': ['bi-hourglass-split', 'text-bg-secondary'],
        'Ahead': ['bi-people', 'text-bg-secondary'],
        'Est. wait': ['bi-hourglass', 'text-bg-warning'],
        'Processed': ['bi-check2-square', 'text-bg-success'],
        'Per course': ['bi-speedometer2', 'text-bg-info'],
        'Est. time left': ['bi-flag', 'text-bg-warning'],
        'Last progress': ['bi-activity', 'text-bg-secondary'],
        'Working': ['bi-gear', 'text-bg-primary'],
        'Checking': ['bi-arrow-repeat', 'text-bg-secondary'],
        'Next check in': ['bi-arrow-repeat', 'text-bg-secondary'],
    };

    function renderTimers(r) {
        const live = byId('cdRunLive');
        if (live && r === run) live.textContent = liveText();
        if (!r || !r.createdAt) { els.timers.replaceChildren(); return; }
        const serverNow = Date.now() / 1000 - (r.offset || 0);
        const end = r.finishedAt || serverNow;
        const elapsed = end - r.createdAt;
        const items = [['Elapsed', clock(elapsed)]];
        // Waiting only while nobody is working on it (not while this page runs its step).
        if (r.status === 'queued' && !r.processed && !(r.requestStarted && r.helping && !r.ahead)) {
            items.push(['Waiting', clock(serverNow - r.createdAt)]);
            if (r.ahead) {
                items.push(['Ahead', r.ahead + ' run' + (r.ahead === 1 ? '' : 's') + (r.aheadCourses !== null && r.aheadCourses !== undefined ? ', ' + r.aheadCourses + ' courses' : '')]);
                if (typeof r.aheadSeconds === 'number') {
                    items.push(['Est. wait', '~' + clock(Math.max(0, r.aheadSeconds - (Date.now() - r.aheadAt) / 1000))]);
                }
            }
        }
        const total = r.remaining !== null && r.remaining !== undefined ? r.processed + r.remaining : null;
        items.push(['Processed', String(r.processed) + (total !== null && r.active ? ' of ' + total : '')]);
        if (r.processed) {
            const each = elapsed / r.processed;
            items.push(['Per course', shortSeconds(each)]);
            if (r.active && r.remaining) items.push(['Est. time left', '~' + clock(r.remaining * each)]);
        }
        if (r.active) {
            if (r.lastStepAt) items.push(['Last progress', clock(serverNow - r.lastStepAt) + ' ago']);
            if (r.requestStarted) {
                items.push([r.helping ? 'Working' : 'Checking', clock((Date.now() - r.requestStarted) / 1000)]);
            } else if (r.nextCheck) {
                items.push(['Next check in', Math.max(0, Math.ceil((r.nextCheck - Date.now()) / 1000)) + ' s']);
            }
        }
        els.timers.replaceChildren(...items.map(([label, value]) => {
            // AdminLTE info box: a coloured icon, the label, and the value.
            const [icon, colour] = TIMER_ICONS[label] || ['bi-clock', 'text-bg-secondary'];
            const col = document.createElement('div');
            col.className = 'col';
            const box = document.createElement('div');
            box.className = 'info-box mb-0 h-100';
            const iconBox = document.createElement('span');
            iconBox.className = 'info-box-icon shadow-sm ' + colour;
            const i = document.createElement('i');
            i.className = 'bi ' + icon;
            i.setAttribute('aria-hidden', 'true');
            iconBox.appendChild(i);
            const content = document.createElement('div');
            content.className = 'info-box-content';
            const text = document.createElement('span');
            text.className = 'info-box-text';
            text.textContent = label;
            const number = document.createElement('span');
            number.className = 'info-box-number font-monospace';
            number.textContent = value;
            content.append(text, number);
            box.append(iconBox, content);
            col.appendChild(box);
            return col;
        }));
    }

    function startTimers(r) {
        stopTimers();
        renderTimers(r);
        timerHandle = setInterval(() => { if (run === r) renderTimers(r); }, 1000);
    }

    function stopTimers() {
        if (timerHandle) clearInterval(timerHandle);
        timerHandle = null;
    }

    // Follow a queued run until it ends, showing its rows and counts as they arrive. The run
    // lives on the server; this only watches it (and helps it along when no worker runs).
    async function follow(view, focus) {
        const settings = view.settings || {};
        const mine = { active: true, jobId: view.id, dryRun: !!settings.dry_run, operation: settings.operation || 'deploy', results: [], counts: view.counts || {} };
        run = mine;
        trackTimes(mine, view);
        startTimers(mine);
        patchJob(view);
        byId('cdRunLog').href = config.apiUrl.replace(/api\.php$/, 'log.php') + '?run=' + encodeURIComponent(view.id);
        els.progressCard.classList.remove('d-none');
        els.resultsCard.classList.remove('d-none');
        els.progressBar.classList.remove('d-none');
        els.runError.classList.add('d-none');
        els.cancel.classList.remove('d-none');
        setCancelButton(view);
        els.progressTitle.textContent = runLabel(mine) + (DeployActive.includes(view.status) ? ' in progress' : '');
        els.progressStatus.textContent = DeployActive.includes(view.status) ? 'Starting…' : 'Loading the results…';
        els.runNotes.replaceChildren(...(view.notes || []).map((note) => {
            const li = document.createElement('li');
            li.textContent = note;
            return li;
        }));
        renderCounts(mine.counts, mine.dryRun);
        renderResults();
        lockForm(true);
        if (focus) els.progressTitle.focus();
        let failures = 0;
        while (run === mine && mine.active) {
            let current;
            mine.requestStarted = Date.now();
            mine.nextCheck = 0;
            renderTimers(mine);
            try {
                current = await post('job_status', { job_id: mine.jobId, from: String(mine.results.length), help: '1' });
                mine.requestStarted = 0;
                trackTimes(mine, current);
                renderTimers(mine);
                patchJob(current);
                failures = 0;
                els.runError.classList.add('d-none');
            } catch (error) {
                if (run !== mine) return;
                if (error.error === 'job_forbidden' || error.error === 'job_missing') {
                    endRun('Run not available', error.message || 'This run cannot be shown.');
                    return;
                }
                // A gateway timeout or dropped connection loses nothing: the run is on the server.
                mine.requestStarted = 0;
                failures++;
                mine.nextCheck = Date.now() + Math.min(15000, 2000 * failures);
                els.runError.textContent = 'Lost contact with the server. Retrying… The run is safe on the server.';
                els.runError.classList.remove('d-none');
                await sleep(Math.min(15000, 2000 * failures));
                continue;
            }
            if (run !== mine) return;
            appendResults(current.results.slice(Math.max(0, mine.results.length - current.results_from)));
            mine.counts = current.counts || {};
            renderCounts(mine.counts, mine.dryRun);
            const moreRows = mine.results.length < current.results_total;
            if (DeployActive.includes(current.status)) {
                els.progressStatus.textContent = statusText(current);
                setCancelButton(current);
                if (!moreRows) {
                    const wait = current.helped ? 300 : 2500;
                    mine.nextCheck = Date.now() + wait;
                    await sleep(wait);
                }
                continue;
            }
            if (moreRows) continue;
            const label = runLabel(mine);
            const titles = { done: label + ' complete', cancelled: label + ' cancelled', failed: label + ' stopped', expired: label + ' expired' };
            const processed = mine.counts.processed || 0;
            let message;
            if (current.status === 'cancelled') {
                message = processed
                    ? 'Cancelled after ' + processed + ' courses. Those keep their changes.'
                    : 'Cancelled before it started. Nothing changed in Canvas.';
            } else {
                message = current.status === 'done'
                    ? 'Done. ' + processed + ' courses processed.'
                    : 'Stopped after ' + processed + ' courses. ' + (current.message || '');
            }
            endRun(titles[current.status] || label + ' ended', message);
            return;
        }
    }
    const DeployActive = ['queued', 'running'];

    function setCancelButton(view) {
        els.cancel.textContent = view.status === 'queued' ? 'Cancel this queued run' : 'Cancel run';
        els.cancel.disabled = !!view.cancelling;
    }

    els.cancel.addEventListener('click', async () => {
        if (!run || !run.active) return;
        els.cancel.disabled = true;
        els.progressStatus.textContent = run.status === 'queued' ? 'Cancelling.' : 'Cancelling after the current step.';
        try {
            await post('job_cancel', { job_id: run.jobId });
        } catch (error) {
            els.cancel.disabled = false;
            els.runError.textContent = 'Could not cancel: ' + (error.message || 'no reply') + ' Try again.';
            els.runError.classList.remove('d-none');
        }
    });

    // "Your runs": runs started from this browser, plus (after connecting) every run this
    // Canvas user started anywhere. An unfinished one is followed automatically.
    function scopeSummary(settings) {
        if (settings.scope === 'course') {
            const n = String(settings.scope_value || '').split(',').filter(Boolean).length;
            return n + ' course' + (n === 1 ? '' : 's');
        }
        if (settings.scope === 'term') return 'Term ' + settings.scope_value;
        return labels.scope[settings.scope] || '';
    }

    const STATUS_BADGE = {
        queued: ['Queued', 'text-bg-secondary'], running: ['Running', 'text-bg-primary'], done: ['Done', 'text-bg-success'],
        cancelled: ['Cancelled', 'text-bg-warning'], failed: ['Stopped', 'text-bg-danger'], expired: ['Expired', 'text-bg-warning'],
    };

    // The floating panel: jobsCache holds the last list from the server, patched with live
    // status from the run being watched, so the panel stays current between refreshes.
    let jobsCache = [];
    const armedCancels = new Map();
    const narrow = () => window.matchMedia('(max-width: 575.98px)').matches;
    let panelFolded = (() => {
        try {
            const saved = localStorage.getItem('cdRunsFolded');
            if (saved !== null) return saved === '1';
        } catch (error) { /* storage blocked: use the default */ }
        return narrow();
    })();

    function setPanelFolded(folded) {
        panelFolded = folded;
        els.jobsCard.classList.toggle('is-folded', folded);
        byId('cdRunsToggle').setAttribute('aria-expanded', folded ? 'false' : 'true');
        try { localStorage.setItem('cdRunsFolded', folded ? '1' : '0'); } catch (error) { /* not remembered */ }
    }
    byId('cdRunsToggle').addEventListener('click', () => setPanelFolded(!panelFolded));

    function patchJob(view) {
        if (!view || !view.id) return;
        const known = jobsCache.find((job) => job.id === view.id);
        const fields = { id: view.id, status: view.status, created_at: view.created_at, counts: view.counts, settings: view.settings, user_name: view.user_name, remaining: view.remaining };
        if (known) Object.assign(known, fields); else jobsCache.unshift(fields);
        renderJobs(jobsCache);
    }

    function liveText() {
        if (!run || !run.createdAt) return '';
        const serverNow = Date.now() / 1000 - (run.offset || 0);
        const total = typeof run.remaining === 'number' ? run.processed + run.remaining : null;
        const checked = run.processed + (total !== null ? ' of ' + total : '') + ' processed';
        return run.status === 'queued'
            ? 'Waiting ' + clock(serverNow - run.createdAt) + (run.ahead ? ' · ' + run.ahead + ' ahead' : ' · next')
            : checked + ' · ' + clock((run.finishedAt || serverNow) - run.createdAt);
    }

    // The whole queue, for everyone: counts only.
    let queueStatus = null;
    // A real estimate, rounded to what matters: "about 20 sec", "about 4 min", "about 1 h 20 min".
    const roughly = (seconds) => {
        seconds = Math.max(0, Math.round(seconds));
        if (seconds < 5) return 'a few sec';
        if (seconds < 55) return 'about ' + Math.max(10, Math.round(seconds / 10) * 10) + ' sec';
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return 'about ' + minutes + ' min';
        return 'about ' + Math.floor(minutes / 60) + ' h' + (minutes % 60 ? ' ' + (minutes % 60) + ' min' : '');
    };

    function renderPanelSummary() {
        const mine = jobsCache.filter((job) => DeployActive.includes(job.status)).length;
        const parts = [];
        if (queueStatus) parts.push(queueStatus.runs ? queueStatus.runs + ' in queue' : 'queue empty');
        if (mine) parts.push(mine + ' yours');
        byId('cdRunsSummary').textContent = parts.length ? '· ' + parts.join(' · ') : '';
    }

    function renderQueueStatus(q) {
        queueStatus = q;
        const box = byId('cdQueueStatus');
        // Only the queue's size matters to the person looking: this page itself keeps the
        // queue moving when no server worker does.
        box.classList.toggle('callout-success', !q.runs);
        box.classList.toggle('callout-info', !!q.runs);
        const lines = [];
        if (!q.runs) {
            lines.push(['Queue is empty.', 'New runs start right away.']);
        } else {
            const parts = [];
            if (q.courses || !q.unknown_runs) parts.push(q.courses + ' course' + (q.courses === 1 ? '' : 's'));
            if (q.unknown_runs) parts.push(q.unknown_runs + ' full term' + (q.unknown_runs === 1 ? '' : 's'));
            const time = q.unknown_runs ? '' : ', ' + roughly(q.seconds);
            lines.push([q.runs + ' run' + (q.runs === 1 ? '' : 's') + ' queued.', parts.join(' + ') + time + '.']);
            lines.push(['', 'New runs join the end of the line.']);
        }
        box.replaceChildren(...lines.map(([bold, rest], index) => {
            const p = document.createElement('div');
            if (index) p.className = 'mt-1';
            const b = document.createElement('strong');
            b.textContent = bold;
            p.append(b, ' ' + rest);
            return p;
        }));
        renderPanelSummary();
    }

    // The panel's check also keeps the queue moving when no server worker runs: any open
    // page steps the first run in line (a page watching a run already does that through its
    // own status checks, so this one only reads). Busy queue: check again within seconds;
    // quiet queue: every 10 s.
    let queueTimer = null;
    async function loadQueueStatus() {
        clearTimeout(queueTimer);
        let delay = 10000;
        try {
            const watching = !!(run && run.active);
            const q = await post('queue_status', { help: watching ? '0' : '1' });
            renderQueueStatus(q);
            if (q.runs && !q.worker_fresh && !watching) delay = q.helped ? 500 : 2000;
            if (q.helped) loadJobs('session_jobs', null, true);
        } catch (error) {
            byId('cdQueueStatus').textContent = 'Could not check the queue (' + (error.message || 'no reply') + ').';
        }
        queueTimer = setTimeout(loadQueueStatus, delay);
    }
    loadQueueStatus();

    function renderJobs(jobs) {
        jobsCache = jobs;
        els.jobsCard.classList.toggle('is-folded', panelFolded);
        const hasRuns = jobs.length > 0;
        ['cdYourRunsHead', 'cdJobsList', 'cdYourRunsNote'].forEach((id) => byId(id).classList.toggle('d-none', !hasRuns));
        renderPanelSummary();
        els.jobsList.replaceChildren(...jobs.map((job) => {
            const settings = job.settings || {};
            const viewing = run && run.jobId === job.id;
            const li = document.createElement('li');
            li.className = 'list-group-item px-2 d-flex justify-content-between align-items-start gap-2' + (viewing ? ' list-group-item-primary' : '');
            const text = document.createElement('div');
            const what = document.createElement('div');
            what.className = 'fw-semibold';
            what.textContent = (settings.dry_run ? 'Dry run: ' : '') + (settings.operation === 'remove' ? 'Remove' : 'Install') + ', ' + scopeSummary(settings);
            const when = document.createElement('div');
            when.className = 'small text-body-secondary';
            when.textContent = new Date(job.created_at * 1000).toLocaleString() + (job.user_name ? ' · ' + job.user_name : '');
            const progress = document.createElement('div');
            progress.className = 'small';
            if (viewing && run.active) {
                progress.id = 'cdRunLive';
                progress.textContent = liveText();
            } else {
                progress.textContent = ((job.counts || {}).processed || 0) + ' processed';
            }
            text.append(what, when, progress);
            const side = document.createElement('div');
            side.className = 'text-end flex-shrink-0';
            const [badgeText, badgeClass] = STATUS_BADGE[job.status] || [job.status, 'text-bg-secondary'];
            const badge = document.createElement('span');
            badge.className = 'badge ' + badgeClass;
            badge.textContent = badgeText;
            side.append(badge);
            if (viewing) {
                const note = document.createElement('div');
                note.className = 'small text-body-secondary';
                note.textContent = 'Viewing';
                side.append(note);
            } else {
                const view = document.createElement('button');
                view.type = 'button';
                view.className = 'btn btn-link btn-sm p-0 d-block ms-auto';
                view.textContent = 'View';
                view.addEventListener('click', () => openJob(job.id));
                side.append(view);
            }
            if (DeployActive.includes(job.status)) {
                // Two clicks, so a stray click does not stop someone's run. The armed state
                // survives the panel redrawing.
                const stop = document.createElement('button');
                stop.type = 'button';
                stop.className = 'btn btn-link btn-sm p-0 d-block ms-auto text-danger';
                const armed = (armedCancels.get(job.id) || 0) > Date.now();
                stop.textContent = armed ? 'Click again to cancel' : 'Cancel';
                stop.addEventListener('click', async () => {
                    if (!((armedCancels.get(job.id) || 0) > Date.now())) {
                        armedCancels.set(job.id, Date.now() + 4000);
                        stop.textContent = 'Click again to cancel';
                        setTimeout(() => renderJobs(jobsCache), 4100);
                        return;
                    }
                    armedCancels.delete(job.id);
                    stop.disabled = true;
                    stop.textContent = 'Cancelling…';
                    try {
                        await post('job_cancel', { job_id: job.id });
                    } catch (error) {
                        showAlert(els.connection, 'warning', 'Could not cancel: ' + (error.message || 'no reply'));
                    }
                    loadJobs('session_jobs');
                });
                side.append(stop);
            }
            li.append(text, side);
            return li;
        }));
    }

    // Unfinished runs not being watched still change (queued -> running -> done): refresh.
    setInterval(() => {
        if (jobsCache.some((job) => DeployActive.includes(job.status) && !(run && run.active && run.jobId === job.id))) {
            loadJobs('session_jobs');
        }
    }, 15000);

    async function openJob(id) {
        try {
            follow(await post('job_status', { job_id: id, from: '0' }), true);
        } catch (error) {
            showAlert(els.connection, 'warning', error.message || 'That run could not be opened.');
        }
    }

    async function loadJobs(action, data, quiet) {
        let reply;
        try {
            reply = await post(action, data || {});
        } catch (error) {
            return;
        }
        renderJobs(reply.jobs || []);
        // quiet: just refresh the list (e.g. after this page helped the queue), don't jump to a run.
        const unfinished = (reply.jobs || []).find((job) => DeployActive.includes(job.status));
        if (!quiet && unfinished && !(run && run.active)) openJob(unfinished.id);
    }

    // Remembered settings: this browser keeps the last values that worked (saved on connect
    // and on starting a run). Never credentials (token, consumer key, shared secret), nor
    // Install/Remove or the courses: anything kept in a browser can leak.
    const SAVED_KEY = 'cdSavedSettings';
    const savedFields = () => ({
        base_url: els.baseUrl.value.trim(),
        lti_own: els.ltiOwn.checked && !els.ltiOwn.disabled,
        lti_launch_url: els.ltiUrl.value.trim(),
        link_name: els.linkName.value.trim(),
        create_nav: els.createNav.checked,
        create_module: els.createModule.checked,
        module_name: els.moduleName.value.trim(),
        course_delay_ms: els.delay.value.trim(),
    });

    function saveSettings() {
        try {
            localStorage.setItem(SAVED_KEY, JSON.stringify(savedFields()));
            byId('cdSavedNote').classList.remove('d-none');
        } catch (error) { /* storage blocked: nothing remembered */ }
    }

    function restoreSettings() {
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(SAVED_KEY) || 'null'); } catch (error) { saved = null; }
        if (!saved || typeof saved !== 'object') return;
        const text = (input, value) => { if (typeof value === 'string' && value !== '') input.value = value; };
        text(els.baseUrl, saved.base_url);
        if (saved.lti_own === true && !els.ltiOwn.disabled) els.ltiOwn.checked = true;
        if (els.ltiOwn.checked) text(els.ltiUrl, saved.lti_launch_url);
        text(els.linkName, saved.link_name);
        if (typeof saved.create_nav === 'boolean') els.createNav.checked = saved.create_nav;
        if (typeof saved.create_module === 'boolean') els.createModule.checked = saved.create_module;
        text(els.moduleName, saved.module_name);
        if (/^\d+$/.test(String(saved.course_delay_ms || ''))) els.delay.value = saved.course_delay_ms;
        byId('cdSavedNote').classList.remove('d-none');
    }

    byId('cdForgetSaved').addEventListener('click', () => {
        try { localStorage.removeItem(SAVED_KEY); } catch (error) { /* nothing to forget */ }
        // Back to the server's defaults.
        window.location.reload();
    });

    let confirmingDeploy = false;
    function resetDeployConfirm() {
        if (!confirmingDeploy) return;
        confirmingDeploy = false;
        els.deploy.textContent = operation() === 'remove' ? 'Remove' : 'Deploy';
        els.deploy.classList.replace('btn-danger', operation() === 'remove' ? 'btn-outline-danger' : 'btn-primary');
        els.confirmNote.classList.add('d-none');
    }

    async function startRun(dryRun) {
        if (run && run.active) return;
        clearFieldErrors();
        setFormError('');
        const settings = currentSettings();
        if (settings.scope === 'picked' && selected.size === 0) {
            markFieldErrors({ scope_value: 'Choose at least one course.' });
            setFormError('Choose at least one course in step 3, then run again.');
            return;
        }
        if (!els.token.value.trim()) {
            markFieldErrors({ canvas_token: 'Enter a Canvas API token.' });
            setFormError('Enter your Canvas API token in step 2.');
            return;
        }
        if (!dryRun) {
            const scopeText = describe('scope', settings.scope) + (['picked', 'course', 'term'].includes(settings.scope) ? ': ' + describe('scope_value', settings.scope_value, settings.scope) : '')
                + (settings.scope === 'picked' ? ' (' + selectedNames(10) + ')' : '');
            // An inline second click instead of window.confirm(), which some browsers block silently.
            if (!confirmingDeploy) {
                confirmingDeploy = true;
                els.deploy.textContent = settings.operation === 'remove' ? 'Confirm: remove now' : 'Confirm: deploy now';
                els.deploy.classList.replace(settings.operation === 'remove' ? 'btn-outline-danger' : 'btn-primary', 'btn-danger');
                els.confirmNote.textContent = settings.operation === 'remove'
                    ? 'Remove SLO Cloud from ' + scopeText + '? This deletes the course tool and its module link in every one of those courses, and cannot be undone from here. Click Confirm to start, or change anything to cancel.'
                    : 'Deploy SLO Cloud to ' + scopeText + '? This adds the tool' + (settings.create_nav === '1' ? ', nav link' : '') + (settings.create_module === '1' ? ' and module link' : '')
                        + ' wherever a course is missing them. '
                        + (settings.update_existing === '1' ? 'Tools already in a course are given these credentials. ' : 'Existing installs are left alone. ')
                        + 'Click Confirm to start, or change anything to cancel.';
                els.confirmNote.classList.remove('d-none');
                return;
            }
            resetDeployConfirm();
        }
        lockForm(true);
        try {
            const started = await post('run_start', Object.assign({}, wire(settings), {
                dry_run: dryRun ? '1' : '0', canvas_token: els.token.value,
                lti_launch_url: els.ltiOwn.checked ? els.ltiUrl.value.trim() : '',
                lti_consumer_key: els.ltiOwn.checked ? els.ltiKey.value.trim() : '',
                lti_consumer_secret: els.ltiOwn.checked ? els.ltiSecret.value : '',
            }));
            saveSettings();
            loadJobs('session_jobs');
            loadQueueStatus();
            follow(started, true);
        } catch (error) {
            lockForm(false);
            setFormError(error.message || 'The run could not start.');
            markFieldErrors(error.fields);
        }
    }

    // The LTI boxes show only when asked for (or when the server has none saved). Removing needs
    // just the launch URL. Unticking wipes what was typed so a secret never lingers hidden.
    function syncLti() {
        if (!config.serverLti) els.ltiOwn.checked = true;
        els.ltiOwn.disabled = !config.serverLti || (run && run.active);
        els.ltiFields.classList.toggle('d-none', !els.ltiOwn.checked);
        els.ltiKeyRow.classList.toggle('d-none', operation() === 'remove');
        if (!els.ltiOwn.checked) {
            els.ltiUrl.value = '';
            els.ltiKey.value = '';
            els.ltiSecret.value = '';
        }
    }
    els.ltiOwn.addEventListener('change', syncLti);

    function syncOperation() {
        syncLti();
        els.updateWrap.classList.toggle('d-none', operation() === 'remove');
        if (operation() === 'remove') els.updateExisting.checked = false;
        const showFixNav = connectedAdmin && operation() === 'deploy';
        els.fixAccountNavWrap.classList.toggle('d-none', !showFixNav);
        if (!showFixNav) els.fixAccountNav.checked = false;
        if (confirmingDeploy) return;
        const removing = operation() === 'remove';
        els.deploy.textContent = removing ? 'Remove' : 'Deploy';
        els.deploy.classList.toggle('btn-primary', !removing);
        els.deploy.classList.toggle('btn-outline-danger', removing);
    }
    els.opDeploy.addEventListener('change', syncOperation);
    els.opRemove.addEventListener('change', syncOperation);
    els.dryRun.addEventListener('click', () => { resetDeployConfirm(); startRun(true); });
    els.deploy.addEventListener('click', () => startRun(false));
    form.addEventListener('submit', (event) => event.preventDefault());
    restoreSettings();
    syncLti();
    form.addEventListener('input', () => { resetDeployConfirm(); renderSummary(); });
    form.addEventListener('change', () => { resetDeployConfirm(); syncScope(); });
    syncScope();
    loadJobs('session_jobs');
})();
</script>
<!-- /canvas-deploy:view -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</body>
</html>
