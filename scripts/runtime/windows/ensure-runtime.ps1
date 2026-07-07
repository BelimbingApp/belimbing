#Requires -Version 5.1
<#
.SYNOPSIS
    Ensure the supervised Windows runtime is installed, running, and reachable.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

if ($script:AppEnv -notin @('staging', 'production')) {
    throw "ensure-runtime.ps1 is only for APP_ENV=staging or production. Current APP_ENV=$script:AppEnv."
}

$tasks = Get-BLBTaskNames
$managedTasks = @($tasks.Server, $tasks.Queue, $tasks.Scheduler, $tasks.Health, $tasks.Backup)
$registered = @{}
foreach ($taskName in $managedTasks) {
    $registered[$taskName] = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
}

$missing = @($managedTasks | Where-Object { -not $registered[$_] })
if ($missing.Count -gt 0) {
    if (Test-BLBCurrentProcessAdmin) {
        Write-Output "Installing missing supervised tasks for $script:AppEnv/$script:InstanceName..."
        & (Join-Path $script:RuntimeDir 'install-services.ps1') -StartNow
    } else {
        Write-Warning "Supervised BLB tasks are not installed: $($missing -join ', ')"
        Write-Output 'Run this once from an elevated PowerShell:'
        Write-Output "  & '$script:RuntimeDir\install-services.ps1' -StartNow"
        throw 'Supervised BLB tasks are not installed.'
    }
}

foreach ($taskName in @($tasks.Server, $tasks.Queue, $tasks.Scheduler, $tasks.Health)) {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task -and $task.State -ne 'Running') {
        Write-Output "Starting $taskName..."
        Start-ScheduledTask -TaskName $taskName
    }
}

$healthy = $false
for ($attempt = 1; $attempt -le 12; $attempt++) {
    if (Invoke-BLBOriginCheck -TimeoutSeconds 5) {
        $healthy = $true
        break
    }

    Start-Sleep -Seconds 5
}

Write-Output ''
Write-Output "Belimbing $script:AppEnv/$script:InstanceName runtime"
Write-Output "  Origin:      $(Get-BLBOriginUrl)"
Write-Output "  Bind:        $(Get-BLBBindAddress):$script:HttpsPort"
Write-Output "  Admin API:   $script:CaddyAdminHost`:$script:CaddyAdminPort"
Write-Output "  Logs:        $script:LogsDir"
Write-Output "  Task prefix: $script:TaskPrefix"
Write-Output ''

foreach ($taskName in $managedTasks) {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task) {
        Write-Output ('  {0,-32} {1}' -f $taskName, $task.State)
    }
}

Write-Output ''
if ($healthy) {
    Write-Output 'OK  Local origin responded.'
} else {
    Write-Warning "Local origin did not respond after starting tasks. Check $script:LogsDir and port ownership."
    throw 'Local origin did not respond after starting tasks.'
}
