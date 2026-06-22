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

## Performance (Important)

Native Windows can feel several seconds slower per page than the same app on
Linux — even on faster hardware. The dominant cause is **Windows Defender
real-time scanning**.

Rendering a page compiles hundreds of PHP files, and Defender scans every file
as it is opened, turning a sub-second compile into multiple seconds. Defender and
the Windows search indexer also keep touching project files, which invalidates
OPcache and forces the same pages to recompile (and be re-scanned) again and
again. The result is page loads of several seconds where Linux — even on weaker
hardware — is well under one second.

The fix is to exclude the development paths from Defender. Keep real-time
protection enabled for the rest of the system; only the project and runtime are
excluded. Run in an **Administrator** PowerShell:

```powershell
# Use your actual project path and FrankenPHP install location
Add-MpPreference -ExclusionPath "D:\Repo\belimbing"
Add-MpPreference -ExclusionPath "$HOME\.frankenphp"
Add-MpPreference -ExclusionProcess "frankenphp.exe"
Add-MpPreference -ExclusionProcess "php.exe"
```

In testing this took a cold page load from ~3s down to ~150ms and made native
Windows competitive with a Linux/WSL2 setup — so WSL2 is not required just for
performance.

### OPcache tuning

The repository ships OPcache tuning at `.php.d/perf.ini`. It raises
`opcache.max_accelerated_files` above the ~11k-file codebase (the PHP default of
10000 is too low and causes compiled files to be evicted), enables tracing JIT,
and increases OPcache memory. `scripts/start-app.ps1` loads it automatically via
`PHP_INI_SCAN_DIR`, so no manual step is needed — just restart the launcher after
editing it. The Defender exclusions above are the larger win; this is
complementary.

## File Locks During Renames, Moves, and Branch Switches (Important)

On Windows a file or directory cannot be renamed, moved, or deleted while any
process holds an open handle to it — or to a file inside it — that was not opened
with the `FILE_SHARE_DELETE` sharing flag. An open handle to a single file
**pins the whole path**, so an ancestor directory cannot be renamed either. The
symptom is an operation failing with one of:

```text
Permission denied
Access to the path '...' is denied.
The process cannot access the file because it is being used by another process.
```

This bites `git mv`, `git checkout`/branch switches that add or remove
directories, `Rename-Item`, `Remove-Item`, and any refactor that moves folders.
Linux and WSL2 do not behave this way — they let you rename or unlink open
files (the inode lives until the last handle closes) — so it only appears on the
native Windows path.

The impact differs sharply between development and production — treat them
separately.

### Dev gotcha — watchers and editors block refactors

In a dev session the path is almost always pinned by a **file watcher or
editor**, and the durable fix is the share-delete principle:

> Every long-running process that reads or watches the source tree should open
> files with full sharing — `FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE`
> — so it never pins the path. Renames and deletes then succeed while it keeps
> running, with nothing to stop first.

The sharing mode belongs to each tool (Node/libuv, your editor, the C runtime),
not to PHP or Laravel, so "ensuring share-delete" means using compliant tools.
Mapped to the BLB Windows dev stack:

