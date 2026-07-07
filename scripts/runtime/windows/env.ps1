#Requires -Version 5.1
<#
.SYNOPSIS
    Shared Windows runtime environment for supervised BLB instances.
#>

Set-StrictMode -Version Latest

$script:RuntimeDir = $PSScriptRoot
$script:ProjectRoot = (Resolve-Path (Join-Path $script:RuntimeDir '..\..\..')).Path
$script:ScriptsDir = Join-Path $script:ProjectRoot 'scripts'
$script:EnvPath = Join-Path $script:ProjectRoot '.env'
$script:DevopsDir = Join-Path $script:ProjectRoot 'storage\app\.devops'
$script:RuntimeStateDir = Join-Path $script:DevopsDir 'runtime'
$script:PhpConfigDir = Join-Path $script:DevopsDir 'php'
$script:PhpIniPath = Join-Path $script:PhpConfigDir 'php.ini'
$script:LogsDir = Join-Path $script:ProjectRoot 'storage\logs\runtime'
$script:OctaneStatePath = Join-Path $script:ProjectRoot 'storage\logs\octane-server-state.json'

function Get-BLBEnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Key,

        [string] $Default = '',

        [string] $Path = $script:EnvPath
    )

    if (-not (Test-Path $Path)) {
        return $Default
    }

    $match = Get-Content -Path $Path | Where-Object { $_ -match "^\s*$([regex]::Escape($Key))=(.*)$" } | Select-Object -First 1
    if (-not $match) {
        return $Default
    }

    $value = ($match -replace "^\s*$([regex]::Escape($Key))=", '').Trim()
    if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    return $value
}

function Get-BLBPortValue {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Key,

        [Parameter(Mandatory = $true)]
        [int] $Default
    )

    $value = Get-BLBEnvValue -Key $Key -Default "$Default"
    if ($value -notmatch '^\d+$') {
        throw "$Key must be a TCP port number, got '$value'."
    }

    $port = [int] $value
    if ($port -lt 1 -or $port -gt 65535) {
        throw "$Key must be between 1 and 65535, got $port."
    }

    return $port
}

function ConvertTo-BLBTaskToken {
    param([string] $Value)

    $token = ($Value -replace '[^A-Za-z0-9-]+', '-') -replace '^-+|-+$', ''
    if (-not $token) {
        return 'Instance'
    }

    return $token
}

function Get-BLBDefaultInstanceName {
    param([string] $Environment)

    switch ($Environment) {
        'production' { return 'Prod' }
        'staging' { return 'Stage' }
        'testing' { return 'Test' }
        default { return 'Local' }
    }
}

function Get-BLBInstallStateValue {
    param([Parameter(Mandatory = $true)][string] $Key)

    $statePath = Join-Path $script:DevopsDir 'install-state.json'
    if (-not (Test-Path $statePath)) {
        return ''
    }

    try {
        $state = Get-Content -Path $statePath -Raw | ConvertFrom-Json
        $value = $state.$Key
        if ($value) {
            return [string] $value
        }
    } catch {
        return ''
    }

    return ''
}

function Resolve-BLBFrankenPhpHome {
    $configured = Get-BLBEnvValue -Key 'BLB_FRANKENPHP_HOME' -Default ''
    if ($configured) {
        return $configured
    }

    if ($env:FRANKENPHP_INSTALL) {
        return $env:FRANKENPHP_INSTALL
    }

    $installStateHome = Get-BLBInstallStateValue -Key 'frankenphpHome'
    if ($installStateHome) {
        return $installStateHome
    }

    $frankenPhpCommand = Get-Command frankenphp -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($frankenPhpCommand) {
        return Split-Path -Parent $frankenPhpCommand.Source
    }

    return Join-Path $HOME '.frankenphp'
}

