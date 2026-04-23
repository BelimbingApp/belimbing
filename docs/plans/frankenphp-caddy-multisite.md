# frankenphp-caddy-multisite.md

Status: In Progress
Last Updated: 2026-04-16
Sources: `Caddyfile`, `scripts/start-app.sh`, `scripts/stop-app.sh`, `docs/plans/AGENTS.md`

## Problem Essence

BLB's current local runtime still carries assumptions from the single-site path where its embedded FrankenPHP/Caddy instance may own public HTTPS directly. That breaks down on a host where another site already owns `:80` and `:443`, and it leaves the boundary between system-managed ingress and BLB-managed app processes too loose.

## Desired Outcome

BLB should support a multi-site host by running behind a system-managed Caddy reverse proxy while keeping BLB's own runtime local to the project. `start-app.sh` and `stop-app.sh` should manage only BLB-owned processes, public ingress should belong only to the system Caddy daemon, and native setup should default to this shared-ingress topology rather than treating it as an optional secondary path.

## Public Contract

For shared-host installs, BLB supports one ingress architecture:

- A system Caddy daemon owns public ingress on `:80` and `:443`.
- BLB runs FrankenPHP locally through Octane on a high, non-privileged loopback port.
- BLB's embedded Caddy continues to serve PHP, static files, and Vite proxying for this project only.
- The system Caddy proxies the BLB frontend and backend hostnames to BLB's local FrankenPHP listener.
- `start-app.sh` and `stop-app.sh` never start, stop, enable, or reload the system Caddy service.

This plan does not treat "system FrankenPHP in Caddy mode" as an equal alternative. Supporting that would be a separate architecture with different service ownership, config composition, and failure modes.

## Top-Level Components

### System Caddy

The system daemon is the only public ingress owner in multi-site mode. It terminates TLS, listens on the public ports, and routes the BLB frontend and backend domains to BLB's app-local listener.

### App-Local FrankenPHP

BLB's local FrankenPHP process remains the project-owned application server. It binds to a loopback-only high port and serves the Laravel app through the repository `Caddyfile`.

### Project Caddyfile

The repository `Caddyfile` remains the single source of truth for BLB's internal routing. In shared-ingress mode it should serve plain HTTP on the app-local listener and should not attempt to terminate public TLS itself.

### Runtime Scripts

`start-app.sh` detects whether the shared-ingress path is in use, exports the correct listener settings, starts BLB-owned processes, and prints actionable guidance when the system proxy is missing or misconfigured. `stop-app.sh` stops only BLB-owned processes and leaves the system daemon untouched.

### Setup and Docs Surface

Setup should prepare BLB for shared ingress without silently taking over the host during routine app startup. `scripts/setup.sh` is the right place to bootstrap either a fresh machine or an already-provisioned machine: it should default to shared ingress, still allow an explicit fallback to direct mode, detect what is already installed and running, install only what is missing, and generate the BLB-specific system Caddy integration. The docs and setup steps should generate or describe the required system Caddy site fragment, but host-level installation and service management should remain explicit setup actions rather than side effects of `start-app.sh`.

## Design Decisions

### System Caddy Is The Only Supported Shared Ingress

We should narrow the plan to system Caddy as the sole supported public ingress daemon for multi-site hosts. BLB already adapts to the presence of a system `caddy` process, and the runtime model is easier to reason about when there is exactly one owner of `:80` and `:443`. Keeping "system FrankenPHP in Caddy mode" in scope without an explicit composition model would create ambiguous ownership and a larger support surface.

### Public Ports And Internal Ports Must Be Distinct

The port model should be explicit:

- System Caddy owns public `:80` and `:443`.
- BLB's local FrankenPHP listener uses a loopback-only high port.
- Vite continues to use a separate internal high port.
- The public BLB URLs remain domain-based and do not expose the app-local port in the normal workflow.

This keeps ingress ownership clear and avoids accidental port conflicts or misleading documentation that treats the app-local listener as if it were the public HTTPS port.

### BLB Should Generate Guidance, Not Seize Host Ownership

BLB should not silently install systemd units, enable services, or rewrite the operator's global Caddy configuration as part of normal app startup. The safer boundary is:

- `start-app.sh` verifies prerequisites and explains what is missing.
- `scripts/setup.sh` and its setup steps may install missing host dependencies, generate the BLB-specific Caddy site fragment, and perform explicit one-time service enablement when the user selects shared-ingress mode.
- any privileged host mutation remains an explicit setup action, not a side effect of routine startup.

This respects multi-site hosts where the operator may already manage Caddy centrally for several applications.

### Shared Ingress Should Be The Default Bootstrap Path

