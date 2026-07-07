# Windows supervised runtime

**Scope:** Native Windows staging and production BLB instances started through
`scripts\start-app.ps1` with `APP_ENV=staging` or `APP_ENV=production`.

Use the Windows installation guide for first-time setup. Use this runbook when an
instance is already configured and you need to start it, verify ingress, deploy,
or triage an outage.

## Runtime contract

Root `.env` is the runtime contract. Operators should not create a second
runtime env file. The supervised Windows scripts read these keys directly:

| Key | Purpose |
| --- | --- |
| `APP_ENV` | `staging` or `production` selects supervised Scheduled Tasks. |
| `BLB_INSTANCE_NAME` | Stable task prefix: `BLB-{Instance}-Server`, `Queue`, `Scheduler`, `Health`, `Backup`. |
| `BLB_INGRESS_MODE` | `tunnel`, `proxy`, `standalone`, `private`, `shared`, or legacy `direct`. |
| `HTTPS_PORT` | Pinned local origin port. External ingress must dial this exact port. |
| `CADDY_SERVER_ADMIN_PORT` | Pinned Caddy admin API port used by reload/deploy. |
| `CADDY_BIND_ADDRESS` | Usually `127.0.0.1` behind tunnels/proxies; explicit LAN/public address for standalone mode. |
| `BLB_FRANKENPHP_HOME`, `BLB_GIT_EXECUTABLE`, `BLB_BUN_EXECUTABLE` | Tool paths pinned by setup so SYSTEM tasks do not depend on an interactive user's profile. |
| `BLB_PUBLIC_HEALTH_URL` | Optional public URL checked by health/deploy when the instance has public ingress. |

Setup-only provenance lives in `storage\app\.devops\install-state.json`. It is
for status/debugging, not a higher-priority config source. If `.env` and the
manifest disagree, fix `.env` and rerun setup. Transient setup state such as
`storage\app\.devops\setup.env` may be deleted after a successful setup;
`install-state.json` may remain for status reporting.

## Start, stop, deploy

Start or report status:

```powershell
.\scripts\start-app.ps1
```

Install missing tasks once from an elevated PowerShell when `start-app.ps1` asks
for it:

```powershell
.\scripts\runtime\windows\install-services.ps1 -StartNow
```

Stop the instance deliberately:

```powershell
.\scripts\stop-app.ps1
```

Deploy code updates:

```powershell
.\scripts\runtime\windows\deploy.ps1
```

Deploy pulls, installs PHP dependencies when Composer is present, builds assets
unless `-SkipBuild` is explicit, runs migrations, reloads FrankenPHP workers,
signals `queue:restart`, checks the local origin, and checks the configured
public health URL when one is set.

## Origin verification

Always verify the local origin before blaming Cloudflare, IIS, nginx, Caddy, or a
provider proxy. Replace names and ports with the values in `.env`.

```powershell
curl.exe -k --resolve blb.example.com:8643:127.0.0.1 https://blb.example.com:8643/
```

Expected result: a BLB response boundary such as `200`, `302`, `401`, or `403`.
Connection refused means FrankenPHP is not listening. TLS or Host errors usually
mean the ingress hostname, Caddy site hostname, and `.env` domains drifted apart.

### Cloudflare Tunnel

For Cloudflare Tunnel, BLB should normally bind a loopback high-port origin:

```dotenv
BLB_INGRESS_MODE=tunnel
CADDY_BIND_ADDRESS=127.0.0.1
HTTPS_PORT=8643
BLB_PUBLIC_HEALTH_URL=https://blb.example.com
BLB_PUBLIC_HEALTH_STATUS_PATTERN=^(200|302|401|403)$
```

The tunnel ingress should dial the same origin:

```text
https://127.0.0.1:8643
```

When Cloudflare Access protects the app, the public health check should assert
the Access/login edge boundary rather than requiring a raw application `200`.
Use `BLB_PUBLIC_HEALTH_BODY_PATTERN` only when the body text is stable enough to
be a useful signal.

### Existing IIS/nginx/Caddy/Apache reverse proxy

Use the same local-origin model as a tunnel:

```dotenv
BLB_INGRESS_MODE=proxy
CADDY_BIND_ADDRESS=127.0.0.1
HTTPS_PORT=8643
```

Configure the external web server to proxy to `https://127.0.0.1:8643` and pass
the intended Host header. If the external proxy terminates public TLS, BLB may
use `TLS_MODE=internal` on the local origin.

### Standalone direct HTTPS

Use standalone only when BLB intentionally owns the LAN/public listener:

```dotenv
BLB_INGRESS_MODE=standalone
CADDY_BIND_ADDRESS=0.0.0.0
HTTPS_PORT=443
TLS_MODE=admin@example.com
```

Before choosing standalone, confirm no other web server owns `80`/`443`, the
Windows firewall/NAT exposes the listener intentionally, and DNS points at this
machine.

### Private/local-only

Use private mode for current-machine or LAN-only installs with no public health
check:

```dotenv
BLB_INGRESS_MODE=private
CADDY_BIND_ADDRESS=127.0.0.1
# BLB_PUBLIC_HEALTH_URL is intentionally unset
```

## First five triage commands

Run from an elevated PowerShell when possible so SYSTEM-owned task/process
details are visible.

```powershell
# 1. Scheduled task state
Get-ScheduledTask BLB-Prod-* | Format-Table TaskName,State

# 2. Recent task outcomes
Get-ScheduledTaskInfo BLB-Prod-Server,BLB-Prod-Queue,BLB-Prod-Scheduler,BLB-Prod-Health |
  Format-Table TaskName,LastRunTime,LastTaskResult,NextRunTime

# 3. Port owners for origin, admin, WSL-dev defaults, and Vite
Get-NetTCPConnection -State Listen -LocalPort 8643,2643,2020,5173 -ErrorAction SilentlyContinue |
  Select-Object LocalAddress,LocalPort,OwningProcess

# 4. Process parent/command line
Get-CimInstance Win32_Process -Filter "name = 'frankenphp.exe' or name = 'php.exe'" |
  Select-Object ProcessId,ParentProcessId,Name,CommandLine

# 5. Runtime logs
Get-Content .\storage\logs\runtime\server.log -Tail 80
Get-Content .\storage\logs\runtime\health.log -Tail 80
```

Then check Caddy access logs and Laravel logs:

```powershell
Get-Content .\.caddy\logs\access.log -Tail 80
Get-Content .\storage\logs\laravel.log -Tail 120
```

## Health and alerting

The `BLB-{Instance}-Health` task runs every five minutes. It starts missing
queue/scheduler tasks, restarts the server task when the local origin is down,
and exits non-zero when the local origin or configured public health check still
fails.

For public instances, also configure an external uptime monitor outside the
Windows host. The monitor should hit the public URL and assert the expected edge
boundary (`200`, `302`, `401`, or `403`, depending on Access/proxy behavior), not
only that the local origin works.

## Backups and restore drills

The installer registers a daily backup task. Set an off-box mirror target during
installation or later at machine scope:

```powershell
.\scripts\runtime\windows\install-services.ps1 -BackupOffboxTarget "\\backup-host\blb-prod" -StartNow
```

On-box backups alone are not disaster recovery. Choose object storage, a NAS, or
another machine as the off-box target, and run a restore drill before relying on
the deployment. Follow `docs/runbooks/database-backup.md` for the restore
procedure and APP_KEY requirements.
