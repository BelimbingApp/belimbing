# Data Share Runbook

Document Type: Operational runbook
Scope: One-way, user-mediated transfer between Belimbing instances
Last Updated: 2026-07-11

Data Share moves a reviewed relational snapshot from one Belimbing instance to another. The source publishes an immutable, expiring transfer offer; an authorized target operator pastes that offer and pulls the stream; the target separately plans and applies. Architecture and rollout evidence live in [`../plans/base-data-share.md`](../plans/base-data-share.md).

Data Share is not replication. It preserves selected physical keys, inserts missing rows, recognizes byte-equivalent rows as unchanged, and blocks every ambiguous difference. It never performs generic updates, deletes, source-wins resolution, or module-specific hooks.

## Security boundary

- Admin pages require normal Belimbing login and the relevant capability.
- The narrow source `GET /data-share/offers/{offerId}` route accepts only the offer bearer secret, is rate-limited, and streams one already-published package. It cannot enumerate, export, plan, or apply.
- The source owns scope, exact package bytes, expiry, advertised routes, and revocation. The target owns whether to fetch and whether a verified receipt proceeds to plan/apply.
- The 256-bit secret is shown once. Only its SHA-256 hash is stored. Put it only in the HTTPS `Authorization` header; never in a URL, log, issue, shell argument, or committed file.
- The package is target-neutral. Target identity and authorization are bound locally when Incoming records the verified receipt.
- Fetch may repeat until expiry or revocation. Package/offer/hash replay protection makes receipt and apply idempotent; repeated download is not repeated mutation.

## Configure each instance

Open **Data Share → Settings**. Operator configuration lives in global `base_settings`, not `.env`.

On **Identity**, save a stable ID, recognizable name, and truthful role. Do not change identity while offers or unapplied receipts remain outstanding.

On a source’s **Transport** settings, enter reachable source HTTPS base URLs, private LAN first and Cloudflare second. Belimbing appends `/data-share/offers/{offerId}`. Set the offer lifetime and target fetch timeout. On every target, review its local storage and transfer limits; target limits may be stricter than the values declared by an offer.

Before publishing:

- every advertised source hostname resolves from the target;
- TLS is valid and trusted by the target host;
- the source proxy/tunnel permits the configured response size and duration;
- protected Outgoing/Receiving/Incoming storage has enough space;
- the production target backup policy and restore material are ready.

## LAN and Cloudflare

Prefer LAN when the target can reach the source privately. Cloudflare Tunnel is the WAN fallback and carries the same raw HTTPS response without changing package identity.

If Cloudflare Access protects the source hostname, create a path-specific application/policy for `/data-share/offers/*` that lets the bearer-authenticated request reach Belimbing without interactive login. Keep ordinary source admin routes protected by human Access/login policy. Do not broaden the bypass to admin, database, plan, apply, or other application routes.

The source offer secret is the only application capability on that narrow path. Persistent Cloudflare service tokens, SSH keys, JSON-RPC/Base64 wrapping, and permanent instance pairing are not part of this workflow.

## Capabilities

| Work | Capability |
|---|---|
| Open Data Share | `admin.system.data-share.view` |
| Preview/publish a source offer | `admin.system.data-share-offer.create` |
| List/revoke source offers | `admin.system.data-share-offer.manage` |
| Review/fetch into target Incoming | `admin.system.data-share-offer.accept` |
| Inspect and plan Incoming | `admin.system.data-share-plan.review` |
| Apply a reviewed plan | `admin.system.data-share-apply.execute` |

Diagnostic capture uses separate create/delete capabilities and remains development-only.

## Web workflow

### 1. Review source tables

On the source, open **Data Share → Share**. Select the module scope, use **Select entire module**, then review every table. Table selection—not module code—is the explicit boundary.

For the first Investment promotion, the current scope has 24 shareable tables and 42,043 rows. Deselect `kiat_investment_agent_tasks` and `kiat_investment_agent_runs` unless operational schedules/history are deliberately being promoted. The reviewed authored-data pilot is 22 tables and 42,036 rows.

### 2. Preview and publish on the source

Choose **Preview export** and review table/record counts, per-table sizes, total estimate, and preview SHA-256. Publishing recomputes the snapshot and refuses if source data changed.

