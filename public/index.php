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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-body border-bottom mb-2">
    <div class="container"><span class="navbar-brand mb-0 h1"><i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>Deploy to Canvas</span></div>
</nav>
<!-- canvas-deploy:view -->
<?php
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$cdDefaults = $canvasDeploy['defaults'];
$cdScopeValue = (string)$cdDefaults['scope_value'];
?>
<div class="container my-4">
<p class="lead">Install the SLO Cloud LTI 1.1 tool in your Canvas courses. Connect with your Canvas API token, then choose the courses. Only what a course is missing is added, so this can be run again at any time.</p>

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
                        <div class="form-text" id="cdOpHelp">Removing deletes the course navigation tool and any module link to it. An emptied, unpublished module with the module name set below is deleted too. A tool installed at the account level is reported, not removed.</div>
                    </fieldset>
                    <hr>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdLtiOwn" <?= $cdServerLti ? '' : 'checked disabled' ?>>
                        <label class="form-check-label" for="cdLtiOwn">Enter the SLO Cloud LTI settings for this run</label>
                    </div>
                    <p class="form-text" id="cdLtiHelp"><?= $cdServerLti
                        ? 'Not needed if SLO Cloud is already installed in these courses, or to use the settings saved on this server. Tick it to type your own. They are used for this run only and are never saved.'
                        : 'Installing needs the launch URL, consumer key and shared secret; removing needs only the launch URL. They are used for this run only and are never saved.' ?></p>
                    <div id="cdLtiFields" class="<?= $cdServerLti ? 'd-none' : '' ?>">
                    <div class="mb-3">
                        <label for="cdLtiUrl" class="form-label">LTI launch URL</label>
                        <input type="url" class="form-control" id="cdLtiUrl" name="lti_launch_url" autocomplete="off" spellcheck="false"
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
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">2</span> Connect to Canvas</h2>
                </div>
                <div class="card-body">
                    <div class="d-none" id="cdConnectedBar">
                        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0">
                            <span id="cdConnectedText" role="status" aria-live="polite"><i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i><strong>Connected</strong></span>
                            <button type="button" class="btn btn-outline-success btn-sm" id="cdLogout"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Log out</button>
                        </div>
                    </div>
                    <div id="cdConnectFields">
                    <div class="mb-3">
                        <label for="cdBaseUrl" class="form-label">Canvas URL</label>
                        <input type="url" class="form-control" id="cdBaseUrl" name="base_url" value="<?= $h($cdDefaults['base_url']) ?>"
                            placeholder="https://college.instructure.com" autocomplete="url" spellcheck="false" required
                            aria-describedby="cdBaseUrlHelp cdBaseUrlError">
                        <div id="cdBaseUrlHelp" class="form-text">Your institution's Canvas address, for example https://college.instructure.com.</div>
                        <div id="cdBaseUrlError" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="cdToken" class="form-label">Canvas API token</label>
                        <input type="password" class="form-control" id="cdToken" name="canvas_token" autocomplete="off" spellcheck="false" required
                            aria-describedby="cdTokenHelp cdTokenError">
                        <div id="cdTokenHelp" class="form-text">Create one in Canvas under Account &gt; Settings &gt; New Access Token. A teacher token can deploy to the courses you teach; a Canvas account admin token also unlocks whole terms and accounts. It is never saved. After you connect it stays in this page so you can run again; Log out, closing or reloading the page forgets it, and during a run the server keeps only an encrypted copy until the run ends.</div>
                        <div id="cdTokenError" class="invalid-feedback"></div>
                    </div>
                    <button type="button" class="btn btn-primary" id="cdTestConnection">Connect and list my courses</button>
                    </div>
                    <div id="cdConnectionStatus" class="mt-3" role="status" aria-live="polite"></div>
                </div>
            </div>

            <div id="cdAfterConnect" class="d-none">
            <div class="card mb-4" id="cdCoursesCard">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">3</span> Choose courses</h2>
                </div>
                <div class="card-body">
                    <fieldset class="border-0 p-0 m-0" aria-describedby="cdScopeError">
                        <legend class="form-label fs-6">Which courses should be checked?</legend>
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
                        <p class="form-text d-none" id="cdAdminNote">Term and all-course options are shown because this token is a Canvas account admin for <span id="cdAdminAccounts">an account</span>.</p>
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
                        <p class="form-text mb-0 d-none" id="cdScopeAllNote">All courses in the account are checked; courses that already have SLO Cloud are left alone.</p>
                        <div id="cdScopeError" class="invalid-feedback"></div>
                    </fieldset>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title d-flex align-items-center gap-2"><span class="badge rounded-pill text-bg-primary">4</span> What to install</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="cdLinkName" class="form-label">Link name</label>
                        <input type="text" class="form-control" id="cdLinkName" name="link_name" maxlength="100" value="<?= $h($cdDefaults['link_name']) ?>" required
                            aria-describedby="cdLinkNameHelp cdLinkNameError">
                        <div id="cdLinkNameHelp" class="form-text">Shown as the course navigation tab (instructors only) and as the module link title.</div>
                        <div id="cdLinkNameError" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdCreateNav" name="create_nav" checked>
                        <label class="form-check-label" for="cdCreateNav">Add a Course Navigation link to SLO Cloud</label>
                        <div class="form-text">Courses that already have the nav link are left alone. Where SLO Cloud comes from the account without a nav link, a course copy of the tool is added to carry it (needs the key and secret).</div>
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
                        <div class="form-text">Off by default, so a tool that already works is left alone. Course tools with the same launch URL are given this key and secret; a dry run lists which ones. A tool installed at the account level can't be changed from a course.</div>
                    </div>
                    <div class="form-check mb-2 d-none" id="cdFixAccountNavWrap">
                        <input class="form-check-input" type="checkbox" id="cdFixAccountNav">
                        <label class="form-check-label" for="cdFixAccountNav">Add the nav link to the account's SLO Cloud tool</label>
                        <div class="form-text">For an account-level SLO Cloud tool that has no Course Navigation link. Adds it once on the account tool, so every course under that account shows SLO Cloud. Needs an account admin token, not the key or secret. A dry run only says whether it would.</div>
                    </div>
                    <div class="mb-0 mt-3">
                        <label for="cdDelay" class="form-label">Delay between courses (milliseconds)</label>
                        <input type="number" class="form-control" id="cdDelay" name="course_delay_ms" min="0" max="10000" step="50" inputmode="numeric"
                            value="<?= $h($cdDefaults['course_delay_ms']) ?>" required aria-describedby="cdDelayHelp cdDelayError">
                        <div id="cdDelayHelp" class="form-text">A pause between courses (0 to 10,000) to go easy on Canvas during busy hours.</div>
                        <div id="cdDelayError" class="invalid-feedback"></div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
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
                    <p class="form-text mt-2 mb-0">A dry run checks every course and reports what would change without writing anything to Canvas.</p>
                </div>
            </div>
            </div>
        </form>

        <div class="card mb-4 d-none" id="cdProgressCard">
            <div class="card-header">
                <h2 class="card-title" id="cdProgressTitle" tabindex="-1">Run in progress</h2>
            </div>
            <div class="card-body">
                <p id="cdProgressStatus" class="mb-2" role="status" aria-live="polite"></p>
                <div class="progress mb-3" id="cdProgressBar" role="progressbar" aria-label="Run in progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                </div>
                <dl class="row mb-3" id="cdCounts"></dl>
                <div id="cdRunError" class="alert alert-danger d-none" role="alert"></div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary d-none" id="cdRetry">Retry</button>
                    <button type="button" class="btn btn-outline-danger" id="cdCancel">Cancel run</button>
                </div>
                <ul id="cdRunNotes" class="mt-3 mb-0"></ul>
            </div>
        </div>

        <div class="card mb-4 d-none" id="cdResultsCard">
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
        <div class="card mb-4">
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
                </ul>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">What gets installed</h2>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>A course navigation tab that only instructors, TAs, and designers can see.</li>
                    <li>Optionally, an unpublished module with a link to SLO Cloud.</li>
                    <li>Only what a course is missing is added. Where SLO Cloud comes from the account without a nav link, a course copy of the tool carries the nav link.</li>
                    <li>Blueprint child courses are skipped; deploy to the Blueprint course and let sync apply it.</li>
                    <li>Keep this tab open during a run. If it closes, run the same selection again to continue.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
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
        progressBar: byId('cdProgressBar'), counts: byId('cdCounts'), runError: byId('cdRunError'), retry: byId('cdRetry'),
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
        els.selectedCount.textContent = selected.size + ' selected of ' + courses.length + ' courses'
            + (hidden ? ' (' + hidden + ' not shown by the current filter, still included in the run).' : '.');
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
            maxSelectable = listing.max_selectable || maxSelectable;
            setCourses(listing.courses);
            syncOperation();
            loadFlags();
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
        showAlert(els.connection, 'info', '');
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

    const beforeUnload = (event) => { event.preventDefault(); event.returnValue = ''; };

    function endRun(title, message) {
        window.removeEventListener('beforeunload', beforeUnload);
        run.active = false;
        run.runKey = null;
        els.progressTitle.textContent = title;
        els.progressStatus.textContent = message;
        els.progressBar.classList.add('d-none');
        els.cancel.classList.add('d-none');
        els.retry.classList.add('d-none');
        lockForm(false);
        els.progressTitle.focus();
    }

    async function closeRun(cancelled) {
        let counts = run.counts;
        try {
            const response = await post(cancelled ? 'run_cancel' : 'run_finish', { run_id: run.runId, run_key: run.runKey });
            counts = response.counts || counts;
        } catch (error) {
            if (error.error !== 'run_missing') run.notes.push('The run ended but could not be closed cleanly: ' + (error.message || 'unknown error'));
        }
        if (counts) renderCounts(counts, run.dryRun);
        const label = run.dryRun ? 'Dry run' : (run.operation === 'remove' ? 'Removal' : 'Deployment');
        const processed = counts ? counts.processed : run.results.length;
        endRun(
            cancelled ? label + ' cancelled' : label + ' complete',
            (cancelled ? 'Stopped after ' : 'Finished: ') + processed + ' courses checked. Review the results below or download the CSV.'
        );
    }

    async function loop() {
        els.runError.classList.add('d-none');
        els.retry.classList.add('d-none');
        els.progressBar.classList.remove('d-none');
        while (run && run.active) {
            if (run.cancelRequested) { await closeRun(true); return; }
            let batch;
            try {
                // "received" lets the server resend rows from a reply that never arrived.
                batch = await post('run_batch', { run_id: run.runId, run_key: run.runKey, received: String(run.results.length) });
            } catch (error) {
                if (['run_missing', 'run_key_rejected', 'run_forbidden', 'run_locked'].includes(error.error)) {
                    endRun('Run stopped', error.message || 'The run is no longer active.');
                    return;
                }
                run.paused = true;
                els.runError.textContent = (error.message || 'The batch failed.') + ' Retry continues from the same place.';
                addLogLink(els.runError);
                els.runError.classList.remove('d-none');
                els.retry.classList.remove('d-none');
                els.progressBar.classList.add('d-none');
                els.progressStatus.textContent = 'Paused after an error.';
                return;
            }
            run.counts = batch.counts;
            const from = typeof batch.results_from === 'number' ? batch.results_from : run.results.length;
            appendResults(batch.results.slice(Math.max(0, run.results.length - from)));
            renderCounts(batch.counts, run.dryRun);
            els.progressStatus.textContent = 'Processed ' + batch.counts.processed + ' courses so far. Last batch: '
                + batch.processed + ' in ' + (batch.elapsed_ms / 1000).toFixed(1) + ' seconds.';
            if (batch.done) { await closeRun(false); return; }
            if (batch.delay_ms > 0) await sleep(batch.delay_ms);
        }
    }

    els.retry.addEventListener('click', () => {
        if (!run || !run.active) return;
        run.paused = false;
        els.progressStatus.textContent = 'Retrying…';
        loop();
    });

    els.cancel.addEventListener('click', () => {
        if (!run || !run.active) return;
        if (run.paused) { closeRun(true); return; }
        run.cancelRequested = true;
        els.cancel.disabled = true;
        els.progressStatus.textContent = 'Cancelling after the current batch…';
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
            run = { active: true, runId: started.run_id, runKey: started.run_key, dryRun, operation: settings.operation, results: [], counts: null, notes: [], cancelRequested: false, paused: false };
            window.addEventListener('beforeunload', beforeUnload);
            els.progressCard.classList.remove('d-none');
            els.resultsCard.classList.remove('d-none');
            els.cancel.classList.remove('d-none');
            els.cancel.disabled = false;
            els.progressTitle.textContent = dryRun ? 'Dry run in progress' : (settings.operation === 'remove' ? 'Removal in progress' : 'Deployment in progress');
            els.progressStatus.textContent = 'Starting…';
            els.runNotes.replaceChildren(...(started.notes || []).map((note) => {
                const li = document.createElement('li');
                li.textContent = note;
                return li;
            }));
            renderCounts({}, dryRun);
            renderResults();
            els.progressTitle.focus();
            await loop();
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
    syncLti();
    form.addEventListener('input', () => { resetDeployConfirm(); renderSummary(); });
    form.addEventListener('change', () => { resetDeployConfirm(); syncScope(); });
    syncScope();
})();
</script>
<!-- /canvas-deploy:view -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
