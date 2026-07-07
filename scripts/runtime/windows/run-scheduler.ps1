#Requires -Version 5.1
<#
.SYNOPSIS
    Run the supervised Windows scheduler for a staging/production BLB instance.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime
New-Item -ItemType Directory -Force -Path $script:LogsDir | Out-Null
$schedulerLog = Join-Path $script:LogsDir 'scheduler.log'

Push-Location $script:ProjectRoot
try {
    while ($true) {
        Write-BLBRuntimeLog -Role 'scheduler' -Message 'Starting Laravel scheduler.'
        & $script:PhpExe (Join-Path $script:ProjectRoot 'artisan') schedule:work >> $schedulerLog 2>&1
        $exitCode = $LASTEXITCODE
        Write-BLBRuntimeLog -Role 'scheduler' -Message "Scheduler exited with code $exitCode; restarting in 5 seconds."
        Start-Sleep -Seconds 5
    }
} finally {
    Pop-Location
}
