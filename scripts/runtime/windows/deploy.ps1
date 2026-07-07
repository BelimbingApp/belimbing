#Requires -Version 5.1
<#
.SYNOPSIS
    Git-driven deploy for a supervised Windows BLB instance.
#>

[CmdletBinding()]
param([switch] $SkipBuild)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime

$composer = Join-Path $script:DevopsDir 'composer.phar'

function Invoke-BLBPull {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Label
    )

    if (-not (Test-Path (Join-Path $Path '.git'))) {
        return
    }

    Write-Output "==> pull $Label"
    $git = Resolve-BLBGitPath
    & $git -C $Path pull --ff-only
    if ($LASTEXITCODE -ne 0) {
        throw "git pull failed for $Label. Resolve the working tree or branch divergence before deploying."
    }
}

Invoke-BLBPull $script:ProjectRoot 'belimbing'
Invoke-BLBPull (Join-Path $script:ProjectRoot 'app\Modules\Commerce') 'commerce'
Invoke-BLBPull (Join-Path $script:ProjectRoot 'extensions\ham') 'ham'

Push-Location $script:ProjectRoot
try {
    if (Test-Path $composer) {
        Write-Output '==> composer install'
        & $script:PhpExe $composer install --no-interaction --prefer-dist --optimize-autoloader
        if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
    } else {
        Write-Warning "Composer not found at $composer; skipping composer install. Run scripts/setup.ps1 if this is unexpected."
    }

    if (-not $SkipBuild) {
        $bun = Resolve-BLBBunPath
        if ($bun) {
            Write-Output '==> bun install + build'
            & $bun install --backend copyfile
            if ($LASTEXITCODE -ne 0) { throw 'bun install failed' }
            & $bun run build
            if ($LASTEXITCODE -ne 0) { throw 'asset build failed' }
        } else {
            throw 'Bun not found. Run scripts/setup.ps1 to pin BLB_BUN_EXECUTABLE, or rerun deploy.ps1 with -SkipBuild only for a deliberate no-asset deploy.'
        }
    }

    Write-Output '==> migrate'
    & $script:PhpExe (Join-Path $script:ProjectRoot 'artisan') migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'migrate failed' }
} finally {
    Pop-Location
}

& (Join-Path $script:RuntimeDir 'reload.ps1')

if (-not (Invoke-BLBOriginCheck -TimeoutSeconds 15)) {
    throw "Deploy finished but local origin did not respond at $(Get-BLBOriginUrl)."
}

if (-not (Invoke-BLBPublicHealthCheck -TimeoutSeconds 20)) {
    throw 'Deploy finished but the configured public health check failed.'
}

Write-Output ''
Write-Output "Deploy complete. Verify: $(Get-BLBOriginUrl)"
