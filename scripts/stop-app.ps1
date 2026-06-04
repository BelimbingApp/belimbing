#Requires -Version 5.1
<#
.SYNOPSIS
    Stop Belimbing processes started by scripts/start-app.ps1.
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [int] $VitePort = 5173,
    [int] $CaddyAdminPort = 2020
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path (Join-Path $ScriptDir '..')
$ProjectRootPath = $ProjectRoot.Path
$envPath = Join-Path $ProjectRootPath '.env'

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

function Test-CommandLineContains {
    param(
        [AllowNull()]
        [string] $CommandLine,
        [string[]] $Needles
    )

    if (-not $CommandLine) {
        return $false
    }

    foreach ($needle in $Needles) {
        if ($CommandLine -notlike "*$needle*") {
            return $false
        }
    }

    return $true
}

function Stop-BelimbingProcess {
    param(
        [string] $Name,
        [scriptblock] $Predicate
    )

    $matches = @(Get-CimInstance Win32_Process | Where-Object { & $Predicate $_ })
    if ($matches.Count -eq 0) {
        Write-Host "$Name is not running." -ForegroundColor DarkGray
        return
    }

    foreach ($match in $matches) {
        $target = "$Name (PID $($match.ProcessId))"
        if ($PSCmdlet.ShouldProcess($target, 'Stop process')) {
            Stop-Process -Id $match.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Host "Stopped $target." -ForegroundColor Green
        }
    }
}

function Stop-BelimbingPortListener {
    param(
        [string] $Name,
        [int] $Port
    )

    $listeners = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    if ($listeners.Count -eq 0) {
        Write-Host "$Name is not listening on port $Port." -ForegroundColor DarkGray
        return
    }

    foreach ($processId in $listeners) {
        $target = "$Name (PID $processId, port $Port)"
        if ($PSCmdlet.ShouldProcess($target, 'Stop process')) {
            Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
            Write-Host "Stopped $target." -ForegroundColor Green
        }
    }
}

$vitePortValue = Get-EnvValue $envPath 'VITE_PORT' "$VitePort"
$VitePort = [int] $vitePortValue

Write-Host "Stopping Belimbing processes for $ProjectRootPath..."

Stop-BelimbingPortListener -Name 'Vite' -Port $VitePort

Stop-BelimbingProcess -Name 'Queue worker' -Predicate {
    param($process)

    Test-CommandLineContains $process.CommandLine @(
        'artisan',
        'queue:work',
        'ai-agent-tasks,ai-background-commands,ai-schedules,default'
    )
}

Stop-BelimbingPortListener -Name 'FrankenPHP / Octane' -Port $CaddyAdminPort
