#Requires -Version 5.1
<#
.SYNOPSIS
    Local health check and self-healing entrypoint for supervised Windows BLB instances.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

$tasks = Get-BLBTaskNames

function Start-BLBTaskIfStopped {
    param([Parameter(Mandatory = $true)][string] $TaskName)

    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) {
        Write-BLBRuntimeLog -Role 'health' -Message "Task $TaskName is not registered."
        return
    }

    if ($task.State -ne 'Running') {
        Write-BLBRuntimeLog -Role 'health' -Message "Starting stopped task $TaskName."
        Start-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    }
}

Start-BLBTaskIfStopped -TaskName $tasks.Queue
Start-BLBTaskIfStopped -TaskName $tasks.Scheduler

if (-not (Invoke-BLBOriginCheck -TimeoutSeconds 10)) {
    Write-BLBRuntimeLog -Role 'health' -Message "Origin check failed for $(Get-BLBOriginUrl); starting $($tasks.Server)."
    Start-BLBTaskIfStopped -TaskName $tasks.Server
    Start-Sleep -Seconds 10

    if (-not (Invoke-BLBOriginCheck -TimeoutSeconds 10)) {
        Write-BLBRuntimeLog -Role 'health' -Message "Origin still unhealthy after restart attempt."
        exit 1
    }
}

if (-not (Invoke-BLBPublicHealthCheck -TimeoutSeconds 15)) {
    Write-BLBRuntimeLog -Role 'health' -Message 'Public ingress health check failed.'
    exit 1
}

Write-BLBRuntimeLog -Role 'health' -Message 'Health check passed.'
