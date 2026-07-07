# windows-prod-runtime-hardening

**Status:** In progress; repo-owned Windows runtime scripts, the one-command front door, pinned port contract, and local health recovery are implemented. Host-specific elevated task installation, external uptime checks, off-box backup selection, and restore drills remain.
**Last Updated:** 2026-07-06
**Sources:** 2026-07-06 502 incident notes in Amp thread `T-019f352b-1978-7561-95d4-3c0cbf21f0b6`; follow-up runtime intent discussion covering Windows deployments with Cloudflare, other ingress providers, or no provider; `scripts/start-app.ps1`; `scripts/stop-app.ps1`; `Caddyfile`; `docs/plans/blb-hosted-instances.md`; adjacent host ops scripts under `D:\Repo\BelimbingApp\ops\` (`README.md`, `install-services.ps1`, `start-production.ps1`, `run-queue.ps1`, `run-scheduler.ps1`, `reload.ps1`, `deploy.ps1`).
**Agents:** amp/gpt-5.5, amp/fable-5

## Problem Essence

Production can return edge 502s when the local origin is down, launched with development defaults, or drifted away from the ingress provider's configured origin port. The Jul 2026 Cloudflare incident exposed a broader operational weakness: BLB's Windows runtime does not yet give non-DevOps users one obvious command that still chooses the correct development, staging, production, and ingress process model.

## Desired Outcome

`https://blb.belimbing.app` should survive unattended reboot, process crashes, routine deploys, and concurrent WSL development without relying on a human to remember special flags. A user should be able to run the obvious Windows command for the current checkout; BLB should infer from `.env` whether that means a foreground local development stack or an idempotent supervised staging/production runtime. The same conventions should work on Windows Server or a standalone Windows 10/11 box whether the site is reached through Cloudflare Tunnel, another tunnel, an existing reverse proxy such as IIS/nginx/Caddy, direct public HTTPS, or local/LAN-only access. A future origin outage should either self-heal within minutes or alert clearly with enough local evidence to fix it without guessing.

## Top-Level Components

- **Runtime front door** — `scripts\start-app.ps1` remains the human-facing start command, but dispatches by environment instead of exposing production-only flags to users.
- **Production runtime runners** — the Windows service runners that start FrankenPHP, the queue worker, the scheduler, backup, deploy, and reload for a staging or production checkout.
- **Windows supervision** — boot-time Scheduled Tasks or an equivalent Windows service wrapper that owns long-running production processes outside any interactive shell.
- **Port and ingress contract** — `.env`, the local FrankenPHP listener, the Caddy admin API, and any external ingress provider must agree on the same values.
- **Ingress mode adapter** — Cloudflare Tunnel is the current hosted-instance adapter, but the runtime should also support bring-your-own reverse proxy, standalone direct HTTPS, and local/LAN-only Windows installs.
- **Origin surface** — Caddy/FrankenPHP should expose only the app surface needed by the selected ingress mode, not development-only Vite routes or accidental LAN/public origins.
- **Deploy and reload path** — pull/build/migrate/reload must update assets, web workers, and queue workers together.
- **Health and recovery** — local checks restart dead services; external checks alert when the configured public ingress path fails.

## Design Decisions

### Runtime front door

