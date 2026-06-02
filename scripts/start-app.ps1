#Requires -Version 5.1
<#
.SYNOPSIS
    Start Belimbing on native Windows with FrankenPHP.
#>

[CmdletBinding()]
param(
    [int] $AppPort = 8000,
    [int] $VitePort = 5173,
    [int] $CaddyAdminPort = 2020,
    [switch] $NoQueue,
    [switch] $NoVite,
    [switch] $Watch
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path (Join-Path $ScriptDir '..')
$ProjectRootPath = $ProjectRoot.Path
$DevopsDir = Join-Path $ProjectRootPath 'storage\app\.devops'
$PhpConfigDir = Join-Path $DevopsDir 'php'
$PhpIniPath = Join-Path $PhpConfigDir 'php.ini'
$FrankenPhpWorkerPath = Join-Path $ProjectRootPath 'public\frankenphp-worker.php'

function Get-EnvValue {
    param(
        [string] $Path,
        [string] $Key,
        [string] $Default = ''
    )

    if (-not (Test-Path $Path)) {
        return $Default
    }

    $match = Get-Content -Path $Path | Where-Object { $_ -match "^\s*$([regex]::Escape($Key))=(.*)$" } | Select-Object -First 1
    if (-not $match) {
        return $Default
    }

    return ($match -replace "^\s*$([regex]::Escape($Key))=", '').Trim('"')
}

function Resolve-FrankenPhpHome {
    if ($env:FRANKENPHP_INSTALL) {
        return $env:FRANKENPHP_INSTALL
    }

    $frankenPhpCommand = Get-Command frankenphp -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($frankenPhpCommand) {
        return Split-Path -Parent $frankenPhpCommand.Source
    }

    return Join-Path $HOME '.frankenphp'
}

function Resolve-BunPath {
    $bun = Resolve-NativeCommandPath 'bun'
    if ($bun) {
        return $bun
    }

    $wingetPackages = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
    if (Test-Path $wingetPackages) {
        $wingetBun = Get-ChildItem -Recurse -Filter bun.exe $wingetPackages -ErrorAction SilentlyContinue |
            Select-Object -First 1 -ExpandProperty FullName
        if ($wingetBun) {
            return $wingetBun
        }
    }

    return ''
}

function Resolve-NativeCommandPath {
    param(
        [string] $Command
    )

    $nativeExtensions = @('.exe', '.cmd', '.bat', '.com')

    $commandInfo = Get-Command $Command -All -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandType -eq 'Application' -and $nativeExtensions -contains $_.Extension } |
        Select-Object -First 1

    if ($commandInfo) {
        return $commandInfo.Source
    }

    foreach ($pathEntry in ($env:Path -split [IO.Path]::PathSeparator)) {
        if (-not $pathEntry) {
            continue
        }

        foreach ($extension in $nativeExtensions) {
            $candidate = Join-Path $pathEntry "$Command$extension"
            if (Test-Path $candidate) {
                return $candidate
            }
        }
    }

    return ''
}

function Start-BelimbingProcess {
    param(
        [string] $Name,
        [string] $FilePath,
        [string[]] $Arguments
    )

    Write-Information "Starting $Name..." -InformationAction Continue
    $process = Start-Process -FilePath $FilePath -ArgumentList $Arguments -WorkingDirectory $ProjectRootPath -PassThru -NoNewWindow
    return [pscustomobject]@{
        Name = $Name
        Process = $process
    }
}

$envPath = Join-Path $ProjectRootPath '.env'
$frankenPhpHome = Resolve-FrankenPhpHome
$phpExe = Join-Path $frankenPhpHome 'php.exe'
$frankenPhpExe = Join-Path $frankenPhpHome 'frankenphp.exe'

if (-not (Test-Path $phpExe) -or -not (Test-Path $frankenPhpExe)) {
    throw "FrankenPHP was not found in '$frankenPhpHome'. Set FRANKENPHP_INSTALL, add FrankenPHP to PATH, or run scripts/setup.ps1 first."
}

if (-not (Test-Path $PhpIniPath)) {
    throw "Project PHP ini not found at '$PhpIniPath'. Run scripts/setup.ps1 first."
}

$frontendDomain = Get-EnvValue $envPath 'FRONTEND_DOMAIN' 'local.blb.lara'
$backendDomain = Get-EnvValue $envPath 'BACKEND_DOMAIN' 'local.api.blb.lara'
$appPortValue = Get-EnvValue $envPath 'APP_PORT' "$AppPort"
$vitePortValue = Get-EnvValue $envPath 'VITE_PORT' "$VitePort"
$AppPort = [int] $appPortValue
$VitePort = [int] $vitePortValue

