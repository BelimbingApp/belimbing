#Requires -Version 5.1
<#
.SYNOPSIS
    Native Windows local setup for Belimbing.

.DESCRIPTION
    Prepares a Windows development environment using FrankenPHP's bundled PHP,
    SQLite, Composer, and Bun/npm frontend dependencies.
#>

[CmdletBinding()]
param(
    [ValidateSet('local', 'staging', 'production', 'testing')]
    [string] $Environment = 'local',

    [string] $FrontendDomain = 'local.blb.lara',
    [string] $BackendDomain = 'local.api.blb.lara',
    [int] $AppPort = 8000,
    [int] $VitePort = 5173,

    [string] $LicenseeCompanyName = 'My Company',
    [string] $LicenseeCompanyCode = '',
    [string] $AdminName = 'Administrator',
    [string] $AdminEmail = 'admin@local.blb.lara',
    [string] $AdminPassword = 'password',

    [switch] $SkipHosts,
    [switch] $SkipComposerInstall,
    [switch] $SkipNodeInstall,
    [switch] $SkipMigrate
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path (Join-Path $ScriptDir '..')
$ProjectRootPath = $ProjectRoot.Path
$DevopsDir = Join-Path $ProjectRootPath 'storage\app\.devops'
$PhpConfigDir = Join-Path $DevopsDir 'php'
$PhpIniPath = Join-Path $PhpConfigDir 'php.ini'
$ComposerPath = Join-Path $DevopsDir 'composer.phar'
$CaBundlePath = Join-Path $DevopsDir 'cacert.pem'
$DatabasePath = Join-Path $ProjectRootPath 'database\database.sqlite'

function Write-Step {
    param([string] $Message)
    Write-Output ""
    Write-Output "==> $Message"
}

function Write-Ok {
    param([string] $Message)
    Write-Output "OK  $Message"
}

function Resolve-FrankenPhpHome {
    if ($env:FRANKENPHP_INSTALL) {
        return $env:FRANKENPHP_INSTALL
    }

    return Join-Path $HOME '.frankenphp'
}

function Set-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Key,
        [AllowEmptyString()][string] $Value
    )

    if ($Value -match '[\s#"]') {
        $escapedValue = $Value.Replace('\', '\\').Replace('"', '\"')
        $line = "$Key=""$escapedValue"""
    } else {
        $line = "$Key=$Value"
    }

    function Write-TextFileSafely {
        param(
            [Parameter(Mandatory = $true)][string] $TargetPath,
            [Parameter(Mandatory = $true)][object] $Content
        )

        $targetDir = Split-Path -Parent $TargetPath
        $tempPath = Join-Path $targetDir ([System.IO.Path]::GetRandomFileName())

        try {
            Set-Content -Path $tempPath -Value $Content -Encoding UTF8

            $lastError = $null
            for ($attempt = 1; $attempt -le 5; $attempt++) {
                try {
                    Move-Item -Path $tempPath -Destination $TargetPath -Force
                    return
                } catch {
                    $lastError = $_
                    if ($attempt -eq 5) {
                        throw
                    }

                    Start-Sleep -Milliseconds (200 * $attempt)
                }
            }

            if ($lastError) {
                throw $lastError
            }
        } finally {
            Remove-Item -Path $tempPath -Force -ErrorAction SilentlyContinue
        }
    }

    if (-not (Test-Path $Path)) {
        Write-TextFileSafely -TargetPath $Path -Content $line
        return
    }

    $content = Get-Content -Path $Path
    $pattern = "^\s*#?\s*$([regex]::Escape($Key))="
    $updated = $false
    $next = foreach ($existingLine in $content) {
        if ($existingLine -match $pattern) {
            $updated = $true
            $line
        } else {
            $existingLine
        }
    }

    if (-not $updated) {
        $next += $line
    }

    Write-TextFileSafely -TargetPath $Path -Content $next
}

function Get-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Key,
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

function Add-HostsEntry {
    param(
        [string[]] $Domains,
        [switch] $Skip
    )

    if ($Skip) {
        Write-Output "Skipping hosts file update."
        return
    }

    $hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
    $hosts = Get-Content -Path $hostsPath -ErrorAction SilentlyContinue
    $missing = @($Domains | Where-Object {
        $domain = $_
        -not ($hosts | Where-Object { $_ -match "^\s*127\.0\.0\.1\s+.*\b$([regex]::Escape($domain))\b" })
    })

    if ($missing.Count -eq 0) {
        Write-Ok "Hosts entries already exist"
        return
    }

    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    $isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    $entry = "127.0.0.1 $($missing -join ' ')"

    if (-not $isAdmin) {
        Write-Warning "Hosts entry still needed. Re-run this script as Administrator or add this line:"
        Write-Output "  $entry"
        return
    }

    Add-Content -Path $hostsPath -Value $entry
    Write-Ok "Added hosts entry: $entry"
}

function Get-DefaultCompanyCode {
    param([string] $Name)

    $code = $Name.ToLowerInvariant() -replace '[^a-z0-9]+', '_'
    $code = $code.Trim('_')
    if ($code) {
        return $code
    }

    return 'my_company'
}

