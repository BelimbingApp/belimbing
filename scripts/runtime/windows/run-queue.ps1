#Requires -Version 5.1
<#
.SYNOPSIS
    Run the supervised Windows queue worker for a staging/production BLB instance.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime
New-Item -ItemType Directory -Force -Path $script:LogsDir | Out-Null
$queueLog = Join-Path $script:LogsDir 'queue.log'
$queues = Get-BLBEnvValue -Key 'BLB_QUEUE_WORKER_QUEUES' -Default 'ai-chat-turns,ai-agent-tasks,ai-background-commands,ai-schedules,default'

Push-Location $script:ProjectRoot
try {
    while ($true) {
        Write-BLBRuntimeLog -Role 'queue' -Message "Starting queue worker for queues: $queues."
        & $script:PhpExe (Join-Path $script:ProjectRoot 'artisan') queue:work --queue=$queues --tries=1 --timeout=900 --sleep=1 >> $queueLog 2>&1
        $exitCode = $LASTEXITCODE
        Write-BLBRuntimeLog -Role 'queue' -Message "Queue worker exited with code $exitCode; restarting in 1 second."
        Start-Sleep -Seconds 1
    }
} finally {
    Pop-Location
}
