# Native Windows Installation

This guide installs Belimbing directly on Windows using FrankenPHP's bundled PHP,
SQLite, and PowerShell scripts.

For WSL2, use the Linux quickstart instead. The native Windows path is different:
it uses `scripts/setup.ps1` and `scripts/start-app.ps1`, writes Windows hosts
entries, and works around PHP/Octane differences that only exist on Windows.

## Supported Shape

Native Windows local development uses:

- FrankenPHP installed under `%USERPROFILE%\.frankenphp`
- FrankenPHP's bundled `php.exe`
- a project-local PHP ini at `storage/app/.devops/php/php.ini`
- SQLite at `database/database.sqlite`
- Composer stored as `storage/app/.devops/composer.phar`
- Bun for Vite and frontend dependencies
- direct HTTPS through FrankenPHP/Caddy at `https://local.blb.lara`
- `frankenphp.exe run --config Caddyfile` for the web server

The Bash scripts remain the Linux/WSL path. Do not run `scripts/setup.sh` from
native PowerShell unless you intentionally want a Git Bash/MSYS-style setup.

## Prerequisites

Install these before setup:

- Windows 10 or Windows 11
- PowerShell 7.6.1 from Microsoft Store
- Git for Windows
- internet access
- permission to edit the Windows hosts file

FrankenPHP can be installed with:

```powershell
irm https://frankenphp.dev/install.ps1 | iex
```

The installer normally places `frankenphp.exe` and `php.exe` in:

```text
%USERPROFILE%\.frankenphp
```

If you installed FrankenPHP somewhere else, set `FRANKENPHP_INSTALL` before
running setup:

```powershell
$env:FRANKENPHP_INSTALL = 'C:\frankenphp'
```

New terminals may be required after installing tools because Windows updates the
user `PATH` for future processes, not already-open shells.

## Clone

```powershell
New-Item -ItemType Directory -Force D:\Repo
Set-Location D:\Repo
git clone https://github.com/BelimbingApp/belimbing.git
Set-Location D:\Repo\belimbing
```

If your repository URL is different, use that URL instead.

## Allow Local Scripts

If PowerShell blocks local scripts, run this once for the current shell:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

This changes only the current PowerShell process.

## Hosts File

Belimbing uses friendly local domains. Native Windows must resolve these names
to Windows localhost:

```text
127.0.0.1 local.blb.lara local.api.blb.lara
```

Add the line to:

```text
C:\Windows\System32\drivers\etc\hosts
```

You need an Administrator editor or Administrator PowerShell:

```powershell
Add-Content -Path "$env:SystemRoot\System32\drivers\etc\hosts" -Value "127.0.0.1 local.blb.lara local.api.blb.lara"
```

This is different from WSL2. WSL2 browser access often needs the WSL2 IP address
in the Windows hosts file. Native Windows uses `127.0.0.1`.

## Run Setup

From the project root:

```powershell
.\scripts\setup.ps1
```

The setup script will:

- locate FrankenPHP's bundled `php.exe`
- create `storage/app/.devops/php/php.ini`
- enable required PHP extensions, including `pdo_sqlite`, `sqlite3`, `openssl`,
  `curl`, `mbstring`, `fileinfo`, `intl`, and `zip`
- download a CA bundle to `storage/app/.devops/cacert.pem`
- configure `curl.cainfo` and `openssl.cafile` for HTTPS downloads
- copy `.env.example` to `.env` when missing
- set `APP_URL=https://local.blb.lara`
- set `DB_CONNECTION=sqlite`
- create `database/database.sqlite`
- install local Composer when missing
- install PHP dependencies
- install frontend dependencies with Bun or npm
- generate `APP_KEY` only when empty
- run migrations and local dev seeders
- bootstrap the licensee company and first admin user

The default local admin account is:

```text
admin@local.blb.lara
password
```

Use setup parameters to override bootstrap values:

```powershell
.\scripts\setup.ps1 `
  -LicenseeCompanyName "Acme Sdn Bhd" `
  -LicenseeCompanyCode "acme" `
  -AdminName "Admin" `
  -AdminEmail "admin@example.test" `
  -AdminPassword "change-me-now"