$env:Path = "$frankenPhpHome;$env:Path"
$env:PHPRC = $PhpConfigDir
$env:APP_DOMAIN = $frontendDomain
$env:BACKEND_DOMAIN = $backendDomain
$env:APP_PORT = "$AppPort"
$env:VITE_PORT = "$VitePort"
$env:VITE_HOST = '127.0.0.1'
$env:HTTPS_PORT = '443'
$env:APP_BIND_HOST = '127.0.0.1'
$env:CADDY_SCHEME = 'https'
$env:TLS_DIRECTIVE = 'tls internal'
$env:CADDY_SERVER_ADMIN_HOST = '127.0.0.1'
$env:CADDY_SERVER_ADMIN_PORT = "$CaddyAdminPort"
$env:CADDY_LOG_DIR = Join-Path $ProjectRootPath '.caddy\logs'
$env:APP_PUBLIC_PATH = Join-Path $ProjectRootPath 'public'
$env:APP_BASE_PATH = $ProjectRootPath
$env:LARAVEL_OCTANE = '1'
$env:MAX_REQUESTS = '500'
$env:REQUEST_MAX_EXECUTION_TIME = '0'
$env:CADDY_SERVER_WORKER_DIRECTIVE = ''
$env:CADDY_SERVER_WATCH_DIRECTIVES = ''

# Local OPcache/JIT tuning (raises the 10k file cap, enables tracing JIT, more
# memory). Picked up by FrankenPHP and the queue worker without admin rights.
$perfIniDir = Join-Path $ProjectRootPath '.php.d'
if (Test-Path (Join-Path $perfIniDir 'perf.ini')) {
    $env:PHP_INI_SCAN_DIR = $perfIniDir
}

New-Item -ItemType Directory -Force -Path $env:CADDY_LOG_DIR | Out-Null

Push-Location $ProjectRootPath
try {
    if (-not (Test-Path (Join-Path $ProjectRootPath 'vendor\autoload.php'))) {
        throw "PHP dependencies are missing. Run scripts/setup.ps1 first."
    }

    if (-not (Test-Path $FrankenPhpWorkerPath)) {
        Copy-Item -Path (Join-Path $ProjectRootPath 'vendor\laravel\octane\src\Commands\stubs\frankenphp-worker.php') -Destination $FrankenPhpWorkerPath
    }

    $processes = @()

    $frankenPhpArgs = @('run', '--config', 'Caddyfile', '--adapter', 'caddyfile')
    if ($Watch) {
        $frankenPhpArgs += '--watch'
    }

    $processes += Start-BelimbingProcess -Name 'FrankenPHP / Octane' -FilePath $frankenPhpExe -Arguments $frankenPhpArgs

    if (-not $NoQueue) {
        $processes += Start-BelimbingProcess -Name 'Queue worker' -FilePath $phpExe -Arguments @('artisan', 'queue:work', '--queue=ai-agent-tasks,ai-background-commands,ai-schedules,default', '--tries=1', '--timeout=900', '--sleep=1')
    }

    if (-not $NoVite) {
        $bun = Resolve-BunPath
        if ($bun) {
            $processes += Start-BelimbingProcess -Name 'Vite' -FilePath $bun -Arguments @('run', 'dev', '--', '--host', '127.0.0.1', '--port', "$VitePort")
        } else {
            $npm = Resolve-NativeCommandPath 'npm'
            if ($npm) {
                $processes += Start-BelimbingProcess -Name 'Vite' -FilePath $npm -Arguments @('run', 'dev', '--', '--host', '127.0.0.1', '--port', "$VitePort")
            } else {
                Write-Host "Bun/npm is not available, so Vite was not started." -ForegroundColor Yellow
            }
        }
    }

    Write-Host ""
    Write-Host "Belimbing is starting at https://$frontendDomain" -ForegroundColor Green
    Write-Host "Press Ctrl+C to stop this launcher. Child processes may need closing if PowerShell is terminated abruptly."

    while ($true) {
        Start-Sleep -Seconds 2
        $exited = @($processes | Where-Object { $_.Process.HasExited })
        if ($exited.Count -gt 0) {
            foreach ($item in $exited) {
                Write-Host "$($item.Name) exited with code $($item.Process.ExitCode)." -ForegroundColor Yellow
            }
            break
        }
    }
} finally {
    foreach ($item in $processes) {
        if ($item.Process -and -not $item.Process.HasExited) {
            Stop-Process -Id $item.Process.Id -Force -ErrorAction SilentlyContinue
        }
    }
    Pop-Location
}
