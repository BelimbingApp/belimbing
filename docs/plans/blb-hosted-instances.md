# blb-hosted-instances

**Status:** Phase 0 done; Phase 2 production instance built and verified at the origin on the Windows host (2026-06-13) — running on `https://blb.belimbing.app:8643`, first admin + "Kiat Solutions" company seeded, encrypted backup validated, boot-time services + go-live scripted in `D:\Repo\BelimbingApp\ops\`. Remaining to go live: run the elevated service installer + reboot check, flip the Cloudflare tunnel ingress (needs dev's API token; touches the shared edge), restrict Access to Kiat + Ham. Phase 1 (demo) deferred (KIV) by Kiat 2026-06-14. eBay publish still depends on open productization items in the ham validation plan.
**Last Updated:** 2026-06-14
**Sources:** `extensions/ham/docs/plans/ham-ebay-sandbox-live-validation.md` (steps A–D, host decision, Windows validation pass item); design discussion 2026-06-12/13 (Windows-native FrankenPHP decision, demo-site idea, phone-test Host-header lesson).
**Agents:** claude/claude-fable-5

## Problem Essence

BLB exists only as Kiat's WSL2 dev instance. Ham needs a hosted production instance and prospects need a no-install demo — both must be reachable from anywhere without making BLB a publicly exposed app.

## Desired Outcome

- **`demo.belimbing.app`** — an Access-gated demo instance with seeded, realistic data; an invitee gets a URL and an email OTP, plays from any device, installs nothing; the data resets on a schedule.
- **`blb.belimbing.app`** — Ham's production instance on the always-on Windows machine; Ham logs in from his shop, BLB stays private behind Cloudflare Access.
- Dev stays untouched at `local.blb.lara` (LAN-only, no public hostname).
- One box, one tunnel connector, zero inbound ports, nightly backups pushed off the box.

## Already in place (verified)

- Domain `belimbing.app` on Cloudflare DNS.
- Tunnel `blb` (healthy, connector installed as a Windows service on the host) with hostnames `ebay-hook.belimbing.app` (path-locked to `/webhooks/*`; eBay deletion challenge verified end-to-end) and `blb.belimbing.app` (Access policy `Access by Email`).
- Cloudflare API token + account id in Administration → System → Integration Parameters (`integrations.cloudflare.*`) for tunnel-config changes by API.
- The Host-header lesson, proven by a phone test: **an instance's site name must be its public hostname**, or Laravel-generated URLs (redirects, assets, OAuth callback) leak an unreachable internal name to the visitor's browser.

## Top-Level Components

- **The host** — Kiat's always-on Windows machine. Native Windows FrankenPHP (v1.12+), not WSL2: Windows is the licensee deployment target (Ham's machine is Windows), so Kiat absorbs Windows quirks before any licensee does.
- **Per-instance stack** — its own checkout (three nested repos), database, `.env`, FrankenPHP/Caddy site, queue worker, and per-minute scheduler trigger; all as Windows services / Task Scheduler jobs.
- **Shared ingress** — the one `cloudflared` connector; per-hostname ingress rules route to each instance's local port; Cloudflare Access gates the two app hostnames; non-routed paths/hostnames die at the edge.
- **Demo reseed job** — scheduled rebuild of the demo database from migrations + seeders, restoring pristine data.
- **Backup job (prod)** — nightly database dump pushed off-box; the restore path is part of the deliverable, not an afterthought.

## Design Decisions

- **Instance identity = public hostname.** Each hosted instance sets `APP_DOMAIN`/`APP_URL` to its public hostname; Caddy serves that site name with `tls internal` (cloudflared does not verify origin TLS; the public certificate is Cloudflare's); the tunnel rule's Origin Server Name and HTTP Host Header carry the same name. Dev keeps `local.blb.lara` and is the reason the current `blb.belimbing.app` rows point at an unusable origin — they get flipped when prod exists.
- **One box, three identities.**

  | Instance | Site name / public hostname | Local port | Database | Assets | Access |
  |---|---|---|---|---|---|
  | dev | `local.blb.lara` (LAN only) | 443 | dev DB | Vite dev | none (LAN) |
  | demo | `demo.belimbing.app` | e.g. 8543 | `blb_demo` | built | `Access by Email` + invitees |
  | prod | `blb.belimbing.app` | e.g. 8643 | `blb_prod` | built | Kiat + Ham only |

- **`blb.belimbing.app` is reserved for Ham.** The demo gets its own hostname so no one ever migrates users between meanings of a URL.
- **Demo is a throwaway by design.** Own database, reseeded on a schedule from dev seeders + the Ham auto-parts catalog seeds. The dev no-wipe rule protects *working* data; the demo DB's whole purpose is to be rebuilt, so a scheduled fresh rebuild is the feature, not a violation. No eBay connection in the demo initially (the marketplace pages honestly show "not connected"); a separate sandbox keyset is a later option if demos need the publish story.
- **Invite = Access policy + BLB user.** Adding an invitee is two steps: their email into the demo Access policy, a demo-company user in BLB. No public signup surface exists; removing them is the reverse.
- **Deploys are git-driven.** Each instance directory holds the three nested repos (main, `app/Modules/Commerce`, `extensions/ham`) pinned to their main branches; a deploy is pull ×3, composer install, asset build, migrate. No artifact pipeline until the pain demands one.
- **Windows validation pass gates prod** (recorded in the ham validation plan): full Pest suite on the host, one sandbox publish smoke, services surviving an unattended reboot.

## Public Contract

- URLs: `https://demo.belimbing.app` (demo), `https://blb.belimbing.app` (Ham prod), `https://ebay-hook.belimbing.app/webhooks/ebay/account-deletion` (eBay compliance, no Access).
- Cloudflare artifacts: tunnel `blb`; Access policies per app hostname; tunnel-config changes via the stored `integrations.cloudflare.api_token`.
- Settings: `commerce.marketplace.ebay.deletion_verification_token` / `…deletion_endpoint_url` (global layer, prod-owned once prod exists).

## Phases

### Phase 0 — Host prerequisites (Windows) — DONE 2026-06-13

Goal: the box can run a BLB instance natively.

- [x] Install native runtime set: **FrankenPHP 1.12.4 (bundled PHP 8.5.7, Caddy 2.11.4)** at `C:\Users\user1\.frankenphp`; **SQLite** (PHP `pdo_sqlite`/`sqlite3` from the FrankenPHP bundle — chosen over MySQL/MariaDB), **Bun 1.3.14** (winget), local **Composer 2.10.1** under `storage/app/.devops`, git, gh. PHP CLI quirk recorded: the bundled `php.exe` loads no default ini, so all CLI/scheduled commands must set `PHPRC` to `storage/app/.devops/php` (handled by `ops/_env.ps1`). Bun on Windows needs `bun install --backend copyfile` (cross-volume hardlink EPERM otherwise).
- [x] Instance directory layout (recorded): one folder per instance under `D:\Repo\BelimbingApp\` — `production\` (belimbing main + nested `app\Modules\Commerce` and `extensions\ham`), future `demo\`, and `ops\` for instance runtime scripts kept **outside** the checkouts so git-driven deploys stay clean. Per-instance public port: prod **8643** (443 is held by WSL2 dev's `wslrelay` on `::1`).
- [x] `cloudflared` Windows service confirmed Running + StartType Automatic (token/remotely-managed tunnel — no local config.yml; ingress changes via API).

### [KIV] Phase 1 — Demo instance

**Deferred (KIV — keep in view) by Kiat 2026-06-14.** Production (Phase 2) and Ham's eBay onboarding take priority; the demo instance is parked, not abandoned. Resume once prod is settled. Nothing below is started.

Affected pages: `https://demo.belimbing.app` from a device off the LAN (the phone test, repeated, must fully work this time).
Goal: an invitee with an Access-listed email reaches the BLB login, signs in to a seeded demo company, and every link/asset stays on `demo.belimbing.app`.

- [ ] Checkout + `.env` per the matrix (`APP_DOMAIN=demo.belimbing.app`, own port, `blb_demo` DB, production app env, built assets).
- [ ] Create DB; migrate; seed (dev seeders + `ham:auto-parts:seed`); create demo users.
- [ ] Windows services / Task Scheduler: FrankenPHP, queue worker, per-minute scheduler.
- [ ] Tunnel: add `demo.belimbing.app` public hostname → local demo port, origin fields = `demo.belimbing.app` (via API with the stored token).
- [ ] Access: application + policy for `demo.belimbing.app`; add first invitees.
- [ ] Scheduled reseed (nightly): rebuild `blb_demo` from migrations + seeds; document the cadence on the demo dashboard or login notice so invitees know data is ephemeral.
- [ ] End-to-end phone test: OTP login → dashboard → item workbench → no `local.blb.lara` anywhere.

Risks: demo exposes whatever AI/provider features are configured — leave provider keys unset in the demo `.env`/settings so invitees cannot spend money or reach external AI.

### Phase 2 — Production instance (Ham)

Goal: Ham's instance live at `blb.belimbing.app`; continues into Phase 3 of the ham validation plan (production keyset, OAuth, first live listing).

Progress 2026-06-13: instance running and verified at the origin (`https://blb.belimbing.app:8643`, `tls internal`); root 302→`https://blb.belimbing.app/login` (clean public hostname, no internal-name leak); login page renders with built assets; first admin `kiatng@hotmail.com` / company "Kiat Solutions" seeded and `Auth::validate` passes. Runtime/ops scripts live in `D:\Repo\BelimbingApp\ops\`. Three repos cloned at **main** (note: dev's `extensions/ham` runs the `commerce-ebay-account-setup` feature branch — confirm `main` carries the aspect-mapping seeder before any live publish).

- [x] Same recipe as demo with prod values; **no dev seeders** — fresh DB created with `migrate --seed --force` (production seeders only). Aspect-mapping seeder dependency for eBay publish still open (ham validation plan).
- [x] Flipped the `blb.belimbing.app` tunnel ingress (tunnel `blb`, id `cba63ef8-c687-465d-ba5e-65353cffd18f`) from `local.blb.lara` origin to `https://127.0.0.1:8643` (originServerName + httpHostHeader = `blb.belimbing.app`, http2Origin + noTLSVerify). eBay-hook rule and 404 catch-all untouched. Cloudflare API params copied from dev into the **prod** instance settings (`integrations.cloudflare.*`, prod-owned now). 2026-06-13.
- ~~[ ] Restrict the `blb.belimbing.app` Access policy to Kiat + Ham. Current "Access by Email" policy allows 3 emails: `kiatsiong.ng@gmail.com`, `tohmeimei2@gmail.com`, `hamletsemails@gmail.com` (Ham). Trim to Kiat + Ham when ready (dashboard/Access API).~~
- Note: exposing dev at `dev.belimbing.app` was explored and **declined** — BLB pins URLs to one canonical `APP_URL` via `URL::forceRootUrl()` in `AppServiceProvider`, so an instance can't cleanly serve two canonical hostnames. Dev stays LAN-only at `local.blb.lara` per the original design. Multi-canonical/per-request host would be a framework change (own plan).
- [x] Backups: nightly `blb:db:backup --prune` (app-key encrypted, sqlite) validated and one real artifact produced under `storage/app/private/backups/production`; wired as a daily 03:00 SYSTEM task by `ops/install-services.ps1`. **Off-box target still to decide** (object storage / second machine) — set `BLB_BACKUP_OFFBOX` / `-BackupOffboxTarget` to enable the off-box mirror. Restore drill still pending.
- [~] Windows validation pass: `ops/install-services.ps1` run (2026-06-13) — server/queue/scheduler now run as **SYSTEM `AtStartup` Scheduled Tasks** (`BLB-Prod-{Server,Queue,Scheduler}` + daily `BLB-Prod-Backup`), verified running via parent chain (svchost→powershell→frankenphp, owner SYSTEM) and serving. Unattended-reboot survival CONFIRMED 2026-06-13: after a real reboot, FrankenPHP auto-started ~52s post-boot as a SYSTEM task and all endpoints returned with no manual step (public ebay-hook 200, blb 302 Access, local origin 302). Pest suite run on the Windows host 2026-06-14: **1995 passed / 17 failed / 15 skipped**; 16 failures were a single real bug (ham `main`'s readiness contributor eager-loaded the dropped `descriptions` relation → item page 500) exposed because prod deploys ham `main` while the working eBay code lived on an unpushed dev-local branch — fixed by pushing + merging that branch to ham `main` (PR #1, `0595dc5`); re-ran the 36 affected tests green. Remaining: 1 minor Windows-only `BashTool` test; sandbox publish→revise→withdraw smoke from the host.
- [x] Moved eBay deletion settings ownership to prod (2026-06-13): `commerce.marketplace.ebay.deletion_{verification_token,endpoint_url}` copied dev→prod; `ebay-hook.belimbing.app` tunnel ingress flipped dev→prod (`https://127.0.0.1:8643`, host `blb.belimbing.app`, `/webhooks/*` path-lock kept, no Access). Challenge re-verified locally AND through the public URL (`PUBLIC_CHALLENGE_MATCH=True`). Token/endpoint unchanged from eBay's registration, so the existing portal config still matches; re-clicking verify in the eBay dev portal is optional.

### Phase 3 — Operations

- [ ] Deploy runbook: the pull ×3 + build + migrate sequence per instance, recorded here once exercised twice.
- [ ] Demo lifecycle: invite/offboard steps; periodic review of the Access invitee list.
- [ ] Lightweight health checks: scheduled-task last-run visibility and log locations per instance.

## Out of Scope

- Public signup, multi-region, containers, CI/CD pipelines.
- Drift between demo and prod feature flags beyond AI-keys-off in demo.
