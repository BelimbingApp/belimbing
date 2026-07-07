#Requires -Version 5.1
<#
.SYNOPSIS
    Register a staging/production BLB checkout as supervised Windows Scheduled Tasks.
#>

[CmdletBinding()]
param(
    [switch] $SkipDefender,
    [switch] $StartNow,
    [string] $BackupOffboxTarget = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. "$PSScriptRoot\env.ps1"

if ($script:AppEnv -notin @('staging', 'production')) {
    throw "Supervised Windows services are only installed for APP_ENV=staging or production. Current APP_ENV=$script:AppEnv."
}

if (-not (Test-BLBCurrentProcessAdmin)) {
    throw 'This installer must run in an elevated PowerShell (Run as Administrator).'
}

Initialize-BLBPhpRuntime

$tasks = Get-BLBTaskNames
$psExe = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"

if ($BackupOffboxTarget) {
    [Environment]::SetEnvironmentVariable('BLB_BACKUP_OFFBOX', $BackupOffboxTarget, 'Machine')
    Write-Output "Set machine env BLB_BACKUP_OFFBOX=$BackupOffboxTarget"
}

if (-not $SkipDefender -and (Get-Command Add-MpPreference -ErrorAction SilentlyContinue)) {
    foreach ($path in @($script:ProjectRoot, $script:FrankenPhpHome)) {
        Add-MpPreference -ExclusionPath $path -ErrorAction SilentlyContinue
    }
    foreach ($processName in @('frankenphp.exe', 'php.exe')) {
        Add-MpPreference -ExclusionProcess $processName -ErrorAction SilentlyContinue
    }
    Write-Output 'Applied Defender exclusions for project, FrankenPHP, and php/frankenphp processes.'
}

$systemPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

function Register-BLBLongRunningTask {
    param(
        [Parameter(Mandatory = $true)][string] $Name,
        [Parameter(Mandatory = $true)][string] $Script
    )

    Unregister-ScheduledTask -TaskName $Name -Confirm:$false -ErrorAction SilentlyContinue
    $action = New-ScheduledTaskAction -Execute $psExe `
        -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$Script`"" `
        -WorkingDirectory $script:RuntimeDir
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) `
        -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $systemPrincipal -Settings $settings | Out-Null
    Write-Output "Registered $Name"
}

Register-BLBLongRunningTask -Name $tasks.Server -Script (Join-Path $script:RuntimeDir 'start-server.ps1')
Register-BLBLongRunningTask -Name $tasks.Queue -Script (Join-Path $script:RuntimeDir 'run-queue.ps1')
Register-BLBLongRunningTask -Name $tasks.Scheduler -Script (Join-Path $script:RuntimeDir 'run-scheduler.ps1')

Unregister-ScheduledTask -TaskName $tasks.Health -Confirm:$false -ErrorAction SilentlyContinue
$healthAction = New-ScheduledTaskAction -Execute $psExe `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$(Join-Path $script:RuntimeDir 'health-check.ps1')`"" `
    -WorkingDirectory $script:RuntimeDir
$healthTrigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).Date.AddMinutes(1))
$healthTrigger.Repetition.Interval = 'PT5M'
$healthTrigger.Repetition.Duration = 'P3650D'
$healthSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName $tasks.Health -Action $healthAction -Trigger $healthTrigger -Principal $systemPrincipal -Settings $healthSettings | Out-Null
Write-Output "Registered $($tasks.Health) (every 5 minutes)"

Unregister-ScheduledTask -TaskName $tasks.Backup -Confirm:$false -ErrorAction SilentlyContinue
$backupAction = New-ScheduledTaskAction -Execute $psExe `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$(Join-Path $script:RuntimeDir 'backup-nightly.ps1')`"" `
    -WorkingDirectory $script:RuntimeDir
$backupTrigger = New-ScheduledTaskTrigger -Daily -At 3am
$backupSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName $tasks.Backup -Action $backupAction -Trigger $backupTrigger -Principal $systemPrincipal -Settings $backupSettings | Out-Null
Write-Output "Registered $($tasks.Backup) (daily 03:00)"

Write-BLBInstallState -Additional @{ tasks = $tasks }

if ($StartNow) {
    Start-ScheduledTask -TaskName $tasks.Server
    Start-ScheduledTask -TaskName $tasks.Queue
    Start-ScheduledTask -TaskName $tasks.Scheduler
    Start-ScheduledTask -TaskName $tasks.Health
    Write-Output 'Started server, queue, scheduler, and health tasks.'
}

Write-Output ''
Write-Output 'Done. Manage with:'
Write-Output "  Get-ScheduledTask $script:TaskPrefix-*"
Write-Output "  Start-ScheduledTask -TaskName $($tasks.Server)"
