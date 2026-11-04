<#
.SYNOPSIS
    Runs the Deploy to Canvas queue worker once (about 45 seconds), for Task Scheduler.

.DESCRIPTION
    Php mode (default) starts bin\worker.php with php.exe. It runs outside IIS and the
    gateway, so no web timeouts apply. Url mode calls public/cron.php over HTTP instead,
    which needs worker_key set in config.local.php.

    Each run appends one line to var\worker.log (kept under about 1 MB). The exit code is 0
    when the worker ran or another worker was already running, and 1 when it failed.

.EXAMPLE
    .\run-worker.ps1
    Runs the worker with the php.exe found on PATH (or in C:\PHP).

.EXAMPLE
    .\run-worker.ps1 -PhpPath 'C:\Program Files\PHP\v8.3\php.exe'

.EXAMPLE
    .\run-worker.ps1 -Mode Url -Url https://localhost/cron.php
    Calls the web worker. The key comes from -WorkerKey, the CANVAS_WORKER_KEY environment
    variable, or worker_key in config.local.php, in that order.
#>
[CmdletBinding()]
param(
    [ValidateSet('Php', 'Url')]
    [string]$Mode = 'Php',

    # php.exe to use in Php mode. Default: php.exe on PATH, then C:\PHP, then Program Files.
    [string]$PhpPath = '',

    # Seconds the worker works before exiting (Php mode).
    [ValidateRange(5, 600)]
    [int]$Budget = 45,

    # Address of cron.php (Url mode). Call the server itself so the request skips the gateway.
    [string]$Url = 'https://localhost/cron.php',

    [string]$WorkerKey = '',

    # Accept a certificate that does not match (e.g. calling https://localhost). Url mode only.
    [switch]$SkipCertificateCheck,

    # Where the log goes. Default: var\worker.log in the app folder.
    [string]$LogFile = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Get-ConfigValue([string]$name) {
    # Reads a plain 'name' => 'value' line from config.local.php; good enough for the key.
    $config = Join-Path $root 'config.local.php'
    if (-not (Test-Path -LiteralPath $config)) { return '' }
    $text = Get-Content -LiteralPath $config -Raw
    $pattern = "(?m)^\s*['""]" + [regex]::Escape($name) + "['""]\s*=>\s*['""]([^'""]*)['""]"
    $match = [regex]::Match($text, $pattern)
    if ($match.Success) { return $match.Groups[1].Value }
    return ''
}

if ($LogFile -eq '') {
    $dataDir = Get-ConfigValue 'data_dir'
    if ($dataDir -eq '' -and $env:CANVAS_AUTOMATIONS_DATA) { $dataDir = $env:CANVAS_AUTOMATIONS_DATA }
    if ($dataDir -eq '' -or $dataDir -match '__DIR__') { $dataDir = Join-Path $root 'var' }
    $LogFile = Join-Path $dataDir 'worker.log'
}

function Write-Log([string]$line) {
    $stamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    # One line per run, whatever the reply looked like.
    $entry = "$stamp [$Mode] " + ($line -replace '\s+', ' ').Trim()
    Write-Output $entry
    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        if ((Test-Path -LiteralPath $LogFile) -and (Get-Item -LiteralPath $LogFile).Length -gt 1MB) {
            Move-Item -LiteralPath $LogFile -Destination ($LogFile + '.old') -Force
        }
        Add-Content -LiteralPath $LogFile -Value $entry -Encoding UTF8
    } catch {
        Write-Warning "Could not write $LogFile : $($_.Exception.Message)"
    }
}

function Find-Php {
    if ($PhpPath -ne '') {
        if (Test-Path -LiteralPath $PhpPath) { return $PhpPath }
        throw "php.exe not found at $PhpPath"
    }
    $onPath = Get-Command php.exe, php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($onPath) { return $onPath.Source }
    $candidates = @('C:\PHP\php.exe') + @(Get-ChildItem -Path "$env:ProgramFiles\PHP" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | ForEach-Object { $_.FullName })
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) { return $candidate }
    }
    throw 'php.exe not found. Pass -PhpPath, or add PHP to PATH.'
}

try {
    if ($Mode -eq 'Php') {
        $php = Find-Php
        $worker = Join-Path $root 'bin\worker.php'
        if (-not (Test-Path -LiteralPath $worker)) { $worker = Join-Path $root 'bin/worker.php' }
        # Windows PowerShell 5.1 turns php's stderr into errors; don't let them stop the script.
        $ErrorActionPreference = 'Continue'
        $output = & $php $worker "--budget=$Budget" 2>&1
        $code = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        $text = (($output | ForEach-Object { "$_" }) -join ' ').Trim()
        if ($code -ne 0) {
            Write-Log "FAILED (exit $code): $text"
            exit 1
        }
        Write-Log $text
        exit 0
    }

    # Url mode. "localhost/cron.php" works as well as "https://localhost/cron.php".
    if ($Url -notmatch '^[a-zA-Z][a-zA-Z0-9+.-]*://') { $Url = 'https://' + $Url.TrimStart('/') }
    if ($WorkerKey -eq '') { $WorkerKey = [string]$env:CANVAS_WORKER_KEY }
    if ($WorkerKey -eq '') { $WorkerKey = Get-ConfigValue 'worker_key' }
    if ($WorkerKey -eq '') { throw 'No worker key. Pass -WorkerKey, set CANVAS_WORKER_KEY, or set worker_key in config.local.php.' }

    $request = @{
        Uri = $Url
        Method = 'Post'
        Headers = @{ 'X-Worker-Key' = $WorkerKey }
        UseBasicParsing = $true
        TimeoutSec = 150
    }
    if ($PSVersionTable.PSVersion.Major -ge 6) {
        if ($SkipCertificateCheck) { $request.SkipCertificateCheck = $true }
    } else {
        # Windows PowerShell 5.1: allow TLS 1.2, and skip the certificate check only if asked.
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        if ($SkipCertificateCheck) { [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true } }
    }

    try {
        $response = Invoke-WebRequest @request
        Write-Log ("HTTP {0}: {1}" -f [int]$response.StatusCode, ([string]$response.Content).Trim())
        exit 0
    } catch {
        $status = ''
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        $body = $_.ErrorDetails.Message
        if (-not $body) { $body = $_.Exception.Message }
        Write-Log ("FAILED HTTP {0}: {1}" -f $status, ([string]$body).Trim())
        exit 1
    }
} catch {
    Write-Log "FAILED: $($_.Exception.Message)"
    exit 1
}