Choose **Publish transfer offer**, copy the complete JSON bundle, and convey it directly to the target operator. Hiding or refreshing permanently removes the plaintext display; revoke and republish if it is lost or exposed.

CLI:

```text
php artisan blb:db:share:export extensions/kiat/investment --table={table} --json
php artisan blb:db:share:export extensions/kiat/investment --table={table} --publish --preview-hash={preview-sha256} --json
```

Use repeated `--table` options for an exact subset. Redirect offer JSON only to an access-restricted temporary file and delete it after the fetch is resolved.

### 3. Review and fetch on the target

Open **Incoming**, paste the complete offer, and choose **Review offer**. Verify source identity/role, scope, counts, size, package hash, expiry, and advertised route. Prefer LAN; choose Cloudflare only when private routing is unavailable.

Choose **Fetch into Incoming**. The target checks policy before networking, streams into bounded private staging, verifies response headers and exact bytes/hash/manifest/payloads, then records a local receipt. Fetch never plans or applies.

CLI:

```text
php artisan blb:db:share:fetch --offer-file={protected-offer-file} --json
php artisan blb:db:share:fetch --offer-file={protected-offer-file} --offer-endpoint={exact-advertised-url} --json
php artisan blb:db:share:inspect {package-id} --json
```

### 4. Build and review the plan

Create a plan from Incoming. `insert` is a safely missing row, `unchanged` is byte-equivalent, and any `conflict` blocks the entire apply.

```text
php artisan blb:db:share:plan {package-id} --json
```

Resolve conflicts outside Data Share, then publish a new snapshot. Never force a conflict.

### 5. Apply after recovery review

Production requires a new verified backup. Follow [`database-backup.md`](database-backup.md) and confirm recovery material out of band. Web apply requires recent password confirmation and the exact package/plan SHA-256 values.

```text
php artisan blb:db:share:apply {plan-sha256} --package-sha256={package-sha256} --confirm --json
```

Apply locks, re-verifies, recomputes against current destination state, creates/verifies recovery, and inserts transactionally.

### 6. Verify and repeat

Run read-only row-count, foreign-key, and application smoke checks without triggering jobs, notifications, scraping, schedules, or external integrations.

While the offer remains available, fetching it again must produce the same package SHA-256 and the same Incoming receipt. Replanning after apply should be entirely `unchanged`. If the offer expired, publish an equivalent snapshot and compare its plan rather than expecting identical package bytes.

## Failure and retry

| Symptom | Response |
|---|---|
| Malformed, expired, or revoked offer | Obtain a newly published offer. |
| Target rejects before networking | Correct direction, scope registration, expiry, or local limits. |
| Truncated or invalid response | Partial Receiving data is deleted. Retry the same offer while available. |
| Network error after the source began streaming | Check target Incoming/History, then retry safely; source bytes remain immutable. |
| Repeated fetch | It returns the same bytes and existing matching receipt; it does not duplicate apply. |
| Hash/header mismatch | Refuse the package. Verify the selected advertised route and source integrity; do not bypass checks. |
| Plan conflicts | Resolve deliberately outside Data Share and publish again. |
| Apply lock/backup failure | Wait or repair recovery; no domain row is changed. |
| Apply transaction failure | Inserts roll back. Investigate and retry only if a fresh plan still matches. |

## Revocation, audit, and retention

Revoke an available offer from **Published** or:

```text
php artisan blb:db:share:offer-revoke {offer-id}
```

Revocation blocks new fetches immediately but does not delete an already verified target receipt. **History** records offer publish/revoke/expiry/download, export, fetch, receipt, planning, apply, pruning, and failures without payload values or secrets.

Preview retention before deletion:

```text
php artisan blb:db:share:prune --json
```

`--commit` removes eligible applied Incoming files and abandoned Receiving uploads by default. Unapplied Incoming and Outgoing files require explicit operator review/override. Ledger rows remain.

## Pilot evidence

Record source/target identities and roles, exact scope/tables, route, offer ID/expiry, package ID/count/bytes/source-and-target SHA-256, plan hash/counts, backup ID/hash, apply actor/time, post-apply checks, repeat all-unchanged result, and restore-drill result. Never record the bearer secret or domain payload values.
