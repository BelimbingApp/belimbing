# Audit Module (app/Base/Audit)

Framework-level audit trail for data mutations and explicit actions. All models are audited by default (opt-out). All actions (HTTP, auth, CLI, jobs) are captured automatically via event listeners and middleware.

## Key Concepts

- **Opt-out, not opt-in.** Every Eloquent model is audited by default. Use `config('audit.exclude_models')` to skip specific models.
- **Zero coupling.** Modules never import anything from Audit. The Audit module listens to Eloquent events globally.
- **Mutations** are captured via global Eloquent event listeners (`eloquent.created/updated/deleted`).
- **Actions** are captured automatically via middleware (HTTP), auth event listeners (login/logout), console event listeners (commands), and queue event listeners (jobs).
- **Deferred writes** — same pattern as `Authz\DatabaseDecisionLogger`. Entries buffer in memory, batch-INSERT after response via `app->terminating()`.
- **Correlation** via `correlation_id` UUID links audit entries to Authz decision logs within the same request.

## Model-Level Configuration (Optional)

Models can define properties to control field strategies. No imports needed — the listener reads them via reflection:

```php
class Employee extends Model
{
    protected array $auditRedact   = ['ssn'];        // values stored as '[redacted]'
    protected array $auditExclude  = ['cached_html']; // field omitted from diff
    protected array $auditTruncate = ['bio' => 500];  // truncated to N chars
}
```

## Opting Out

```php
// Config: exclude entire models
'exclude_models' => [App\Base\Audit\Models\AuditMutation::class],

// Programmatic: suppress during bulk operations
MutationListener::withoutAuditing(fn () => Model::query()->update([...]));
```

## Field Strategy Resolution Order

1. Config `exclude_models` → model skipped entirely
2. Config `exclude_fields` → `created_at`, `updated_at` always stripped
3. Model `$auditExclude` → per-model exclusions
4. Config `redact` (global) → always redacted
5. Model `$auditRedact` → per-model redactions
6. Encrypted cast detected → auto-redact
7. Model `$auditTruncate` → truncate to N chars
8. Config `truncate_default` → safety net (2000 chars)
9. Otherwise → full value stored

## Action Capture (Automatic)

| Source | Listener/Middleware | Event Name |
|---|---|---|
| HTTP requests | `AuditRequestMiddleware` | `http.request` |
| Login/logout | `AuthListener` | `auth.login`, `auth.logout`, `auth.login.failed` |
| Artisan commands | `CommandListener` | `console.command` |
| Queue jobs | `JobListener` | `queue.job.processed`, `queue.job.failed` |

All togglable via config: `log_http_requests`, `log_auth_events`, `log_console_commands`, `log_queue_jobs`.

## Process Actor Types

| `actor_type` | `actor_id` | `url` | Meaning |
|---|---|---|---|
| `user` | 42 | `https://app/employees` | User 42 via browser |
| `agent` | 7 | `https://app/api/...` | AI agent 7 |
| `console` | 42 | `artisan:blb:export employees` | User 42 ran artisan command |
| `console` | 0 | `artisan:migrate --seed` | Artisan with no authenticated user |
| `scheduler` | 0 | `schedule:schedule:run` | Cron-triggered task |
| `queue` | 42 | `queue:App\Jobs\ExportCsv` | Job dispatched by user 42 |
| `queue` | 0 | `queue:App\Jobs\PruneStale` | System-dispatched job |

## Tables

| Table | Retention |
|---|---|
| `base_audit_mutations` | Forever |
| `base_audit_actions` | Configurable (`audit.action_retention_days`, default 90) |

## UI

Two admin pages under the "Audit Log" parent menu:
- `admin/audit/mutations` (route: `admin.audit.mutations`, capability: `admin.audit_log.list`) — Data Mutations with inline field-level diffs.
- `admin/audit/actions` (route: `admin.audit.actions`, capability: `admin.audit_log.list`) — Actions log.

## Migration Prefix

`0100_01_17` — registered in `docs/architecture/database.md`.

## File Structure

```
app/Base/Audit/
├── Config/
│   ├── audit.php          # Module config (opt-out lists, retention, toggles)
│   ├── authz.php          # Capabilities
│   └── menu.php           # Sidebar menu item
├── Database/Migrations/
├── DTO/RequestContext.php
├── Listeners/
│   ├── MutationListener.php   # Global Eloquent events
│   ├── AuthListener.php       # Login/logout/failed
│   ├── CommandListener.php    # Artisan commands
│   └── JobListener.php        # Queue jobs
├── Livewire/AuditLog/
│   ├── Mutations.php
│   └── Actions.php
├── Middleware/AuditRequestMiddleware.php
├── Models/
│   ├── AuditMutation.php
│   └── AuditAction.php
├── Routes/web.php
├── Services/AuditBuffer.php
├── AGENTS.md
└── ServiceProvider.php
```