The current setup flow installs FrankenPHP and local TLS assets, but it does not yet expose system Caddy as the opinionated default topology. That gap matters because shared ingress is the more flexible architecture: it works for one BLB instance, several BLB instances, or BLB alongside unrelated sites, while direct mode is mainly a single-instance convenience path. We should treat `scripts/setup.sh` as the bootstrap entry point for both fresh machines and existing machines and make it default to shared ingress while still allowing an explicit fallback to:

- direct local mode, where BLB can run without a system Caddy daemon

This keeps host provisioning in setup, where it belongs, and keeps day-to-day startup focused on the project itself. The setup flow should be idempotent: on an existing machine it should validate and reuse working host services rather than behaving as if every run starts from zero.

### Shared-Ingress Setup Should Install Caddy When Missing

When shared-ingress mode is selected, setup should not stop at a warning that Caddy is absent. On a fresh machine it should install and enable Caddy through an explicit setup step when supported. On an existing machine it should verify the current Caddy installation and service state, reuse them when valid, and only change what is missing or misconfigured. If automated installation is unsupported on that platform, setup should fail with a precise instruction. A shared-ingress architecture without a setup path for the ingress daemon creates an avoidable bootstrap gap.

### Stale Caddy Setup Assumptions Must Be Removed

Some current setup and trust flows still reflect older assumptions where "Caddy running" implied a system `caddy` process or where a now-missing Caddy setup step still existed. Implementation should clean that up while introducing the shared-ingress setup path so BLB does not ship contradictory guidance across setup, SSL trust, and startup behavior.

### The Repository Caddyfile Remains Internal To BLB

The repository `Caddyfile` should continue to define BLB's internal routing, worker, and Vite behavior. Shared ingress mode should only change how the app-local listener is exposed, not split BLB's internal routing logic across multiple places.

### Failure Modes Must Be Actionable

Shared-ingress mode introduces a new failure class where BLB starts successfully but is unreachable through the public hostname because the system proxy is absent or stale. The runtime and docs should therefore treat these cases explicitly:

- system Caddy not running
- BLB site snippet not installed
- site snippet present but pointing to the wrong local port
- another process already using the intended app-local listener

The scripts should report these states precisely enough that operators can fix them without guessing.

## Phases

### Phase 1

Goal: lock the architecture and port contract before changing startup behavior.

- [x] Update this plan if implementation work reveals a better ingress boundary or a missing constraint.
- [x] Document the shared-ingress contract in prose in the relevant setup/development docs.
- [x] State clearly that multi-site support means system Caddy owns public ingress and BLB owns only app-local processes.
- [x] Define the exact listener expectations for BLB local FrankenPHP and Vite.
- [x] Define the setup-mode split between direct local mode and shared-ingress mode.
- [x] Define idempotent setup behavior for fresh machines versus already-provisioned machines.

### Phase 2

Goal: make `start-app.sh` and `stop-app.sh` enforce the BLB-owned process boundary cleanly.

- [x] Ensure `start-app.sh` always binds BLB's FrankenPHP listener to a high, non-privileged app-local port in shared-ingress mode.
- [x] Ensure the shared-ingress path uses loopback semantics rather than implying a public listener.
- [x] Remove or simplify any startup behavior that still assumes BLB may own public `:80` or `:443` when system Caddy is active.
- [x] Ensure startup messaging explains the difference between the BLB local listener and the public hostname.
- [x] Confirm `stop-app.sh` remains scoped to BLB-owned processes only.

### Phase 3

Goal: provide a safe and repeatable system-Caddy integration path.

- [x] Update `scripts/setup.sh` so a fresh machine can choose between direct local mode and shared-ingress mode.
- [x] Update `scripts/setup.sh` so an existing machine can validate and reuse an already-working Caddy installation instead of reinstalling or overwriting it.
- [x] Add or update setup steps so shared-ingress mode verifies whether `caddy` is installed and installs it when supported and missing.
- [x] Add or update a setup step that generates the BLB-specific Caddy site fragment or equivalent instructions for the frontend and backend hostnames.
- [x] Add or update setup steps so shared-ingress mode can explicitly enable, start, validate, and reload the system Caddy service.
- [x] Keep host-level installation, enablement, and reload actions explicit in setup rather than implicit during routine startup.
- [x] Document how operators install or reload the generated site fragment without suggesting BLB owns the entire host Caddyfile.
- [x] Document how to rotate or update the BLB site fragment when the local app port changes.
- [x] Remove stale references and assumptions in setup and SSL trust flows that refer to obsolete Caddy setup paths or equate embedded FrankenPHP/Caddy with a system `caddy` daemon.

### Phase 4

Goal: verify the operational behavior that matters on a shared host.

- [ ] Verify BLB can run alongside at least one other site without competing for public ports.
- [ ] Verify BLB remains reachable through the configured public frontend and backend hostnames.
- [ ] Verify startup failure and warning paths for missing system Caddy, missing proxy config, and occupied local listener ports.
- [ ] Verify `stop-app.sh` does not affect the system Caddy daemon or other sites on the host.
