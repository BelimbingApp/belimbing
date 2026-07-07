#Requires -Version 5.1
<#
.SYNOPSIS
    Run the supervised Windows FrankenPHP server for a staging/production BLB instance.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime
Set-BLBCaddyEnvironment

$workerPath = Join-Path $script:ProjectRoot 'public\frankenphp-worker.php'
$octaneStub = Join-Path $script:ProjectRoot 'vendor\laravel\octane\src\Commands\stubs\frankenphp-worker.php'
if (-not (Test-Path $workerPath)) {
    Copy-Item -Path $octaneStub -Destination $workerPath
}

New-Item -ItemType Directory -Force -Path $script:LogsDir | Out-Null
$serverLog = Join-Path $script:LogsDir 'server.log'

Push-Location $script:ProjectRoot
try {
    while ($true) {
        try {
            Assert-BLBPortAvailable -Port $script:HttpsPort -Purpose 'BLB HTTPS origin'
            Assert-BLBPortAvailable -Port $script:CaddyAdminPort -Purpose 'BLB Caddy admin'
            Write-BLBOctaneServerState
            Write-BLBRuntimeLog -Role 'server' -Message "Starting FrankenPHP for $script:AppEnv/$script:InstanceName on $env:CADDY_BIND_ADDRESS`:$script:HttpsPort (admin $script:CaddyAdminHost`:$script:CaddyAdminPort)."

            $previousErrorActionPreference = $ErrorActionPreference
            $ErrorActionPreference = 'Continue'
            try {
                & $script:FrankenPhpExe run --config Caddyfile --adapter caddyfile >> $serverLog 2>&1
            } finally {
                $ErrorActionPreference = $previousErrorActionPreference
            }
            $exitCode = $LASTEXITCODE
            Write-BLBRuntimeLog -Role 'server' -Message "FrankenPHP exited with code $exitCode; restarting in 5 seconds."
        } catch {
            Write-BLBRuntimeLog -Role 'server' -Message "FrankenPHP failed before startup: $($_.Exception.Message); retrying in 10 seconds."
            Start-Sleep -Seconds 10
            continue
        }

        Start-Sleep -Seconds 5
    }
} finally {
    Pop-Location
}
