#Requires -Version 5.1
<#
.SYNOPSIS
    Native Windows local setup for Belimbing.

.DESCRIPTION
    Prepares a Windows development environment using FrankenPHP's bundled PHP,
    SQLite, Composer, and Bun/npm frontend dependencies.

    Re-running this script is safe - it is idempotent. User-configurable values
    (.env domains, ports, company name) are only written when absent unless the
    corresponding parameter is explicitly provided. Infrastructure values
    (DB_CONNECTION, SESSION_DRIVER, etc.) are always applied.

    The admin user is created on first run only. On subsequent runs the
    provisioner re-asserts roles without touching the password. Pass
    -ResetAdmin to explicitly overwrite admin credentials.
#>

[CmdletBinding()]
param(
    [ValidateSet('local', 'staging', 'production', 'testing')]
    [string] $Environment = 'local',

    [string] $FrontendDomain = 'local.blb.lara',
    [string] $BackendDomain = 'local.api.blb.lara',
    [int] $AppPort = 8000,
    [int] $VitePort = 5173,
    [ValidateSet('direct', 'shared', 'tunnel', 'proxy', 'standalone', 'private')]
    [string] $IngressMode = 'shared',
    [int] $HttpsPort = 0,
    [int] $CaddyAdminPort = 0,
    [string] $InstanceName = '',

    [string] $LicenseeCompanyName = 'My Company',
    [string] $LicenseeCompanyCode = '',
    [string] $AdminName = 'Administrator',
    [string] $AdminEmail = 'admin@local.blb.lara',
    [string] $AdminPassword = 'password',

    [switch] $SkipHosts,
    [switch] $SkipComposerInstall,
    [switch] $SkipNodeInstall,
    [switch] $SkipMigrate,

    # Force admin credential reset even when an admin already exists.
    # Without this flag the provisioner re-asserts roles only - password is untouched.
    [switch] $ResetAdmin
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

function Write-Warn {
    param([string] $Message)
    Write-Warning $Message
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

function Set-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][string] $Key,
        [AllowEmptyString()][string] $Value,
        # When set, skip the write if the key already has a non-empty value.
        [switch] $OnlyIfAbsent
    )

    if ($OnlyIfAbsent -and (Test-Path $Path)) {
        $existing = Get-EnvValue -Path $Path -Key $Key
        if ($existing -ne '') {
            return
        }
    }

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

function Set-EnvValueBatch {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][object[]] $Entries
    )

    foreach ($entry in $Entries) {
        $onlyIfAbsent = $false
        if ($entry.PSObject.Properties.Name -contains 'OnlyIfAbsent') {
            $onlyIfAbsent = [bool] $entry.OnlyIfAbsent
        }

        Set-EnvValue -Path $Path -Key $entry.Key -Value ([string] $entry.Value) -OnlyIfAbsent:$onlyIfAbsent
    }
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

