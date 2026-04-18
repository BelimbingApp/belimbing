# Base/Audit Module

**Document Type:** Module Design & Progress Tracker
**Purpose:** Record every meaningful action and data mutation by any actor (human or AI) with full context
**Last Updated:** 2026-03-21

---

## Problem Essence

Record every meaningful action and data mutation by any actor (human or AI) with full context — who, what, when, where (IP, URL), role, and field-level diffs — so the system provides a complete, queryable, permanent audit trail.

## Design Decisions

### Relationship to Existing Logs

Authz `DecisionLog` and (future) AI `ae_tool_calls` remain **separate**. They serve different concerns with different schemas, retention policies, and query patterns. Audit provides a **correlation mechanism** (shared `correlation_id` UUID) to trace a full request lifecycle across all log types.

### Two Tables, One Module

| Table | Purpose | Retention |
|---|---|---|
| `base_audit_mutations` | Data changes: model created/updated/deleted. Field-level old→new diffs. | **Forever** |
| `base_audit_actions` | Non-data actions: login, export, page visit, tool execution. | **Configurable** (default 90 days) |

### Actor Model

Reuses `Authz\DTO\Actor` and `Authz\Enums\PrincipalType`. The enum was extended with process types:

| `PrincipalType` | `actor_id` | Context |
|---|---|---|
| `user` | User ID | Browser / API requests |
| `agent` | Agent ID | AI agent operations |
| `console` | User ID or 0 | Artisan commands (may be run by a user) |
| `scheduler` | 0 | Cron-triggered scheduled tasks |
| `queue` | Dispatching user ID or 0 | Queued job execution |

The `url` field captures the specific identity: HTTP URL for web, `artisan:{command}` for console, `schedule:{task}` for scheduler, `queue:{JobClass}` for jobs.

`PrincipalType::isProcess()` returns true for `CONSOLE`, `SCHEDULER`, `QUEUE`.

### Field Strategies

| Strategy | What's Stored | Use Case |
|---|---|---|
| **Redact** | `[redacted]` for both old & new | Passwords, tokens, secrets |
| **Full** | Complete old & new values | Default for most fields |
| **Truncate** | First N chars + `[truncated, X chars]` | Long text (descriptions, body) |
| **Exclude** | Field omitted from diff entirely | Cached/computed columns, timestamps |

Resolution order:
1. Config `exclude_models` → model skipped entirely
2. Config `exclude_fields` → `created_at`, `updated_at` always stripped
3. Model `$auditExclude` → per-model field exclusions
4. Config `redact` (global) → always redacted
5. Model `$auditRedact` → per-model redactions
6. Encrypted cast detected → auto-redact
7. Model `$auditTruncate` → truncate to N chars
8. Config `truncate_default` → safety net for long text
9. Otherwise → full value stored

### Deferred Writes

Same buffered-flush pattern as `DatabaseDecisionLogger`: entries collected in-memory during request, batch-INSERT after response sent via `app->terminating()`.

---

## Architecture

### Components

| Component | Responsibility |
|---|---|
| **RequestContext** | DTO singleton. Captures IP, URL, user agent, actor, role, correlation_id once per request. |
| **Auditable** | Eloquent trait. Auto-registers observer for create/update/delete. Captures dirty fields and original values. |
| **AuditService** | Public contract for explicit action logging. |
| **AuditBuffer** | Internal. Collects mutation and action entries, batch-INSERTs on terminating(). |

### File Structure

```
app/Base/Audit/
├── Config/
│   └── audit.php
├── Contracts/
│   └── AuditService.php
├── Concerns/
│   └── Auditable.php
├── Database/
│   └── Migrations/
│       ├── 0100_01_17_000000_create_base_audit_mutations_table.php
│       └── 0100_01_17_000001_create_base_audit_actions_table.php
├── DTO/
│   └── RequestContext.php
├── Models/
│   ├── AuditMutation.php
│   └── AuditAction.php
├── Services/
│   ├── DatabaseAuditService.php
│   └── AuditBuffer.php
├── AGENTS.md
└── ServiceProvider.php
```

### Migration Prefix

`0100_01_17` — registered in the Migration Registry. Dependencies: Database.

---

## Progress

- [x] Design & planning
- [x] Create tracking doc
- [x] Register migration prefix `0100_01_17` in `docs/architecture/database.md`
- [x] Config (`Config/audit.php`)
- [x] DTO (`DTO/RequestContext.php`)
- [x] Contract (`Contracts/AuditService.php`)
- [x] Models (`Models/AuditMutation.php`, `Models/AuditAction.php`)
- [x] Migrations (mutations table, actions table)
- [x] AuditBuffer (`Services/AuditBuffer.php`)
- [x] DatabaseAuditService (`Services/DatabaseAuditService.php`)
- [x] Auditable trait (`Concerns/Auditable.php`)
- [x] ServiceProvider
- [x] AGENTS.md
- [x] Run migrations and verify
- [x] Tests (`tests/Feature/Audit/AuditableTraitTest.php` — 11 tests, 28 assertions)
- [x] Rebuild: opt-out global listeners (MutationListener, AuthListener, CommandListener, JobListener)
- [x] Rebuild: HTTP middleware (AuditRequestMiddleware)
- [x] Rebuild: Remove trait/contract/service (replaced by global listeners)
- [x] UI: Livewire component, Blade view, route, menu, authz config
