#Requires -Version 5.1
<#
.SYNOPSIS
    Gracefully reload supervised Windows BLB workers after a code update.
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime

$configUrl = 'http://{0}:{1}/config/apps/frankenphp' -f $script:CaddyAdminHost, $script:CaddyAdminPort
$restartUrl = 'http://{0}:{1}/frankenphp/workers/restart' -f $script:CaddyAdminHost, $script:CaddyAdminPort

Write-Output ('==> reloading web workers ({0}:{1})' -f $script:CaddyAdminHost, $script:CaddyAdminPort)
$body = (& curl.exe -sS $configUrl 2>$null | Out-String)
if (-not $body.Trim()) {
    throw "Could not read FrankenPHP config at $configUrl - is the server running on this admin port?"
}

$code = (& curl.exe -sS -o NUL -w '%{http_code}' -X POST $restartUrl 2>$null | Out-String).Trim()
if ($code -ne '200') {
    throw "FrankenPHP worker reload failed at $restartUrl (HTTP $code)."
}
Write-Output '    web workers reloaded (HTTP 200)'

Write-Output '==> reloading queue workers (queue:restart)'
Push-Location $script:ProjectRoot
try {
    & $script:PhpExe (Join-Path $script:ProjectRoot 'artisan') queue:restart
    if ($LASTEXITCODE -ne 0) {
        throw "queue:restart failed with exit code $LASTEXITCODE"
    }
} finally {
    Pop-Location
}

Write-Output 'Reload complete.'
