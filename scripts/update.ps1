#Requires -Version 5.1
<#!
.SYNOPSIS
    Refresh BLB after dependency manifest changes with one command.

.DESCRIPTION
    Edit composer.json and/or package.json, then run this script. It decides
    when Composer should update the lockfile versus just install from it,
    refreshes Bun dependencies, republishes PHP-managed assets, and rebuilds
    frontend assets.

.EXAMPLE
    ./scripts/update.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = (Resolve-Path (Join-Path $ScriptDir '..')).Path

function Write-Step {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Output $Message
}

function Assert-CommandAvailable {
    param([Parameter(Mandatory = $true)][string] $Name)

    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "$Name is not available"
    }
}

function Invoke-ProjectCommand {
    param(
        [Parameter(Mandatory = $true)][string] $Command,
        [Parameter(Mandatory = $true)][string[]] $Arguments
    )

    Push-Location $ProjectRoot
    try {
        & $Command @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "$Command exited with code $LASTEXITCODE"
        }
    } finally {
        Pop-Location
    }
}

function Test-GitPathModified {
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        return $false
    }

    Push-Location $ProjectRoot
    try {
        & git rev-parse --is-inside-work-tree *> $null
        if ($LASTEXITCODE -ne 0) {
            return $false
        }

        & git diff --quiet -- $Path
        if ($LASTEXITCODE -ne 0) {
            return $true
        }

        & git diff --cached --quiet -- $Path
        if ($LASTEXITCODE -ne 0) {
            return $true
        }

        return $false
    } finally {
        Pop-Location
    }
}

function Test-ShouldRunComposerUpdate {
    $composerJson = Join-Path $ProjectRoot 'composer.json'
    $composerLock = Join-Path $ProjectRoot 'composer.lock'

    if (-not (Test-Path $composerLock)) {
        return $true
    }

    if ((Get-Item $composerJson).LastWriteTimeUtc -gt (Get-Item $composerLock).LastWriteTimeUtc) {
        return $true
    }

    if ((Test-GitPathModified 'composer.json') -and -not (Test-GitPathModified 'composer.lock')) {
        return $true
    }

    return $false
}

function Invoke-ComposerRefresh {
    Assert-CommandAvailable 'composer'
    Assert-CommandAvailable 'php'

    if (Test-ShouldRunComposerUpdate) {
        Write-Step 'Composer manifest changed; updating PHP dependencies...'
        Invoke-ProjectCommand -Command 'composer' -Arguments @('update', '--no-interaction', '--prefer-dist', '--optimize-autoloader')
    } else {
        Write-Step 'Composer lockfile is current; installing PHP dependencies...'
        Invoke-ProjectCommand -Command 'composer' -Arguments @('install', '--no-interaction', '--prefer-dist', '--optimize-autoloader')
    }

    Write-Step 'Publishing Composer-managed assets...'
    Invoke-ProjectCommand -Command 'php' -Arguments @('artisan', 'vendor:publish', '--tag=laravel-assets', '--ansi', '--force')
    Invoke-ProjectCommand -Command 'php' -Arguments @('artisan', 'vendor:publish', '--tag=livewire:assets', '--ansi', '--force')
}

function Invoke-PackageRefresh {
    Assert-CommandAvailable 'bun'

    Write-Step 'Refreshing Bun dependencies...'
    Invoke-ProjectCommand -Command 'bun' -Arguments @('install')

    Write-Step 'Building frontend assets...'
    Invoke-ProjectCommand -Command 'bun' -Arguments @('run', 'build')
}

if ($args.Count -ne 0) {
    throw 'Usage: ./scripts/update.ps1'
}

Invoke-ComposerRefresh
Invoke-PackageRefresh
