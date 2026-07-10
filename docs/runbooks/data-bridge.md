# Data Bridge Runbook

Document Type: Operational runbook
Scope: One-way, user-mediated transfer between Belimbing instances
Last Updated: 2026-07-10

This runbook covers the connected Data Bridge path: a target-authorized user issues one short-lived receive key, a separately authorized source user reviews and streams one relational package, and the target reviews a deterministic plan before applying it. The architecture and remaining rollout work live in [`../plans/base-data-bridge.md`](../plans/base-data-bridge.md).

Data Bridge is not database replication. It preserves selected physical primary and foreign keys, inserts missing rows, recognizes byte-equivalent rows as unchanged, and blocks every ambiguous difference as a conflict. It never performs generic updates, deletes, source-wins resolution, or module-specific import hooks.

## Security boundary

- Admin pages require normal Belimbing login and the relevant Data Bridge capability.
- The narrow `POST /data-bridge/receive/{grantId}` route does not accept an admin session as transport authorization. It accepts only the target-issued bearer secret, requires a bounded `Content-Length`, is rate-limited, and admits bytes to Incoming only after complete verification.
- A receive key is bound to one expected source ID and role, target ID and role, registered scope, byte limit, and expiry. The 256-bit secret is shown once; only its SHA-256 hash is stored.
- The key belongs in the HTTPS `Authorization` header. Never place it in a URL, package, log, issue, chat, shell argument, or committed file.
- Receipt never plans or applies data. Plan review and apply are separate target-side capabilities.

## Deployment checklist

Log in as an authorized administrator and open **Data Bridge → Settings**. Data Bridge operator configuration lives as global values in `base_settings`; it does not require Data Bridge entries in `.env`.

On **Identity**, set a stable instance ID such as `livenpc`, a recognizable name, and the truthful environment role. Belimbing derives a deterministic fallback from the application environment and URL for first access, but save an explicit ID before exchanging a receive key. Configure the development source identity there as well. Do not change the ID or role while receive keys or unapplied packages remain outstanding; their target and direction bindings are intentionally immutable.

On **Transport**, enter the private LAN HTTPS base URL first and the Cloudflare URL second, one per line. Belimbing appends the grant-specific receive path. The source UI presents the advertised routes before preview/send; the CLI uses the first unless `--receive-endpoint` selects another exact advertised endpoint.

Use **Storage**, **Transfer limits**, and **Diagnostics** to review the private filesystem disk, path separation, retention, and hard byte/row/table bounds. Values are validated before they are written to `base_settings`; framework defaults remain in code when no override exists.

Before issuing a key, verify:

- every advertised hostname resolves from the source;
- TLS is valid and trusted by the source host;
- the reverse proxy or tunnel accepts the configured package size and send duration;
- **Maximum package bytes** in Data Bridge Settings is no larger than the narrowest proxy/origin limit;
- the configured bridge disk is private and has enough space for Receiving, Incoming, and Outgoing copies;
- the target backup policy is working before any production apply.

## LAN and Cloudflare ingress

Prefer LAN when the source can reach the target privately. Cloudflare Tunnel is the remote fallback; it carries the same raw HTTPS request and does not change package contents or trust policy.

If Cloudflare Access protects the hostname, create a path-specific application/policy for `/data-bridge/receive/*` that allows the request to reach Belimbing without an interactive login. Keep the ordinary admin application protected by human Access/login policy. Do not broaden the bypass to admin, plan, apply, database, or other application routes.

The receive key is the application-level capability on that narrow path. Persistent Cloudflare service tokens, SSH keys, and JSON-RPC/Base64 wrapping are not part of this workflow.

Test LAN and Cloudflare with separate short-lived grants. For each path, compare the source Outgoing SHA-256 with the target Incoming receipt SHA-256. Because the grant ID is embedded in the package, packages created for different grants intentionally have different hashes; the end-to-end equality within each delivery is the proof that matters.

## Capabilities

Assign only what each operator needs:

| Work | Capability |
|---|---|
| Open Data Bridge | `admin.system.database-bridge.view` |
| Preview/export/send | `admin.system.database-bridge-export.execute` |
| Issue/revoke target keys | `admin.system.database-bridge-receive-grant.manage` |
| Inspect and plan Incoming | `admin.system.database-bridge-plan.review` |
| Apply a reviewed plan | `admin.system.database-bridge-apply.execute` |

Diagnostic capture uses separate create/delete capabilities and remains development-only.

## Recommended web workflow

### 1. Review source tables

On the source, open **Administration → System → Database → Data Bridge → Export**. Choose the module scope and start with **Select entire module**. Review every table before requesting a key; table selection is the explicit boundary for authored data versus operational state.

For the first Investment promotion, the current live inventory contains 24 tables and 42,043 rows, with no credential, cookie, saved browser-session, or filesystem-path columns. The credentials remain in encrypted Base settings, outside the Investment scope. `kiat_investment_maybank_realized_positions.trading_account` is an account identifier attached to captured investment history, not a login credential. Two tables are operational rather than authored investment data:

- `kiat_investment_agent_tasks` contains enabled schedules, prompts, last-run state, and manual run requests.
- `kiat_investment_agent_runs` contains machine execution history and output/error excerpts.

Deselect both for the first pilot unless the operator deliberately wants to promote that execution state. This leaves 22 authored/captured Investment tables and 42,036 rows in the current source snapshot. Do not add a module adapter or hidden exclusion rule; the reviewed table list is the honest transfer contract.

### 2. Issue the receive key on the target

Log in to the target and open **Receive**. Enter the exact source instance ID, source role, and registered scope. Generate the key, copy the complete JSON bundle once, and convey it directly to the source operator.

