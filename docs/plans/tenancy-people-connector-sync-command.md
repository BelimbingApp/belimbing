# tenancy-people-connector-sync-command

**Status:** Proposed upstream repair
**Last Updated:** 2026-10-12
**Sources:** `app/Base/Tenancy/Config/domain_commands.php`, `app/Base/Tenancy/AGENTS.md`, composed-smoke run `38184532339`
**Agents:** codex/gpt-5

## Problem Essence

The composed PeopleConnector checkout still exposes `people-connector:sync` through `App\Domains\PeopleConnector\Connector\Console\Commands\SyncWorkforceCommand` without extending `TenantScopedCommand`. The platform tenant command audit correctly fails after the original rollout exemption expired on 2026-10-07.

## Desired Outcome

`people-connector:sync` binds an explicit tenant before handling provider sync work, and the platform allowlist can remove its final PeopleConnector exception without weakening the audit.

## Design Decisions

Option A is to keep extending the platform allowlist indefinitely. That keeps CI green but hides a tenant-safety hole, so it violates the honesty and low-entropy rules.

Option B is to disable PeopleConnector command auditing in composed smoke. That would lower the platform contract for one Domain and make future drift easier to miss.

Option C is to keep one short, dated platform exception while the owning `blb-people-connector` command migrates to `TenantScopedCommand`. This preserves the platform contract, keeps the remaining gap visible, and localizes the durable code change to the owning Domain repository.

Recommendation: Option C. The platform should carry only this narrow exception until the upstream command is migrated, then delete the allowlist entry.

## Public Contract

Domain commands that touch tenant-owned data must extend `App\Base\Tenancy\Console\TenantScopedCommand`. Temporary exceptions need a reason, an expiry date, and a plan entry naming the upstream owner.

## Phases

- [ ] Update `SyncWorkforceCommand` in `BelimbingApp/blb-people-connector` to extend `TenantScopedCommand` and keep its existing behavior behind the required `--tenant` binding.
- [ ] Add or update connector-side command tests proving `people-connector:sync` refuses a missing, unknown, or inactive tenant and succeeds with a valid tenant.
- [ ] Run connector CI plus the platform composed-smoke audit against the connector head.
- [ ] Remove `people-connector:sync` from the platform `domain_commands.tenant_scope.allowlist` after the connector fix is released into composed smoke.
