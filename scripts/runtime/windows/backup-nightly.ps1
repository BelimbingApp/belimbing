#Requires -Version 5.1
<#
.SYNOPSIS
    Run the encrypted BLB database backup and optional off-box mirror.
#>

[CmdletBinding()]
param(
    [string] $OffboxTarget = $env:BLB_BACKUP_OFFBOX
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

Initialize-BLBPhpRuntime
New-Item -ItemType Directory -Force -Path $script:LogsDir | Out-Null
$backupLog = Join-Path $script:LogsDir 'backup.log'
$backupDir = Join-Path $script:ProjectRoot "storage\app\private\backups\$script:AppEnv"

Push-Location $script:ProjectRoot
try {
    Write-BLBRuntimeLog -Role 'backup' -Message 'Starting encrypted database backup.'
    & $script:PhpExe (Join-Path $script:ProjectRoot 'artisan') blb:db:backup --prune --no-interaction >> $backupLog 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "blb:db:backup failed with exit code $LASTEXITCODE"
    }
    Write-BLBRuntimeLog -Role 'backup' -Message 'Database backup completed.'
} finally {
    Pop-Location
}

if ($OffboxTarget) {
    if (-not (Test-Path $backupDir)) {
        throw "Backup dir not found: $backupDir"
    }

    New-Item -ItemType Directory -Force -Path $OffboxTarget | Out-Null
    & robocopy.exe $backupDir $OffboxTarget /MIR /R:2 /W:5 /NFL /NDL /NP | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy to off-box target failed (code $LASTEXITCODE)"
    }
    Write-BLBRuntimeLog -Role 'backup' -Message "Off-box mirror complete: $OffboxTarget"
} else {
    Write-BLBRuntimeLog -Role 'backup' -Message 'BLB_BACKUP_OFFBOX not set; backup stayed on-box only.'
}
