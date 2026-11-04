<#
.SYNOPSIS
    Creates (or removes) the Windows scheduled task that runs the Deploy to Canvas queue
    worker every minute. Run it in an elevated PowerShell on the web server.

.DESCRIPTION
    The task runs bin\run-worker.ps1 every minute, indefinitely. If the last run is still
    going, the new one is skipped. Runs as SYSTEM unless -User is given; the account must be
    able to write to the app's var folder (or data_dir).

.EXAMPLE
    .\install-task.ps1
    Php mode, php.exe from PATH, runs as SYSTEM.

.EXAMPLE
    .\install-task.ps1 -PhpPath 'C:\Program Files\PHP\v8.3\php.exe' -User 'DOMAIN\svc-canvas'
    Asks for that account's password.

.EXAMPLE
    .\install-task.ps1 -Mode Url -Url https://localhost/cron.php -SkipCertificateCheck
    Calls the web worker instead (needs worker_key in config.local.php).

.EXAMPLE
    .\install-task.ps1 -Uninstall
#>
[CmdletBinding()]
param(
    [string]$TaskName = 'Deploy to Canvas queue worker',

    [ValidateSet('Php', 'Url')]
    [string]$Mode = 'Php',

    [string]$PhpPath = '',

    [string]$Url = 'https://localhost/cron.php',

    [switch]$SkipCertificateCheck,

    # Account to run as, e.g. DOMAIN\svc-canvas. Default: SYSTEM.
    [string]$User = '',

    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this in an elevated PowerShell (Run as administrator).'
}

if ($Uninstall) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Output "Removed the scheduled task '$TaskName'."
    } else {
        Write-Output "No scheduled task named '$TaskName'."
    }
    return
}

$script = Join-Path $PSScriptRoot 'run-worker.ps1'
if (-not (Test-Path -LiteralPath $script)) { throw "run-worker.ps1 not found next to this script ($script)." }

$arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$script`" -Mode $Mode"
if ($Mode -eq 'Php' -and $PhpPath -ne '') { $arguments += " -PhpPath `"$PhpPath`"" }
if ($Mode -eq 'Url') {
    $arguments += " -Url `"$Url`""
    if ($SkipCertificateCheck) { $arguments += ' -SkipCertificateCheck' }
}

# Try it once first, so a wrong php.exe or key shows up now instead of in the task history.
Write-Output 'Running the worker once to check the settings...'
$check = @{ Mode = $Mode; Url = $Url; SkipCertificateCheck = $SkipCertificateCheck }
if ($PhpPath -ne '') { $check.PhpPath = $PhpPath }
& $script @check
if ($LASTEXITCODE -ne 0) { throw 'The worker did not run; fix the problem above, then try again.' }

$action = New-ScheduledTaskAction -Execute (Join-Path $PSHOME 'powershell.exe') -Argument $arguments -WorkingDirectory (Split-Path -Parent $PSScriptRoot)
if (-not (Test-Path -LiteralPath (Join-Path $PSHOME 'powershell.exe'))) {
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory (Split-Path -Parent $PSScriptRoot)
}
# Every minute, starting now, with no end date.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

$task = @{
    TaskName = $TaskName
    Action = $action
    Trigger = $trigger
    Settings = $settings
    Description = 'Runs bin\run-worker.ps1 for Deploy to Canvas every minute (see README, Queue worker).'
    Force = $true
}
if ($User -eq '') {
    $task.User = 'SYSTEM'
    $task.RunLevel = 'Highest'
} else {
    $credential = Get-Credential -UserName $User -Message "Password for $User (the task runs as this account)"
    $task.User = $credential.UserName
    $task.Password = $credential.GetNetworkCredential().Password
}
Register-ScheduledTask @task | Out-Null

Write-Output "Scheduled task '$TaskName' created: every minute, as $($task.User)."
Write-Output "Log: var\worker.log in the app folder. Remove it with: .\install-task.ps1 -Uninstall"
