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

    $matchedProcesses = @(Get-CimInstance Win32_Process | Where-Object { & $Predicate $_ })
    if ($matchedProcesses.Count -eq 0) {
        Write-Output "$Name is not running."
        return
    }

    foreach ($matchedProcess in $matchedProcesses) {
        $target = "$Name (PID $($matchedProcess.ProcessId))"
        if ($PSCmdlet.ShouldProcess($target, 'Stop process')) {
            Stop-Process -Id $matchedProcess.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Output "Stopped $target."
        }
    }
}

function Stop-BelimbingPortListener {
    param(
        [string] $Name,
        [int] $Port,
        [string[]] $AllowedProcessNames = @()
    )

    $listeners = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    if ($listeners.Count -eq 0) {
        Write-Output "$Name is not listening on port $Port."
        return
    }

    foreach ($processId in $listeners) {
        $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
        $processName = if ($process) { $process.ProcessName } else { '' }
        if ($AllowedProcessNames.Count -gt 0 -and $processName -notin $AllowedProcessNames) {
            Write-Warning "$Name port $Port is owned by '$processName' (PID $processId), not by this Belimbing launcher. Leaving it running."
            continue
        }

        $target = "$Name (PID $processId, port $Port)"
        if ($PSCmdlet.ShouldProcess($target, 'Stop process')) {
            Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
            Write-Output "Stopped $target."
        }
    }
}

$appEnv = (Get-EnvValue $envPath 'APP_ENV' 'local').ToLowerInvariant()
if ($appEnv -in @('staging', 'production')) {
    Write-Output "Stopping supervised Belimbing runtime for $ProjectRootPath..."
    & (Join-Path $ScriptDir 'runtime\windows\stop-runtime.ps1')
    if (-not $?) {
        exit 1
    }
    return
}

$vitePortValue = Get-EnvValue $envPath 'VITE_PORT' "$VitePort"
$caddyAdminPortValue = Get-EnvValue $envPath 'CADDY_SERVER_ADMIN_PORT' "$CaddyAdminPort"
$VitePort = [int] $vitePortValue
$CaddyAdminPort = [int] $caddyAdminPortValue

Write-Output "Stopping Belimbing processes for $ProjectRootPath..."

Stop-BelimbingPortListener -Name 'Vite' -Port $VitePort -AllowedProcessNames @('bun', 'node', 'npm')

Stop-BelimbingProcess -Name 'Queue worker' -Predicate {
    param($process)

    Test-CommandLineContains $process.CommandLine @(
        $ProjectRootPath,
        'artisan',
        'queue:work',
        'ai-chat-turns,ai-agent-tasks,ai-background-commands,ai-schedules,default'
    )
}

Stop-BelimbingProcess -Name 'Scheduler' -Predicate {
    param($process)

    Test-CommandLineContains $process.CommandLine @(
        $ProjectRootPath,
        'artisan',
        'schedule:work'
    )
}

Stop-BelimbingPortListener -Name 'FrankenPHP / Octane' -Port $CaddyAdminPort -AllowedProcessNames @('frankenphp')
