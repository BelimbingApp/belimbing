# blb-hosted-instances

**Status:** Planned — nothing built yet beyond the shared ingress (Cloudflare tunnel + Access + the eBay deletion webhook, verified 2026-06-13 against the dev box). Phase 1 (demo instance) is ready to start; Phase 2 (Ham production) additionally depends on the open productization items in the ham validation plan.
**Last Updated:** 2026-06-13
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

### Phase 0 — Host prerequisites (Windows)

Goal: the box can run a BLB instance natively.

- [ ] Install native runtime set: FrankenPHP (Windows build), PHP extensions BLB needs, MySQL (or MariaDB — decide on install; record the choice here), Node (asset builds), git.
- [ ] Decide instance directory layout (e.g. one folder per instance, each containing the three repos) and record it here.
- [ ] Confirm `cloudflared` Windows service auto-starts after reboot (already installed; verify once).

### Phase 1 — Demo instance

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

- [ ] Same recipe as demo with prod values; **no dev seeders** — fresh DB + the aspect-mapping seeder dependency (open item in the ham validation plan; prod is blocked on it for any publish).
- [ ] Flip the existing `blb.belimbing.app` tunnel rows' origin fields from `local.blb.lara` to `blb.belimbing.app` (via API).
- [ ] Restrict the `blb.belimbing.app` Access policy to Kiat + Ham.
- [ ] Backups: nightly dump of `blb_prod` pushed off-box (target to decide: object storage / second machine — record here); one restore drill before Ham relies on it.
- [ ] Windows validation pass (per the ham validation plan): Pest suite green on the host, sandbox publish smoke, unattended reboot survival.
- [ ] Move the eBay deletion settings ownership to prod (the endpoint then served by the prod instance; re-verify the challenge).

### Phase 3 — Operations

- [ ] Deploy runbook: the pull ×3 + build + migrate sequence per instance, recorded here once exercised twice.
- [ ] Demo lifecycle: invite/offboard steps; periodic review of the Access invitee list.
- [ ] Lightweight health checks: scheduled-task last-run visibility and log locations per instance.

## Out of Scope

- Public signup, multi-region, containers, CI/CD pipelines.
- Drift between demo and prod feature flags beyond AI-keys-off in demo.
