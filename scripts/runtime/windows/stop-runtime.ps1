#Requires -Version 5.1
<#
.SYNOPSIS
    Stop supervised Windows runtime tasks for a BLB instance.
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

if ($script:AppEnv -notin @('staging', 'production')) {
    throw "stop-runtime.ps1 is only for APP_ENV=staging or production. Current APP_ENV=$script:AppEnv."
}

$tasks = Get-BLBTaskNames
foreach ($taskName in @($tasks.Health, $tasks.Scheduler, $tasks.Queue, $tasks.Server)) {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if (-not $task) {
        Write-Output "$taskName is not registered."
        continue
    }

    if ($task.State -eq 'Running') {
        if ($PSCmdlet.ShouldProcess($taskName, 'Stop scheduled task')) {
            Stop-ScheduledTask -TaskName $taskName
            Write-Output "Stopped $taskName."
        }
    } else {
        Write-Output "$taskName is $($task.State)."
    }
}

Write-Output 'Backup task registration was left intact.'