- **Option A — keep using `scripts/start-app.ps1` with special production flags.** Lowest immediate friction, but keeps production tied to a development launcher that starts Vite by default, defaults Caddy admin to 2020, and was the path that made WSL port collisions relevant.
- **Option B — make `start-app.ps1` refuse production and require operators to know separate `ops\` commands.** Safer than Option A, but too much cognitive load for BLB's Windows target users and too easy to forget during an incident.
- **Option C — one front door, environment-dispatched.** `start-app.ps1` reads `.env`: `APP_ENV=local` runs the foreground development stack; `APP_ENV=staging` or `production` ensures the supervised runtime is installed/running, verifies health, and prints status. Internally, staging/production still use separate service runners with no Vite and no foreground FrankenPHP.
- **Option D — replace the scripts now with NSSM/WinSW or another service manager.** Durable long term, but introduces another dependency before the current contract is even enforced.

**Recommendation:** choose Option C. This is the DHH-style shape: one obvious command by convention, not one configurable tangle of flags. The command is a front door; the environment selects the right process model. Keep the `HTTPS_PORT` fix in `start-app.ps1`, but make the production branch delegate to hardened service runners instead of starting the development stack with production-flavored flags. Revisit NSSM/WinSW only if Task Scheduler plus supervisor loops proves insufficient.

### Supervision and recovery

- **Option A — detached interactive PowerShell.** Fast during an incident, but dies on logoff/reboot and provides no restart semantics.
- **Option B — boot-time SYSTEM tasks plus runner-level supervisor loops.** Matches the existing ops scripts, avoids stored passwords, and can restart individual roles independently.
- **Option C — full observability stack first.** Useful later, but too much surface before the basic watchdog exists.

**Recommendation:** use Option B with a small external uptime monitor when the instance has a public URL. Local self-healing handles dead origins; the external check catches failures in the selected ingress path visible to users.

### Port and config source of truth

- **Option A — hardcode ports in each script and remember matching ingress values.** This is what drifted in the Cloudflare incident: `start-app.ps1`, `reload.ps1`, Caddy admin, and the tunnel can all disagree.
- **Option B — `.env` owns staging/production ports, with script parameters only as explicit overrides.** The launcher, reload script, and drift checks read the same values. Ports are selected at setup time and pinned; starts do not auto-hop because external ingress dials a specific port.
- **Option C — the external provider config owns the truth.** Attractive, but provider config may be remote, manual, or not readable by BLB; it should be checked against local truth when possible, not become the only source.

**Recommendation:** make `.env` the local truth for `HTTPS_PORT` and the Caddy admin port, then add a verification step that compares it to the live ingress origin when provider credentials or local proxy config are available. Robust port assignment means setup-time selection plus start-time verification, not production auto-selection. If a pinned staging/production port is busy, BLB should name the owner and stop loudly rather than silently changing the contract.

### Setup state versus runtime environment

- **Option A — put every setup answer in root `.env`.** Simple to implement, but it pollutes Laravel's runtime configuration with one-time installer details, creates stale values after setup, and tempts scripts to treat setup history as live truth.
- **Option B — create a second root-style `.env.setup` for users to edit.** Separates concerns, but increases cognitive load: users now have two env files, unclear precedence, and a new place for drift.
- **Option C — keep root `.env` as the only runtime instance contract, and store setup-only state under `storage/app/.devops/`.** Runtime values that `start`, `deploy`, `reload`, Laravel, or supervised tasks need stay in `.env`; one-time setup answers, generated artifact paths, detected tool paths, and install provenance go to a setup state/manifest file.

**Recommendation:** choose Option C. Do not create a second user-facing runtime env file. Use root `.env` for durable instance facts: `APP_ENV`, domains/URLs, DB settings, queue/cache/mail settings, ingress mode, pinned ports, bind policy, and Caddy admin port. Use `storage/app/.devops/setup.env` only for transient cross-step setup state, and introduce a clearer durable manifest such as `storage/app/.devops/install-state.json` if `start-app.ps1` needs to report what setup installed. Never persist bootstrap-only secrets such as the first admin password in setup state.

### Ingress provider boundary

- **Option A — make Cloudflare Tunnel the production architecture.** This matches the current hosted instance and is a good low-ops default, but it would make BLB feel brittle or over-prescribed for Windows users who already have nginx/IIS/Caddy, another tunnel, a VPS provider proxy, or no public site at all.
- **Option B — make BLB always own public `80`/`443`.** Simple for a single standalone server, but hostile to machines that already run other web stacks and risky on consumer Windows where firewall/NAT/certificate assumptions differ.
- **Option C — keep BLB's runtime ingress-neutral.** BLB owns a supervised app origin and a small set of conventions; setup chooses an ingress mode: tunnel, existing reverse proxy, standalone direct, or private local/LAN. Provider-specific automation hangs off that boundary.

**Recommendation:** choose Option C. Cloudflare Tunnel remains the recommended public, low-ops adapter for the hosted BLB instance, not a platform requirement. When behind any tunnel or reverse proxy, BLB should bind a loopback high-port origin. When explicitly configured as standalone direct, BLB may bind a chosen LAN/public address and own TLS/firewall guidance. When private/local-only, BLB should optimize for safe local access and not pretend the site is publicly reachable.

### Daemon model by environment

- **Option A — daemonize everything, including development.** Consistent on paper, but worse for local work: developers want foreground logs, Ctrl+C cleanup, hot reload, and easy process visibility.
- **Option B — foreground everything for command consistency.** This caused the incident class: production dies with the interactive shell and does not survive reboot/logoff.
- **Option C — same command, environment-appropriate lifecycle.** Local development runs foreground; staging and production run supervised daemons; the command hides the lifecycle choice unless the operator asks for status or logs.

**Recommendation:** choose Option C. Development should not require a daemon by default. Staging and production should require one. A deliberate attached production debug mode can exist later, but it must be explicit, warn loudly, and refuse to run while the daemon tasks are active.

## Public Contract

- `scripts\start-app.ps1` is the start front door for Windows checkouts. It dispatches by `APP_ENV` instead of asking users to remember production flags.
- Local development starts in the foreground with Vite/HMR and terminal logs.
- Staging and production ensure supervised server, queue, scheduler, and health tasks are installed/running, then print status and log locations.
- Staging/production web origins are loopback-only when behind a tunnel or reverse proxy, and reachable by that ingress at the configured `HTTPS_PORT`.
- Standalone direct mode is explicit: BLB may bind a configured LAN/public address and serve HTTPS itself only when the instance is intended to own that network surface.
- Private/local-only mode is explicit: BLB serves the current machine or LAN without public-ingress assumptions.
- Staging/production never start Vite and never proxy Vite development routes.
- The Caddy admin port used for deploy reloads is configured per instance, not `2020` by convention.
- Ports are pinned per staging/production instance at setup time; start-time port hopping is forbidden.
- Root `.env` is the only runtime env file users need to understand; setup-only state lives under `storage/app/.devops/` and is not a second source of runtime truth.
- A deploy builds assets, runs migrations, gracefully reloads FrankenPHP workers, and signals `queue:restart` before being considered complete.
- A dead local origin is restarted automatically; a public ingress failure alerts an operator when a public ingress exists.

## Phases

### Phase 0 — Stabilize the current host

Goal: move production out of ad-hoc process state and back under supervised production ownership before the next reboot or deploy.

- [ ] From an elevated Windows PowerShell, verify the actual state of `BLB-Prod-*` Scheduled Tasks, current `frankenphp.exe` / `php.exe` owners, and listeners on 8643, the configured Caddy admin port, 2020, and 5173.
- [ ] Re-run the production service installer with `-StartNow` during a quiet window, or explicitly start the already-registered tasks if they exist and are correct.
- [ ] Confirm only one production FrankenPHP server, one production queue supervisor, and one scheduler are active after the task start.
- [ ] Verify the local origin with `curl --resolve blb.belimbing.app:HTTPS_PORT:127.0.0.1` and the public URL through Cloudflare Access.
- [ ] Reconcile `docs/plans/blb-hosted-instances.md` with the verified service state so the hosted-instance plan no longer contradicts the live host.

### Phase 1 — Make the production runners crash-proof and drift-resistant

Goal: a process crash, queue restart, or wrong port owner produces a restart or a clear log, not a silent 502.

- [x] Move the reusable production runner contract into this repository, or put the adjacent `ops\` scripts under durable version control as an interim step; the final product cannot depend on an unversioned hand-built ops folder. `{amp/gpt-5.5}`
- [x] Make the service runners derive project root and instance identity from the checkout/config instead of hardcoded `D:\Repo\BelimbingApp\production` paths. `{amp/gpt-5.5}`
- [x] Make the supervised server runner read `HTTPS_PORT` and the Caddy admin port from `.env`, with setup-time parameters as explicit overrides. `{amp/gpt-5.5}`
- [x] Make `reload.ps1` read the same Caddy admin port instead of relying on a separate default. `{amp/gpt-5.5}`
- [x] Derive Scheduled Task names from the instance identity so production, staging, demo, and other Windows BLB instances can coexist on one machine. `{amp/gpt-5.5}`
- [x] Add preflight checks before FrankenPHP starts: if the HTTPS or Caddy admin port is occupied, log the owning process name/PID and fail or retry intentionally. `{amp/gpt-5.5}`
- [x] Wrap the server and scheduler runners in supervisor loops like `run-queue.ps1`, with backoff and append-only logs. `{amp/gpt-5.5}`
- [x] Ensure long-running runners self-restart, while one-shot health/reload/deploy failures exit non-zero for Scheduled Task or operator visibility. `{amp/gpt-5.5}`

### Phase 2 — Make one start command without one process model

Goal: reduce user cognitive load while preserving the hard boundary between local development and supervised staging/production.

- [x] Make `scripts/start-app.ps1` dispatch on `APP_ENV`: `local` uses the current foreground development stack; `staging` and `production` ensure the supervised runtime is installed/running, verify origin health, and print status. `{amp/gpt-5.5}`
- [x] Keep Vite, mkcert-local-dev setup, and foreground FrankenPHP unreachable from the staging/production branch unless a future explicit debug mode is added. `{amp/gpt-5.5}`
- [x] If staging/production tasks are missing, have `start-app.ps1` either self-elevate to install them or print one copy-pasteable elevated command; repeated runs should be idempotent. `{amp/gpt-5.5}`
- [x] Add a status surface to the start command for already-running staging/production instances: task states, origin health, and log paths. `{amp/gpt-5.5}`
- [x] Make `scripts/stop-app.ps1` environment-aware: local stops foreground/dev-owned processes; staging/production stops the instance tasks deliberately, scopes queue-worker matching to the current project root, and never kills an unrecognized listener such as `wslrelay.exe` just because it owns a default port. `{amp/gpt-5.5}`
- [x] Update the Windows installation/ops runbook to say `start-app.ps1` is the start front door, while deploy/reload remain separate verbs for code updates. `{amp/gpt-5.5}`

### Phase 3 — Close origin exposure and development-route gaps by ingress mode

Goal: the selected ingress mode is the only intended public path to staging/production, and staging/production cannot proxy files from a WSL Vite dev server.

- [x] Define the Windows ingress-mode contract, reusing existing `BLB_INGRESS_MODE` conventions where practical instead of adding Cloudflare-specific switches. `{amp/gpt-5.5}`
- [x] Add an environment-driven Caddy `bind` policy so tunnel/reverse-proxy modes bind app site blocks to `127.0.0.1`, while standalone direct and development can explicitly bind a LAN/public address when intended. `{amp/gpt-5.5}`
- [x] Gate the Vite websocket and dev-asset routes in `Caddyfile` behind an explicit development flag or imported snippet, so production never proxies `/@vite`, `/@fs`, `/node_modules`, or `/resources` to port 5173. `{amp/gpt-5.5}`
- [x] Remove or de-prioritize the hardcoded 2020 admin fallback from the application-side FrankenPHP admin resolver once production has a configured admin port and state file path. `{amp/gpt-5.5}`
- [ ] Provide provider-neutral origin verification plus adapter-specific examples for Cloudflare Tunnel, existing IIS/nginx/Caddy reverse proxy, and standalone direct HTTPS.
- [ ] Verify dev HMR still works and staging/production built assets still serve after the Caddy split.

### Phase 4 — Add health checks, self-healing, and alerts

Goal: future origin failures are detected quickly even if no one is watching logs.

- [x] Add a `BLB-Prod-Health` Scheduled Task that runs every few minutes, checks the local origin on the configured HTTPS port, logs failures, and starts the server task if the origin is down. `{amp/gpt-5.5}`
- [x] Include queue and scheduler presence in the local health check, restarting only the missing role. `{amp/gpt-5.5}`
- [ ] Add an external uptime check against the configured public URL when the selected ingress mode has one; assert the expected edge/login boundary rather than a raw `200` from the app.
- [ ] Capture log locations and the first five triage commands in the production runbook: scheduled-task state, listeners, process owners, Caddy access/error logs, Laravel logs, and selected-ingress service state.
- [x] Fold the most common triage output into the `start-app.ps1` staging/production status surface so the software carries the runbook instead of relying only on docs. `{amp/gpt-5.5}`

### Phase 5 — Make deploys and backups operationally boring

Goal: the next code update cannot leave stale assets, stale workers, or unproven backups behind.

- [x] Treat repo-owned `scripts\runtime\windows\deploy.ps1` as the routine production deploy path after merges to `main`. `{amp/gpt-5.5}`
- [x] Keep asset build in the deploy path unless an operator explicitly chooses `-SkipBuild` for a PHP/Blade-only change. `{amp/gpt-5.5}`
- [ ] After every deploy, verify the public URL and a representative authenticated page, then record any production-specific issue in the relevant plan.
- [ ] Choose and configure the off-box backup target.
- [ ] Run and document one restore drill from the encrypted SQLite backup.

### Phase 6 — Generalize Windows instance setup for coexisting servers

Goal: BLB can be installed on a Windows machine that already runs WSL dev, another FrankenPHP instance, nginx, IIS, or another web stack without asking the user to reason through port conflicts.

- [x] During Windows setup, accept one ingress choice in plain language: private/local-only, public through a tunnel, public through an existing web server/proxy, or standalone direct. `{amp/gpt-5.5}`
- [x] During Windows setup, choose or suggest free high ports for staging/production origins and admin APIs, then pin them in `.env`. `{amp/gpt-5.5}`
- [x] Write only durable runtime facts to root `.env`; write setup-only choices, generated artifact paths, and install provenance to `storage/app/.devops/` state files. `{amp/gpt-5.5}`
- [ ] Define retention rules for setup state: transient cross-step state may be deleted after successful setup; durable install manifests may remain for status/reporting but must not override `.env`.
- [x] Never bind staging/production to public `80`/`443` by default; use loopback origins behind tunnels/reverse proxies unless standalone direct mode is explicitly selected. `{amp/gpt-5.5}`
- [x] Add a setup-time warning when a requested port is occupied, including the owning process and a recommended alternate port. `{amp/gpt-5.5}`
- [x] Document the convention: setup may pick ports and ingress mode, start verifies ports and starts the right lifecycle, deploy never changes ports or ingress mode. `{amp/gpt-5.5}`

## Not Now

- Kubernetes, containers, multi-region failover, or Cloudflare Load Balancer. The current failure was local supervision/config drift, not a scaling problem.
- A broad observability platform before a watchdog and external uptime check exist.
- Full automation for every possible tunnel, VPS, reverse proxy, or DNS provider. The first durable contract is provider-neutral local runtime plus clear adapter instructions; provider automation can be added when a real deployment needs it.
- One command that does every verb. `start-app.ps1` starts and reports status; deploy/build/migrate/reload remain a separate environment-aware deploy verb so start stays fast and predictable.
- Multiple configurable production launch paths. The hardening target is one blessed start front door with environment-dispatched internals and loud failures.