```

## Bun and Frontend Dependencies

The setup script prefers Bun. If Bun is missing but `winget` is available, install
Bun with:

```powershell
winget install -e --id Oven-sh.Bun --accept-package-agreements --accept-source-agreements
```

After installing Bun, open a new terminal or let `scripts/setup.ps1` find the
Winget install location directly.

## Start The App

From the project root:

```powershell
.\scripts\start-app.ps1
```

The launcher starts three processes:

- FrankenPHP running the project `Caddyfile`
- Laravel queue worker
- Vite

Open:

```text
https://local.blb.lara
```

FrankenPHP may take several seconds to finish loading the Caddyfile, preparing
TLS, and booting PHP workers. If the first browser attempt says
`ERR_CONNECTION_REFUSED`, wait a few seconds and refresh. If it persists, check
whether `frankenphp.exe` is listening on `443`:

```powershell
netstat -ano | Select-String ':443'
```

Stop the launcher with `Ctrl+C`.

If PowerShell is closed abruptly, child processes may remain. Check and stop them
with:

```powershell
Get-Process frankenphp,php,bun -ErrorAction SilentlyContinue
Stop-Process -Name frankenphp,php,bun -Force -ErrorAction SilentlyContinue
```

## HTTPS On Windows

The native Windows launcher uses FrankenPHP/Caddy `tls internal`. This gives
local HTTPS, but browsers may warn until the generated local CA is trusted.

For local development, it is acceptable to proceed through the browser warning.
For a trusted-browser setup, install and trust a local development certificate
authority such as `mkcert`, then configure BLB's local cert files in the project
Caddyfile path. Do not use public Let's Encrypt certificates for
`local.blb.lara`; it is a local-only development name.

PHP HTTPS downloads are separate from browser trust. `scripts/setup.ps1` writes
`curl.cainfo` and `openssl.cafile` to a project-local `cacert.pem` so seeders and
Composer can download over HTTPS without Windows OpenSSL CA errors.

## Windows-Specific Octane Detail

Native Windows PHP does not provide the `pcntl` extension or POSIX signal
handling. Laravel's `php artisan octane:start` path subscribes to console
signals and can fail on native Windows before the server binds to `443`.

For that reason, `scripts/start-app.ps1` starts FrankenPHP directly:

```powershell
frankenphp.exe run --config Caddyfile --adapter caddyfile
```

The project Caddyfile still uses Laravel Octane's FrankenPHP worker script, so
requests run through the Octane worker model without relying on Symfony console
signal registration.

## Common Problems

### `frankenphp` or `php` is not recognized

The current terminal probably does not have the updated user `PATH`.

Use a new PowerShell window, or set:

```powershell
$env:Path = "$HOME\.frankenphp;$env:Path"
```

If FrankenPHP was installed elsewhere:

```powershell
$env:FRANKENPHP_INSTALL = 'C:\frankenphp'
```

### `Failed to parse dotenv file`

Values containing spaces must be quoted in `.env`. The setup script handles this
for values it writes, including `LICENSEE_COMPANY_NAME`.

### `cURL error 60`

Windows PHP often has no default OpenSSL CA file. Re-run:

```powershell
.\scripts\setup.ps1 -SkipComposerInstall -SkipNodeInstall -SkipMigrate
```

This refreshes `storage/app/.devops/cacert.pem` and the project PHP ini.

### `Undefined constant Laravel\Octane\Commands\Concerns\SIGINT`

This happens when starting the app with Laravel's console command on native
Windows:

```powershell
php artisan octane:start --server=frankenphp
```

Use the Windows launcher instead:

```powershell
.\scripts\start-app.ps1
```

### Browser cannot resolve `local.blb.lara`

The hosts file is missing the native Windows entry:

```text
127.0.0.1 local.blb.lara local.api.blb.lara
```

Add it to `C:\Windows\System32\drivers\etc\hosts` as Administrator.

### Port 443 is already in use

Another local web server may already be using HTTPS. Stop the other service, or
change the launcher's `HTTPS_PORT` environment before starting and browse to the
matching URL.

`-AppPort` changes the internal Octane listener. It does not move the public
`https://local.blb.lara` listener away from port `443`.

## Reset Local Data

To reset the native Windows SQLite database:

```powershell
Stop-Process -Name frankenphp,php,bun -Force -ErrorAction SilentlyContinue
Remove-Item .\database\database.sqlite -Force
.\scripts\setup.ps1
```

This destroys local data.

## Verification

Check Laravel's view of the environment:

```powershell
$env:PHPRC = 'D:\Repo\belimbing\storage\app\.devops\php'
$env:Path = "$HOME\.frankenphp;$env:Path"
& "$HOME\.frankenphp\php.exe" artisan about --only=environment,cache,drivers
```

Expected local indicators:

- `Environment`: `local`
- `URL`: `local.blb.lara`
- `Database`: `sqlite`
- `Cache`: `database`
- `Queue`: `database`
- `Session`: `database`
- `Octane`: `frankenphp`