The plaintext secret cannot be recovered after the page is refreshed or **Hide permanently** is used. If it is lost or exposed, revoke the grant and issue another.

CLI equivalent on the target:

```text
php artisan blb:db:bridge:grant extensions/kiat/investment --source-id={source-id} --source-name="Development" --source-role=development --json
```

Redirect CLI output only to an access-restricted temporary file. Delete that file after the send is resolved.

### 3. Bind, preview, and send on the source

Paste the complete bundle into **One-time receive key** and choose **Use receive key**. Confirm the target ID, role, scope, route, and exact selected tables. Prefer the advertised LAN route when reachable.

Choose **Preview export**. Review:

- table and record counts;
- per-table counts, sizes, and hashes;
- total estimated bytes;
- exact target/scope/grant binding;
- preview SHA-256.

Choose **Send package** only after that review. Export recomputes the preview and refuses to proceed if source data changed.

CLI preview and send:

```text
php artisan blb:db:bridge:export extensions/kiat/investment --receive-grant-file={protected-key-file} --json
php artisan blb:db:bridge:export extensions/kiat/investment --receive-grant-file={protected-key-file} --receive-endpoint={exact-advertised-url} --commit --send --preview-hash={preview-sha256} --json
```

Omit `--receive-endpoint` to use the first advertised route. Use repeated `--table={table}` options when sending an exact subset such as the 22-table Investment pilot.

### 4. Verify Incoming on the target

A successful send returns `202` only after the target verifies and stores the complete package. In **Incoming**, compare the package ID and SHA-256 with the source result. Receipt alone changes no selected domain row.

CLI verification:

```text
php artisan blb:db:bridge:inspect {package-id} --json
```

### 5. Build and review the plan

Create a plan from Incoming and review every aggregate:

- `insert` is a missing primary key with no unique/reference collision;
- `unchanged` is a byte-equivalent destination row;
- `conflict` blocks the entire plan.

CLI:

```text
php artisan blb:db:bridge:plan {package-id} --json
```

Never override a conflict in Data Bridge. Correct the source or destination deliberately, issue a new key, and export again. A different primary-key row, unique collision, missing parent, schema difference, or partially null reference is intentionally not mergeable here.

### 6. Apply only after recovery review

For production, verify that the target can create a fresh backup and that its APP_KEY or provider-snapshot recovery material is available out of band. Follow [`database-backup.md`](database-backup.md) for the restore drill.

The web workflow requires recent password confirmation plus the exact package and plan SHA-256 values. CLI apply requires both reviewed hashes and `--confirm`:

```text
php artisan blb:db:bridge:apply {plan-sha256} --package-sha256={package-sha256} --confirm --json
```

Apply takes a global lock, re-verifies the package, recomputes the plan against current destination state, creates and verifies the production recovery point, then inserts in foreign-key order inside a transaction. Any stale state or failure stops or rolls back the mutation.

### 7. Verify and repeat

After apply, run read-only row-count, foreign-key, and application smoke checks. Do not trigger jobs, notifications, scraping, agent schedules, or external integrations during the check.

Issue a new grant and export the same selection again. The second plan should contain only `unchanged` actions. A new insert or any conflict means the pilot is not complete.

## Failure and retry behavior

| Symptom | Meaning and response |
|---|---|
| Wrong, expired, or revoked key | No body is admitted. Issue a fresh key if necessary. |
| Truncated/rejected stream | The grant remains issued when no package was accepted. Correct the route/limit and retry while it is unexpired. |
| Source reports a network error after upload | Check target Incoming and History before retrying. The target may have consumed the grant and committed the receipt before the response was lost. |
| Grant is consumed but source saw no success | Treat target Incoming as authoritative; compare package ID/SHA-256. Do not issue another send blindly. |
| Grant is still issued and Incoming is empty | Retry the reviewed export while the key and preview remain valid. |
| Package expired | Issue a new grant and export a new package. |
| Plan has conflicts | Resolve outside Data Bridge, then re-export. Never force the plan. |
| Apply lock held | Another apply is active. Wait for it to finish and rebuild/review if destination state changed. |
| Backup or hash verification failed | No production row is changed. Repair recovery first; do not bypass it. |
| Apply transaction failed | Inserts roll back and the failure is recorded. Investigate, confirm destination state, and retry the same ready plan only if its fresh-plan check still passes. |

## Revocation, audit, and retention

Revoke an unconsumed key from **Receive**, or by CLI:

```text
php artisan blb:db:bridge:grant-revoke {grant-id}
```

The **History** tab records grant issue/revocation/expiry/consumption, export, receipt, planning, apply, pruning, and failures without payload values or plaintext secrets. Use package ID, grant ID, package SHA-256, and plan SHA-256 to correlate source and target records.

Preview retention before deletion:

```text
php artisan blb:db:bridge:prune --json
```

`--commit` removes only eligible applied Incoming files and abandoned Receiving uploads by default. Unapplied Incoming and Outgoing files require the explicit `--include-unapplied` override after operator review. Ledger rows remain.

## Pilot completion evidence

Record these values in the operations journal without the receive secret or payload data:

- source and target instance IDs/roles;
- selected scope and exact tables;
- route used (LAN or Cloudflare);
- grant ID and expiry;
- package ID, record count, bytes, and source/target SHA-256;
- plan SHA-256 and insert/unchanged/conflict counts;
- backup ID, artifact path, and verified backup SHA-256;
- apply actor/time and post-apply integrity checks;
- second-plan all-unchanged result;
- disposable restore-drill date and result.