function New-AdminBootstrapFile {
    param(
        [string] $Name,
        [string] $Email,
        [string] $Password
    )

    $path = Join-Path $DevopsDir 'bootstrap-admin.txt'
    @($Name, $Email, $Password) | Set-Content -Path $path -Encoding UTF8
    return $path
}

function Resolve-BunPath {
    $bun = Get-Command bun -ErrorAction SilentlyContinue
    if ($bun) {
        return $bun.Source
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

function Invoke-Php {
    param([string[]] $Arguments)
    & $script:PhpExe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "PHP command failed: $($Arguments -join ' ')"
    }
}

function Invoke-Composer {
    param([string[]] $Arguments)
    $composerArguments = @($ComposerPath) + $Arguments
    Invoke-Php -Arguments $composerArguments
}

Push-Location $ProjectRootPath
try {
    Write-Step "Locating FrankenPHP"
    $frankenPhpHome = Resolve-FrankenPhpHome
    $script:PhpExe = Join-Path $frankenPhpHome 'php.exe'
    $frankenPhpExe = Join-Path $frankenPhpHome 'frankenphp.exe'
    $extensionDir = Join-Path $frankenPhpHome 'ext'

    if (-not (Test-Path $frankenPhpExe) -or -not (Test-Path $script:PhpExe)) {
        throw "FrankenPHP was not found in '$frankenPhpHome'. Set FRANKENPHP_INSTALL or install FrankenPHP first."
    }

    $env:Path = "$frankenPhpHome;$env:Path"
    Write-Ok "Using $frankenPhpHome"

    Write-Step "Writing project PHP configuration"
    New-Item -ItemType Directory -Force -Path $PhpConfigDir | Out-Null
    New-Item -ItemType Directory -Force -Path $DevopsDir | Out-Null
    if (-not (Test-Path $CaBundlePath)) {
        Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $CaBundlePath
    }

    @"
extension_dir="$extensionDir"
curl.cainfo="$CaBundlePath"
openssl.cafile="$CaBundlePath"
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
memory_limit=512M
upload_max_filesize=64M
post_max_size=64M
variables_order=EGPCS
"@ | Set-Content -Path $PhpIniPath -Encoding ASCII
    $env:PHPRC = $PhpConfigDir
    Write-Ok "PHP ini: $PhpIniPath"

    foreach ($extension in @('ctype', 'dom', 'fileinfo', 'filter', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'session', 'tokenizer', 'xml', 'zip')) {
        Invoke-Php @('-r', "exit(extension_loaded('$extension') ? 0 : 1);")
        if ($LASTEXITCODE -ne 0) {
            throw "Missing PHP extension: $extension"
        }
    }
    Write-Ok "Required PHP extensions are enabled"

    Write-Step "Preparing .env and SQLite"
    $envPath = Join-Path $ProjectRootPath '.env'
    if (-not (Test-Path $envPath)) {
        Copy-Item -Path (Join-Path $ProjectRootPath '.env.example') -Destination $envPath
        Write-Ok "Created .env from .env.example"
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $DatabasePath) | Out-Null
    if (-not (Test-Path $DatabasePath)) {
        New-Item -ItemType File -Path $DatabasePath | Out-Null
    }

    $databaseForEnv = $DatabasePath.Replace('\', '/')
    Set-EnvValue $envPath 'APP_ENV' $Environment
    Set-EnvValue $envPath 'APP_DEBUG' 'true'
    Set-EnvValue $envPath 'APP_SCHEME' 'https'
    Set-EnvValue $envPath 'APP_URL' "https://$FrontendDomain"
    Set-EnvValue $envPath 'FRONTEND_DOMAIN' $FrontendDomain
    Set-EnvValue $envPath 'BACKEND_DOMAIN' $BackendDomain
    Set-EnvValue $envPath 'BLB_INGRESS_MODE' 'direct'
    Set-EnvValue $envPath 'APP_PORT' "$AppPort"
    Set-EnvValue $envPath 'VITE_PORT' "$VitePort"
    Set-EnvValue $envPath 'DB_CONNECTION' 'sqlite'
    Set-EnvValue $envPath 'DB_DATABASE' $databaseForEnv
    Set-EnvValue $envPath 'SESSION_DRIVER' 'database'
    Set-EnvValue $envPath 'QUEUE_CONNECTION' 'database'
    Set-EnvValue $envPath 'CACHE_STORE' 'database'
    Set-EnvValue $envPath 'LICENSEE_COMPANY_NAME' $LicenseeCompanyName
    if (-not $LicenseeCompanyCode) {
        $LicenseeCompanyCode = Get-DefaultCompanyCode $LicenseeCompanyName
    }
    Set-EnvValue $envPath 'LICENSEE_COMPANY_CODE' $LicenseeCompanyCode
    Write-Ok "SQLite database: $DatabasePath"

    Write-Step "Configuring local domains"
    Add-HostsEntry -Domains @($FrontendDomain, $BackendDomain) -Skip:$SkipHosts

    if (-not $SkipComposerInstall) {
        Write-Step "Installing Composer locally if needed"
        if (-not (Test-Path $ComposerPath)) {
            $installer = Join-Path $env:TEMP "composer-setup-$PID.php"
            Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $installer
            try {
                Invoke-Php @($installer, '--install-dir', $DevopsDir, '--filename', 'composer.phar')
            } finally {
                Remove-Item $installer -Force -ErrorAction SilentlyContinue
            }
        }
        Write-Ok "Composer: $ComposerPath"

        Write-Step "Installing PHP dependencies"
        Invoke-Composer @('install')
    }

    if (-not $SkipNodeInstall) {
        Write-Step "Installing frontend dependencies"
        $bun = Resolve-BunPath
        if ($bun) {
            & $bun install
            if ($LASTEXITCODE -ne 0) {
                throw "Bun dependency install failed"
            }
        } elseif (Get-Command npm -ErrorAction SilentlyContinue) {
            & npm install
            if ($LASTEXITCODE -ne 0) {
                throw "npm dependency install failed"
            }
        } else {
            Write-Warning "Bun/npm is not available. Install Bun for the full dev workflow: https://bun.sh/docs/installation"
        }
    }

    Write-Step "Installing CLI tools (rg, jq)"
    $cliTools = @(
        @{ Name = 'ripgrep (rg)'; Binary = 'rg'; WingetId = 'BurntSushi.ripgrep.MSVC' },
        @{ Name = 'jq';           Binary = 'jq'; WingetId = 'jqlang.jq' }
    )
    foreach ($tool in $cliTools) {
        if (Get-Command $tool.Binary -ErrorAction SilentlyContinue) {
            Write-Ok "$($tool.Name) already installed"
        } elseif (Get-Command winget -ErrorAction SilentlyContinue) {
            Write-Output "  Installing $($tool.Name)..."
            winget install --id $tool.WingetId --accept-source-agreements --accept-package-agreements --silent
            if (Get-Command $tool.Binary -ErrorAction SilentlyContinue) {
                Write-Ok "$($tool.Name) installed"
            } else {
                Write-Warning "$($tool.Name) installed but not yet on PATH — restart your shell or add winget's install path to PATH"
            }
        } else {
            Write-Warning "$($tool.Name) not found and winget is unavailable — install manually: https://github.com/$($tool.WingetId.ToLower())"
        }
    }

    Write-Step "Finalizing Laravel"
    if (Test-Path (Join-Path $ProjectRootPath 'vendor\autoload.php')) {
        $appKey = Get-EnvValue -Path $envPath -Key 'APP_KEY'
        if (-not $appKey) {
            Invoke-Php @('artisan', 'key:generate', '--force')
        } else {
            Write-Ok "APP_KEY already exists"
        }

        if (-not $SkipMigrate) {
            $adminBootstrapFile = New-AdminBootstrapFile -Name $AdminName -Email $AdminEmail -Password $AdminPassword
            $previousCompanyName = $env:LICENSEE_COMPANY_NAME
            $previousCompanyCode = $env:LICENSEE_COMPANY_CODE
            $previousBootstrapFile = $env:BLB_BOOTSTRAP_ADMIN_FILE

            $env:LICENSEE_COMPANY_NAME = $LicenseeCompanyName
            $env:LICENSEE_COMPANY_CODE = $LicenseeCompanyCode
            $env:BLB_BOOTSTRAP_ADMIN_FILE = $adminBootstrapFile

            try {
                if ($Environment -eq 'local') {
                    Invoke-Php @('artisan', 'migrate', '--seed', '--dev')
                } else {
                    Invoke-Php @('artisan', 'migrate', '--seed', '--force')
                }
            } finally {
                if ($null -eq $previousCompanyName) {
                    Remove-Item Env:\LICENSEE_COMPANY_NAME -ErrorAction SilentlyContinue
                } else {
                    $env:LICENSEE_COMPANY_NAME = $previousCompanyName
                }

                if ($null -eq $previousCompanyCode) {
                    Remove-Item Env:\LICENSEE_COMPANY_CODE -ErrorAction SilentlyContinue
                } else {
                    $env:LICENSEE_COMPANY_CODE = $previousCompanyCode
                }

                if ($null -eq $previousBootstrapFile) {
                    Remove-Item Env:\BLB_BOOTSTRAP_ADMIN_FILE -ErrorAction SilentlyContinue
                } else {
                    $env:BLB_BOOTSTRAP_ADMIN_FILE = $previousBootstrapFile
                }

                Remove-Item $adminBootstrapFile -Force -ErrorAction SilentlyContinue
            }
        }
        Invoke-Php @('artisan', 'config:clear')
    } else {
        Write-Warning "Skipping Laravel finalization because vendor/autoload.php is not present."
    }

    Write-Output ""
    Write-Output "Windows setup complete. Start the app with:"
    Write-Output "  .\scripts\start-app.ps1"
} finally {
    Pop-Location
}