| Tool | Status / lever |
|------|----------------|
| Windows Defender, Search indexer | Already share-delete — scan without blocking renames (they cost compile *time*, not locks; see [Performance](#performance-important)). |
| Git for Windows, Composer | Already compliant. |
| VS Code / Cursor watcher | Modern versions are share-delete; keep the editor updated. A stale language server can still pin files — close the folder if it does. |
| **Vite / Node watcher** (`scripts/start-app.ps1`) | The usual blocker; its native watch handle is not reliably share-delete and the flag is not a Vite option. Lever: set `server.watch.usePolling` in `vite.config.js` so it stat-polls instead of holding a directory handle (costs CPU), or just don't run it during a structural change. |
| FrankenPHP / `php.exe` | Not a blocker — reads and closes source files; OPcache holds bytecode in shared memory. |

**Immediate unblock** when a non-compliant tool is pinning the path right now —
stop it, do the operation, restart:

```powershell
Stop-Process -Name frankenphp,php,bun -Force -ErrorAction SilentlyContinue
# the watcher may also be a node.exe — target it by command line, not your editor's helpers:
Get-CimInstance Win32_Process -Filter "Name='node.exe'" |
  Where-Object { $_.CommandLine -match 'vite|belimbing' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```

Then ensure no shell's current directory is inside the folder, run the
rename / move / `git mv` / branch switch, and restart with `.\scripts\start-app.ps1`.
The Defender exclusions reduce file churn but do not remove a lock.

### Production gotcha — the in-app updater replacing files in place

The dev lockers are absent on a server: there is no Vite watcher (assets are
pre-built), no editor, and FrankenPHP workers do not hold source-file handles
(PHP reads-and-closes; OPcache keeps bytecode in shared memory). So the in-app
updater (**Administration → System → Software → Updates**), which replaces files
with `git pull` + `composer install`, **usually succeeds in place on a clean
Windows Server**.

The residual risk is narrow and conditional: a **non-share-delete third-party
process touching the deploy path during the pull** — an AV, backup, or file-sync
agent (Defender is fine; some others are not), or a watcher/editor someone left
on the box. Maintenance mode does *not* help: it stops serving requests, not file
handles, so an agent grabbing a file mid-pull still fails the step.

#### Practical solution for BLB

In order of effort:

1. **Harden the current in-place updater (low effort, fits today's design).**
   Exclude the deploy path from AV/backup *real-time* scanning (or require
   share-delete agents) and keep watchers/editors off the production host. The
   updater already runs under maintenance mode and gracefully reloads FrankenPHP
   workers after replacing files, so on a clean host this is normally enough.

2. **Atomic release directories (robust; sidesteps the lock entirely).** Instead
   of pulling over the live tree, build each release in its own
   `releases\<timestamp>` directory and flip a `current` **directory junction**
   (`New-Item -ItemType Junction` / `mklink /J`) to point at it, then reload
   workers. You never overwrite an open file — you write a fresh directory and
   repoint a pointer — so Windows file locks cannot block the update. This is the
   standard zero-downtime pattern (Capistrano / Deployer / Envoyer), and
   FrankenPHP's graceful worker restart handles the cut-over. It is **not** how
   BLB's updater works today (it pulls in place); adopt it if in-place updates
   prove flaky on Windows.

3. **Run production on Linux (simplest robust answer).** On Linux, open files
   never block rename/replace, so this whole class of failure disappears and the
   in-place updater is safe with no extra machinery. Recommended when the in-app
   updater is a primary operational path.

## HTTPS On Windows

The native Windows launcher now prefers `mkcert` if it is available. When
`mkcert` is not installed, it falls back to FrankenPHP/Caddy `tls internal` and
imports Caddy's local root CA into the current user's Windows trust store before
the launcher finishes starting. In normal Edge/Chrome setups, that avoids the
"Not Secure" browser warning on `https://local.blb.lara`.

If a browser still warns, restart the launcher once so it can re-read the local
CA files, or install `mkcert` manually and let the launcher generate trusted
certificates. Do not use public Let's Encrypt certificates for
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

### Pages take several seconds to load

Windows Defender real-time scanning is scanning every PHP file as it compiles —
this is the single biggest native-Windows performance problem, and it makes the
app feel far slower than a Linux box even on better hardware. See
[Performance](#performance-important) and add the Defender exclusions. A symptom
that points here specifically: the *first* visit to each page is slow (seconds)
while a refresh is fast, and the slowness returns as you move between pages.

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

### `git mv`, rename, or branch switch fails with "Access is denied" / "being used by another process"

A watcher or editor holds an open handle to the file or directory; Windows pins
open files against rename and delete. Stop the launcher's Vite/Node watcher (and
close the folder in your editor if it still holds the path), then retry. This is
not a BLB setting — see
[File Locks During Renames, Moves, and Branch Switches](#file-locks-during-renames-moves-and-branch-switches-important).

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