function Resolve-NativeCommandPath {
    param(
        [Parameter(Mandatory = $true)][string] $Command
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

# Returns $true when at least one user with company_id=1 exists in the SQLite
# database. Returns $false on any error (table absent, db missing, etc.).
function Test-AdminExists {
    param([string] $DatabasePath)
    if (-not (Test-Path $DatabasePath)) { return $false }
    try {
        $result = & $script:PhpExe -r 'try{$p=new PDO("sqlite:".$argv[1]);$n=$p->query("SELECT COUNT(*) FROM users WHERE company_id=1")->fetchColumn();echo $n>0?"yes":"no";}catch(Exception $e){echo "no";}' -- $DatabasePath 2>$null
        return ($result -eq 'yes')
    } catch {
        return $false
    }
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

function Invoke-Php {
    param([string[]] $Arguments)
    & $script:PhpExe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "PHP command failed: $($Arguments -join ' ')"
    }
}

function Invoke-PhpProbe {
    param([string[]] $Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        return (& $script:PhpExe @Arguments 2>$null | Out-String).Trim()
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

function Invoke-Composer {
    param([string[]] $Arguments)
    $composerArguments = @($ComposerPath) + $Arguments
    Invoke-Php -Arguments $composerArguments
}

function Get-PortOwnerSummary {
    param([int] $Port)

    $listeners = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    if ($listeners.Count -eq 0) {
        return ''
    }

    $owners = foreach ($processId in $listeners) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
        if ($process) {
            "$($process.Name) PID $processId"
        } else {
            "PID $processId"
        }
    }

    return ($owners -join ', ')
}

function Find-AvailableTcpPort {
    param(
        [int] $PreferredPort,
        [string] $Purpose
    )

    for ($port = $PreferredPort; $port -le 65535; $port++) {
        $owner = Get-PortOwnerSummary -Port $port
        if (-not $owner) {
            if ($port -ne $PreferredPort) {
                Write-Warn "$Purpose port $PreferredPort is busy; using $port instead."
            }
            return $port
        }

        if ($port -eq $PreferredPort) {
            Write-Warn "$Purpose port $PreferredPort is busy ($owner). Looking for an available port."
        }
    }

    throw "Could not find an available TCP port for $Purpose starting at $PreferredPort."
}

function Assert-TcpPortAvailable {
    param(
        [int] $Port,
        [string] $Purpose
    )

    $owner = Get-PortOwnerSummary -Port $Port
    if ($owner) {
        throw "$Purpose port $Port is busy ($owner). Stop that process or choose a different port explicitly."
    }

    return $Port
}

function Get-ExistingTcpPort {
    param(
        [string] $Path,
        [string] $Key
    )

    $value = Get-EnvValue -Path $Path -Key $Key
    if (-not $value) {
        return 0
    }

    if ($value -notmatch '^\d+$') {
        throw "$Key in .env must be a TCP port number, got '$value'."
    }

    $port = [int] $value
    if ($port -lt 1 -or $port -gt 65535) {
        throw "$Key in .env must be between 1 and 65535, got $port."
    }

    return $port
}

function Get-DefaultInstanceName {
    param([string] $Environment)

    switch ($Environment) {
        'production' { return 'Prod' }
        'staging' { return 'Stage' }
        'testing' { return 'Test' }
        default { return 'Local' }
    }
}

function ConvertTo-TaskToken {
    param([string] $Value)

    $token = ($Value -replace '[^A-Za-z0-9-]+', '-') -replace '^-+|-+$', ''
    if ($token) { return $token }
    return 'Instance'
}

# Capture which params were explicitly provided so we can distinguish
# "user changed this" from "default value that should not stomp .env".
$explicitEnvironment    = $PSBoundParameters.ContainsKey('Environment')
$explicitFrontendDomain = $PSBoundParameters.ContainsKey('FrontendDomain')
$explicitBackendDomain  = $PSBoundParameters.ContainsKey('BackendDomain')
$explicitAppPort        = $PSBoundParameters.ContainsKey('AppPort')
$explicitVitePort       = $PSBoundParameters.ContainsKey('VitePort')
$explicitIngressMode    = $PSBoundParameters.ContainsKey('IngressMode')
$explicitHttpsPort      = $PSBoundParameters.ContainsKey('HttpsPort')
$explicitCaddyAdminPort = $PSBoundParameters.ContainsKey('CaddyAdminPort')
$explicitInstanceName   = $PSBoundParameters.ContainsKey('InstanceName')
$explicitCompanyName    = $PSBoundParameters.ContainsKey('LicenseeCompanyName')
$explicitCompanyCode    = $PSBoundParameters.ContainsKey('LicenseeCompanyCode')

Push-Location $ProjectRootPath
try {
    Write-Step "Locating FrankenPHP"
    $frankenPhpHome = Resolve-FrankenPhpHome
    $script:PhpExe = Join-Path $frankenPhpHome 'php.exe'
    $frankenPhpExe = Join-Path $frankenPhpHome 'frankenphp.exe'
    $extensionDir = Join-Path $frankenPhpHome 'ext'

    if (-not (Test-Path $frankenPhpExe) -or -not (Test-Path $script:PhpExe)) {
        throw "FrankenPHP was not found in '$frankenPhpHome'. Set FRANKENPHP_INSTALL, add FrankenPHP to PATH, or install FrankenPHP first."
    }

    $env:Path = "$frankenPhpHome;$env:Path"
    Write-Ok "Using $frankenPhpHome"

    Write-Step "Writing project PHP configuration"
    New-Item -ItemType Directory -Force -Path $PhpConfigDir | Out-Null
    New-Item -ItemType Directory -Force -Path $DevopsDir | Out-Null
    if (-not (Test-Path $CaBundlePath)) {
        Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $CaBundlePath -TimeoutSec 30
    }

    $phpIniContent = @"
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
extension=sodium
memory_limit=512M
upload_max_filesize=64M
post_max_size=64M
variables_order=EGPCS
"@
    Set-Content -Path $PhpIniPath -Value $phpIniContent -Encoding ASCII
    $env:PHPRC = $PhpConfigDir
    Write-Ok "PHP ini: $PhpIniPath"

    $missingExtensions = @()
    foreach ($extension in @('ctype', 'dom', 'fileinfo', 'filter', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'session', 'sodium', 'tokenizer', 'xml', 'zip')) {
        $loaded = Invoke-PhpProbe -Arguments @('-r', "echo extension_loaded('$extension') ? 'yes' : 'no';")
        if ($loaded -ne 'yes') {
            $missingExtensions += $extension
        }
    }
    if ($missingExtensions.Count -gt 0) {
        throw "Missing PHP extensions: $($missingExtensions -join ', '). Ensure FrankenPHP is correctly installed in '$frankenPhpHome'."
    }
    Write-Ok "Required PHP extensions are enabled"

    Write-Step "Ensuring command-line PHP can encrypt backups (sodium)"
    # The verification above and the running app both load the project ini via
    # PHPRC, which enables sodium. But bare command-line invocations (php artisan
    # test, php artisan blb:db:backup, scheduled tasks) load FrankenPHP's own
    # php.ini, which does not enable sodium by default. Without it, app-key
    # encrypted backups fail with "Required tool not available: ext-sodium". The
    # php_sodium extension already ships in the extension dir the CLI uses, so a
    # single line in that ini is enough. We inspect the CLI ini with PHPRC
    # cleared so we see FrankenPHP's default, not the project ini.
    $savedPhprc = $env:PHPRC
    $env:PHPRC = $null
    $cliIni = Invoke-PhpProbe -Arguments @('-r', 'echo (string) php_ini_loaded_file();')
    $cliHasSodium = (Invoke-PhpProbe -Arguments @('-r', "echo extension_loaded('sodium') ? 'yes' : 'no';") -eq 'yes')
    $env:PHPRC = $savedPhprc

    if ($cliHasSodium) {
        Write-Ok "Command-line PHP already has sodium enabled"
    }
    elseif (-not $cliIni) {
        Write-Warn "Command-line PHP loads no php.ini, so sodium cannot be enabled automatically. Run CLI commands with `$env:PHPRC='$PhpConfigDir' (or set it as a user environment variable)."
    }
    else {
        $iniBody = Get-Content -Path $cliIni -Raw -ErrorAction SilentlyContinue
        if ($iniBody -match '(?m)^\s*extension\s*=\s*sodium\b') {
            Write-Warn "sodium is declared in $cliIni but not loading; check 'extension_dir' there."
        }
        else {
            try {
                Add-Content -Path $cliIni -Value "`nextension=sodium" -Encoding ASCII -ErrorAction Stop
                $env:PHPRC = $null
                $cliHasSodium = ((& $script:PhpExe -r "echo extension_loaded('sodium') ? 'yes' : 'no';" 2>$null | Out-String).Trim() -eq 'yes')
                $env:PHPRC = $savedPhprc
                if ($cliHasSodium) {
                    Write-Ok "Enabled sodium in command-line PHP ini: $cliIni"
                }
                else {
                    Write-Warn "Added 'extension=sodium' to $cliIni but it is still not loading; verify 'extension_dir' in that file."
                }
            }
            catch {
                Write-Warn "Could not edit $cliIni (it is likely under Program Files; re-run this script in an elevated PowerShell). To enable manually, run as Administrator: Add-Content '$cliIni' 'extension=sodium'"
            }
        }
    }

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
    $existingHttpsPort = Get-ExistingTcpPort -Path $envPath -Key 'HTTPS_PORT'
    $existingCaddyAdminPort = Get-ExistingTcpPort -Path $envPath -Key 'CADDY_SERVER_ADMIN_PORT'
    $instanceNameSeed = if ($InstanceName) {
        $InstanceName
    } else {
        Get-DefaultInstanceName $Environment
    }
    $resolvedInstanceName = ConvertTo-TaskToken $instanceNameSeed
    $resolvedHttpsPort = if ($HttpsPort -gt 0 -and $existingHttpsPort -eq $HttpsPort) {
        $HttpsPort
    } elseif ($HttpsPort -gt 0) {
        Assert-TcpPortAvailable -Port $HttpsPort -Purpose 'BLB HTTPS origin'
    } elseif ($Environment -in @('staging', 'production') -and $existingHttpsPort -gt 0) {
        $existingHttpsPort
    } elseif ($Environment -in @('staging', 'production')) {
        Find-AvailableTcpPort -PreferredPort 8643 -Purpose 'BLB HTTPS origin'
    } else {
        443
    }
    $resolvedCaddyAdminPort = if ($CaddyAdminPort -gt 0 -and $existingCaddyAdminPort -eq $CaddyAdminPort) {
        $CaddyAdminPort
    } elseif ($CaddyAdminPort -gt 0) {
        Assert-TcpPortAvailable -Port $CaddyAdminPort -Purpose 'BLB Caddy admin'
    } elseif ($Environment -in @('staging', 'production') -and $existingCaddyAdminPort -gt 0) {
        $existingCaddyAdminPort
    } elseif ($Environment -eq 'production') {
        Find-AvailableTcpPort -PreferredPort 2643 -Purpose 'BLB Caddy admin'
    } elseif ($Environment -eq 'staging') {
        Find-AvailableTcpPort -PreferredPort 2644 -Purpose 'BLB Caddy admin'
    } else {
        2020
    }
    $debugValue = if ($Environment -in @('staging', 'production')) { 'false' } else { 'true' }

    # Infrastructure values - BLB's architecture decisions, always applied.
    Set-EnvValueBatch -Path $envPath -Entries @(
        [pscustomobject]@{ Key = 'APP_DEBUG';        Value = $debugValue },
        [pscustomobject]@{ Key = 'APP_SCHEME';       Value = 'https' },
        [pscustomobject]@{ Key = 'BLB_INGRESS_MODE'; Value = $IngressMode; OnlyIfAbsent = (-not $explicitIngressMode) },
        [pscustomobject]@{ Key = 'DB_CONNECTION';    Value = 'sqlite' },
        [pscustomobject]@{ Key = 'DB_DATABASE';      Value = $databaseForEnv },
        [pscustomobject]@{ Key = 'SESSION_DRIVER';   Value = 'database' },
        [pscustomobject]@{ Key = 'QUEUE_CONNECTION'; Value = 'database' },
        [pscustomobject]@{ Key = 'CACHE_STORE';      Value = 'database' }
    )

    Write-Step "Verifying Git"
    $gitExe = Resolve-NativeCommandPath 'git'
    if (-not $gitExe) {
        throw "Git is required for BLB but was not found on PATH. Install Git from https://git-scm.com/download/win, restart PowerShell, then re-run scripts/setup.ps1."
    }
    Set-EnvValue $envPath 'BLB_FRANKENPHP_HOME' $frankenPhpHome
    Set-EnvValue $envPath 'BLB_GIT_EXECUTABLE' $gitExe
    Write-Ok "Git: $gitExe"

    $bunExe = Resolve-BunPath
    if ($bunExe) {
        Set-EnvValue $envPath 'BLB_BUN_EXECUTABLE' $bunExe
        Write-Ok "Bun: $bunExe"
    }

    # User-configurable values - written only when absent unless the param was
    # explicitly provided on this invocation.
    if (-not $LicenseeCompanyCode) {
        $LicenseeCompanyCode = Get-DefaultCompanyCode $LicenseeCompanyName
    }
    Set-EnvValueBatch -Path $envPath -Entries @(
        [pscustomobject]@{ Key = 'APP_ENV';               Value = $Environment;            OnlyIfAbsent = (-not $explicitEnvironment) },
        [pscustomobject]@{ Key = 'APP_URL';               Value = "https://$FrontendDomain"; OnlyIfAbsent = (-not $explicitFrontendDomain) },
        [pscustomobject]@{ Key = 'FRONTEND_DOMAIN';       Value = $FrontendDomain;         OnlyIfAbsent = (-not $explicitFrontendDomain) },
        [pscustomobject]@{ Key = 'BACKEND_DOMAIN';        Value = $BackendDomain;          OnlyIfAbsent = (-not $explicitBackendDomain) },
        [pscustomobject]@{ Key = 'APP_PORT';              Value = "$AppPort";              OnlyIfAbsent = (-not $explicitAppPort) },
        [pscustomobject]@{ Key = 'VITE_PORT';             Value = "$VitePort";             OnlyIfAbsent = (-not $explicitVitePort) },
        [pscustomobject]@{ Key = 'BLB_INSTANCE_NAME';     Value = $resolvedInstanceName;   OnlyIfAbsent = (-not $explicitInstanceName) },
        [pscustomobject]@{ Key = 'LICENSEE_COMPANY_NAME'; Value = $LicenseeCompanyName;    OnlyIfAbsent = (-not $explicitCompanyName) },
        [pscustomobject]@{ Key = 'LICENSEE_COMPANY_CODE'; Value = $LicenseeCompanyCode;    OnlyIfAbsent = (-not $explicitCompanyCode) }
    )
    if ($Environment -in @('staging', 'production') -or $explicitHttpsPort) {
        if ($explicitHttpsPort) {
            Set-EnvValue $envPath 'HTTPS_PORT' "$resolvedHttpsPort"
        } else {
            Set-EnvValue $envPath 'HTTPS_PORT' "$resolvedHttpsPort" -OnlyIfAbsent
        }
    }
    if ($Environment -in @('staging', 'production') -or $explicitCaddyAdminPort) {
        if ($explicitCaddyAdminPort) {
            Set-EnvValue $envPath 'CADDY_SERVER_ADMIN_PORT' "$resolvedCaddyAdminPort"
        } else {
            Set-EnvValue $envPath 'CADDY_SERVER_ADMIN_PORT' "$resolvedCaddyAdminPort" -OnlyIfAbsent
        }
    }

    $installState = [ordered]@{
        schema = 1
        generatedAt = (Get-Date).ToString('o')
        projectRoot = $ProjectRootPath
        environment = $Environment
        instanceName = $resolvedInstanceName
        ingressMode = $IngressMode
        frontendDomain = $FrontendDomain
        backendDomain = $BackendDomain
        appPort = $AppPort
        vitePort = $VitePort
        httpsPort = $resolvedHttpsPort
        caddyAdminPort = $resolvedCaddyAdminPort
        frankenphpHome = $frankenPhpHome
        phpIniPath = $PhpIniPath
        git = $gitExe
        bun = $bunExe
        composer = $ComposerPath
        skipped = [ordered]@{
            hosts = [bool] $SkipHosts
            composerInstall = [bool] $SkipComposerInstall
            nodeInstall = [bool] $SkipNodeInstall
            migrate = [bool] $SkipMigrate
        }
    }
    $installState | ConvertTo-Json -Depth 5 | Set-Content -Path (Join-Path $DevopsDir 'install-state.json') -Encoding UTF8

    Write-Ok "SQLite database: $DatabasePath"

    Write-Step "Configuring local domains"
    Add-HostsEntry -Domains @($FrontendDomain, $BackendDomain) -Skip:$SkipHosts

    if (-not $SkipComposerInstall) {
        Write-Step "Installing Composer locally if needed"
        if (Test-Path $ComposerPath) {
            Write-Ok "Composer already present - checking for updates"
            & $script:PhpExe $ComposerPath self-update --quiet
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Composer self-update failed - continuing with existing version"
            }
        } else {
            $installer = Join-Path $env:TEMP "composer-setup-$PID.php"
            Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $installer -TimeoutSec 30
            Invoke-Php @($installer, '--install-dir', $DevopsDir, '--filename', 'composer.phar')
            Remove-Item $installer -Force -ErrorAction SilentlyContinue
        }
        Write-Ok "Composer: $ComposerPath"

        Write-Step "Installing PHP dependencies"
        Invoke-Composer @('install')

        Write-Step "Publishing Livewire browser assets"
        Invoke-Php @('artisan', 'vendor:publish', '--tag=livewire:assets', '--force', '--no-interaction')
    }

    if (-not $SkipNodeInstall) {
        Write-Step "Installing frontend dependencies"
        $bun = Get-EnvValue -Path $envPath -Key 'BLB_BUN_EXECUTABLE'
        if (-not $bun) {
            $bun = Resolve-BunPath
        }
        if ($bun) {
            & $bun install --backend copyfile
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
                Write-Warning "$($tool.Name) installed but not yet on PATH. Restart your shell or add the winget install path to PATH."
            }
        } else {
            Write-Warning "$($tool.Name) not found and winget is unavailable - install manually: https://github.com/$($tool.WingetId.ToLower())"
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
            # Only bootstrap admin credentials on first run or when explicitly requested.
            # Skipping the bootstrap file lets FrameworkPrimitivesProvisioner use the
            # canonical anchor path - re-asserts roles without touching the password.
            $adminExists = Test-AdminExists -DatabasePath $databaseForEnv
            $needsBootstrap = (-not $adminExists) -or $ResetAdmin

            if ($adminExists -and $ResetAdmin) {
                Write-Output "  -ResetAdmin specified - admin credentials will be overwritten"
            } elseif ($adminExists) {
                Write-Ok "Admin user already exists - skipping credential bootstrap"
            }

            $adminBootstrapFile = $null
            if ($needsBootstrap) {
                $adminBootstrapFile = New-AdminBootstrapFile -Name $AdminName -Email $AdminEmail -Password $AdminPassword
            }

            $previousCompanyName = $env:LICENSEE_COMPANY_NAME
            $previousCompanyCode = $env:LICENSEE_COMPANY_CODE
            $previousBootstrapFile = $env:BLB_BOOTSTRAP_ADMIN_FILE

            $env:LICENSEE_COMPANY_NAME = $LicenseeCompanyName
            $env:LICENSEE_COMPANY_CODE = $LicenseeCompanyCode
            if ($adminBootstrapFile) {
                $env:BLB_BOOTSTRAP_ADMIN_FILE = $adminBootstrapFile
            }

            try {
                if ($Environment -eq 'local') {
                    Invoke-Php @('artisan', 'migrate', '--seed', '--dev')
                } else {
                    Invoke-Php @('artisan', 'migrate', '--seed', '--force')
                }
            } catch {
                throw
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

                if ($adminBootstrapFile) {
                    Remove-Item $adminBootstrapFile -Force -ErrorAction SilentlyContinue
                }
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