function Resolve-BLBNativeCommandPath {
    param([Parameter(Mandatory = $true)][string] $Command)

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

function Resolve-BLBGitPath {
    $configured = Get-BLBEnvValue -Key 'BLB_GIT_EXECUTABLE' -Default ''
    if ($configured) {
        return $configured
    }

    $installStateGit = Get-BLBInstallStateValue -Key 'git'
    if ($installStateGit) {
        return $installStateGit
    }

    $git = Resolve-BLBNativeCommandPath 'git'
    if ($git) {
        return $git
    }

    throw 'Git executable not found. Run scripts/setup.ps1 so BLB_GIT_EXECUTABLE is pinned in .env.'
}

function Resolve-BLBBunPath {
    $configured = Get-BLBEnvValue -Key 'BLB_BUN_EXECUTABLE' -Default ''
    if ($configured) {
        return $configured
    }

    $installStateBun = Get-BLBInstallStateValue -Key 'bun'
    if ($installStateBun) {
        return $installStateBun
    }

    $bun = Resolve-BLBNativeCommandPath 'bun'
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

function Initialize-BLBPhpRuntime {
    if (-not (Test-Path $script:PhpExe)) {
        throw "php.exe not found in '$script:FrankenPhpHome'. Set FRANKENPHP_INSTALL or run scripts/setup.ps1."
    }

    if (-not (Test-Path $script:FrankenPhpExe)) {
        throw "frankenphp.exe not found in '$script:FrankenPhpHome'. Set FRANKENPHP_INSTALL or run scripts/setup.ps1."
    }

    if (-not (Test-Path $script:PhpIniPath)) {
        throw "Project PHP ini not found at '$script:PhpIniPath'. Run scripts/setup.ps1."
    }

    if (-not (Test-Path (Join-Path $script:ProjectRoot 'vendor\autoload.php'))) {
        throw "PHP dependencies missing under '$script:ProjectRoot'. Run scripts/setup.ps1."
    }

    $env:Path = "$script:FrankenPhpHome;$env:Path"
    $env:PHPRC = $script:PhpConfigDir
    $env:PHP_BINARY = $script:PhpExe

    $perfIniDir = Join-Path $script:ProjectRoot '.php.d'
    if (Test-Path (Join-Path $perfIniDir 'perf.ini')) {
        $env:PHP_INI_SCAN_DIR = $perfIniDir
    }
}

function Get-BLBTlsDirective {
    if ($script:CaddyScheme -eq 'http') {
        return ''
    }

    $tlsMode = Get-BLBEnvValue -Key 'TLS_MODE' -Default 'internal'
    if ($tlsMode -eq 'internal' -or -not $tlsMode) {
        return 'tls internal'
    }

    if ($tlsMode -match '@') {
        return "tls $tlsMode"
    }

    if ($tlsMode -eq 'off') {
        return ''
    }

    return $tlsMode
}

function Get-BLBBindAddress {
    $configured = Get-BLBEnvValue -Key 'CADDY_BIND_ADDRESS' -Default ''
    if ($configured) {
        return $configured
    }

    $appBindHost = Get-BLBEnvValue -Key 'APP_BIND_HOST' -Default ''
    if ($appBindHost) {
        return $appBindHost
    }

    switch ($script:IngressMode) {
        'standalone' { return '0.0.0.0' }
        default { return '127.0.0.1' }
    }
}

function Set-BLBCaddyEnvironment {
    $env:APP_DOMAIN = $script:FrontendDomain
    $env:BACKEND_DOMAIN = $script:BackendDomain
    $env:APP_PORT = "$script:AppPort"
    $env:HTTPS_PORT = "$script:HttpsPort"
    $env:CADDY_SCHEME = $script:CaddyScheme
    $env:TLS_DIRECTIVE = Get-BLBTlsDirective
    $env:CADDY_SERVER_ADMIN_HOST = $script:CaddyAdminHost
    $env:CADDY_SERVER_ADMIN_PORT = "$script:CaddyAdminPort"
    $env:CADDY_LOG_DIR = Join-Path $script:ProjectRoot '.caddy\logs'
    $env:CADDY_BIND_ADDRESS = Get-BLBBindAddress
    $env:CADDY_VITE_SNIPPET = 'scripts/caddy-snippets/vite-disabled.caddy'
    $env:APP_PUBLIC_PATH = Join-Path $script:ProjectRoot 'public'
    $env:APP_BASE_PATH = $script:ProjectRoot
    $env:LARAVEL_OCTANE = '1'
    $env:MAX_REQUESTS = Get-BLBEnvValue -Key 'MAX_REQUESTS' -Default '500'
    $env:REQUEST_MAX_EXECUTION_TIME = Get-BLBEnvValue -Key 'REQUEST_MAX_EXECUTION_TIME' -Default '0'
    $env:CADDY_SERVER_WORKER_DIRECTIVE = ''
    $env:CADDY_SERVER_WATCH_DIRECTIVES = ''

    New-Item -ItemType Directory -Force -Path $env:CADDY_LOG_DIR | Out-Null
}

function Write-BLBOctaneServerState {
    $state = [ordered]@{
        state = [ordered]@{
            appName = 'Belimbing'
            host = Get-BLBBindAddress
            port = $script:HttpsPort
            adminHost = $script:CaddyAdminHost
            adminPort = $script:CaddyAdminPort
            workers = $null
            maxRequests = $env:MAX_REQUESTS
        }
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $script:OctaneStatePath) | Out-Null
    $state | ConvertTo-Json -Depth 4 | Set-Content -Path $script:OctaneStatePath -Encoding UTF8
}

function Write-BLBRuntimeLog {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Role,

        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    New-Item -ItemType Directory -Force -Path $script:LogsDir | Out-Null
    $line = '[{0:yyyy-MM-dd HH:mm:ss}] {1}' -f (Get-Date), $Message
    Add-Content -Path (Join-Path $script:LogsDir "$Role.log") -Value $line -Encoding UTF8
}

function Get-BLBPortListeners {
    param([Parameter(Mandatory = $true)][int] $Port)

    @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object LocalAddress, LocalPort, OwningProcess -Unique)
}

function Get-BLBProcessSummary {
    param([Parameter(Mandatory = $true)][int] $ProcessId)

    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $ProcessId" -ErrorAction SilentlyContinue
    if (-not $process) {
        return "PID $ProcessId"
    }

    return "$($process.Name) PID $ProcessId $($process.CommandLine)".Trim()
}

function Assert-BLBPortAvailable {
    param(
        [Parameter(Mandatory = $true)]
        [int] $Port,

        [Parameter(Mandatory = $true)]
        [string] $Purpose
    )

    $listeners = @(Get-BLBPortListeners -Port $Port)
    if ($listeners.Count -eq 0) {
        return
    }

    $owners = $listeners | ForEach-Object { Get-BLBProcessSummary -ProcessId $_.OwningProcess }
    throw "$Purpose port $Port is already in use by: $($owners -join '; ')"
}

function Test-BLBCurrentProcessAdmin {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-BLBTaskNames {
    [pscustomobject]@{
        Server = "$script:TaskPrefix-Server"
        Queue = "$script:TaskPrefix-Queue"
        Scheduler = "$script:TaskPrefix-Scheduler"
        Health = "$script:TaskPrefix-Health"
        Backup = "$script:TaskPrefix-Backup"
    }
}

function Get-BLBOriginUrl {
    $scheme = $script:CaddyScheme
    $originHost = $script:FrontendDomain
    $port = $script:HttpsPort

    return "${scheme}://${originHost}:${port}/"
}

function Get-BLBOriginCheckAddress {
    $bindAddress = Get-BLBBindAddress
    if (-not $bindAddress -or $bindAddress -in @('0.0.0.0', '::')) {
        return '127.0.0.1'
    }

    return $bindAddress
}

function Invoke-BLBOriginCheck {
    param([int] $TimeoutSeconds = 10)

    $url = Get-BLBOriginUrl
    $resolve = "$script:FrontendDomain`:$script:HttpsPort`:$(Get-BLBOriginCheckAddress)"
    $arguments = @('-sS', '-k', '--max-time', "$TimeoutSeconds", '--resolve', $resolve, '-o', 'NUL', '-w', '%{http_code}', $url)
    $status = (& curl.exe @arguments 2>$null | Out-String).Trim()

    return ($status -match '^[123][0-9][0-9]$')
}

function Get-BLBPublicHealthUrl {
    Get-BLBEnvValue -Key 'BLB_PUBLIC_HEALTH_URL' -Default ''
}

function Invoke-BLBPublicHealthCheck {
    param([int] $TimeoutSeconds = 15)

    $url = Get-BLBPublicHealthUrl
    if (-not $url) {
        return $true
    }

    $statusPattern = Get-BLBEnvValue -Key 'BLB_PUBLIC_HEALTH_STATUS_PATTERN' -Default '^[123][0-9][0-9]$'
    $bodyPattern = Get-BLBEnvValue -Key 'BLB_PUBLIC_HEALTH_BODY_PATTERN' -Default ''
    $bodyPath = Join-Path ([IO.Path]::GetTempPath()) "blb-public-health-$PID.txt"

    try {
        $status = (& curl.exe -sS -k --max-time "$TimeoutSeconds" -o $bodyPath -w '%{http_code}' $url 2>$null | Out-String).Trim()
        if ($status -notmatch $statusPattern) {
            Write-BLBRuntimeLog -Role 'health' -Message "Public health check failed for ${url}: HTTP $status did not match $statusPattern."

            return $false
        }

        if ($bodyPattern) {
            $body = if (Test-Path $bodyPath) { Get-Content -Path $bodyPath -Raw } else { '' }
            if ($body -notmatch $bodyPattern) {
                Write-BLBRuntimeLog -Role 'health' -Message "Public health check failed for ${url}: body did not match configured pattern."

                return $false
            }
        }

        Write-BLBRuntimeLog -Role 'health' -Message "Public health check passed for $url (HTTP $status)."

        return $true
    } finally {
        Remove-Item -Path $bodyPath -Force -ErrorAction SilentlyContinue
    }
}

function Write-BLBInstallState {
    param([hashtable] $Additional = @{})

    New-Item -ItemType Directory -Force -Path $script:DevopsDir | Out-Null
    $state = [ordered]@{
        schema = 1
        generatedAt = (Get-Date).ToString('o')
        projectRoot = $script:ProjectRoot
        appEnv = $script:AppEnv
        instanceName = $script:InstanceName
        ingressMode = $script:IngressMode
        frontendDomain = $script:FrontendDomain
        backendDomain = $script:BackendDomain
        appPort = $script:AppPort
        httpsPort = $script:HttpsPort
        caddyAdminPort = $script:CaddyAdminPort
        caddyBindAddress = Get-BLBBindAddress
        php = $script:PhpExe
        frankenphp = $script:FrankenPhpExe
    }

    foreach ($key in $Additional.Keys) {
        $state[$key] = $Additional[$key]
    }

    $state | ConvertTo-Json -Depth 4 | Set-Content -Path (Join-Path $script:DevopsDir 'install-state.json') -Encoding UTF8
}

$script:AppEnv = (Get-BLBEnvValue -Key 'APP_ENV' -Default 'local').ToLowerInvariant()
$script:IngressMode = (Get-BLBEnvValue -Key 'BLB_INGRESS_MODE' -Default 'direct').ToLowerInvariant()
$script:FrontendDomain = Get-BLBEnvValue -Key 'FRONTEND_DOMAIN' -Default 'local.blb.lara'
$script:BackendDomain = Get-BLBEnvValue -Key 'BACKEND_DOMAIN' -Default 'local.api.blb.lara'
$script:AppPort = Get-BLBPortValue -Key 'APP_PORT' -Default 8000
$script:VitePort = Get-BLBPortValue -Key 'VITE_PORT' -Default 5173
$defaultHttpsPort = if ($script:AppEnv -in @('staging', 'production')) { $script:AppPort } else { 443 }
$script:HttpsPort = Get-BLBPortValue -Key 'HTTPS_PORT' -Default $defaultHttpsPort
$defaultAdminPort = switch ($script:AppEnv) {
    'production' { 2643 }
    'staging' { 2644 }
    default { 2020 }
}
$script:CaddyAdminHost = Get-BLBEnvValue -Key 'CADDY_SERVER_ADMIN_HOST' -Default '127.0.0.1'
$script:CaddyAdminPort = Get-BLBPortValue -Key 'CADDY_SERVER_ADMIN_PORT' -Default $defaultAdminPort
$script:CaddyScheme = (Get-BLBEnvValue -Key 'CADDY_SCHEME' -Default (Get-BLBEnvValue -Key 'BLB_ORIGIN_SCHEME' -Default 'https')).ToLowerInvariant()
$script:InstanceName = ConvertTo-BLBTaskToken (Get-BLBEnvValue -Key 'BLB_INSTANCE_NAME' -Default (Get-BLBDefaultInstanceName -Environment $script:AppEnv))
$script:TaskPrefix = "BLB-$script:InstanceName"
$script:FrankenPhpHome = Resolve-BLBFrankenPhpHome
$script:PhpExe = Join-Path $script:FrankenPhpHome 'php.exe'
$script:FrankenPhpExe = Join-Path $script:FrankenPhpHome 'frankenphp.exe'
