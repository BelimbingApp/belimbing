# Audit Module (app/Base/Audit)

Framework-level audit trail for data mutations and explicit actions. All models are audited by default (opt-out). All actions (HTTP, auth, CLI, jobs) are captured automatically via event listeners and middleware.

## Key Concepts

- **Opt-out, not opt-in.** Every Eloquent model is audited by default. Use `config('audit.exclude_models')` to skip specific models.
- **Zero coupling.** Modules never import anything from Audit. The Audit module listens to Eloquent events globally.
- **Mutations** are captured via global Eloquent event listeners (`eloquent.created/updated/deleted`).
- **Actions** are captured automatically via middleware (HTTP), auth event listeners (login/logout), console event listeners (commands), and queue event listeners (jobs). Product-level semantic actions are captured through the Foundation-owned `SemanticActionRecorder` contract; modules must not import Audit classes directly.
- **Deferred writes** — same pattern as `Authz\DatabaseDecisionLogger`. Entries buffer in memory, batch-INSERT after response via named Laravel `defer()->always()` callbacks.
- **Trace correlation** via compact `trace_id` links audit entries to Authz decision logs within the same request/process. Trace IDs are 12-character Crockford Base32 values stored without separators; UI may display them as 4-4-4 groups.

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

Models can also expose audit subject metadata with duck-typed methods. No module imports Audit classes:

```php
public function getAuditSubject(): ?array
{
    return ['name' => 'employee', 'id' => $this->employee_id];
}

public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
{
    return [[
        'subject_name' => 'employee',
        'subject_id' => $this->employee_id,
        'subject_identifier' => '2026-05-15',
        'event' => $event,
        'old_values' => ['shift_code' => 'DAY'],
        'new_values' => ['shift_code' => 'NIGHT'],
    ]];
}
```

Subject `id` values may be integers or stable strings (UUID, ULID, prefixed integration IDs). Audit stores them as normalized strings; do not cast string identifiers to integers in model metadata or source-history queries.

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

## Semantic Product Actions

Use `App\Base\Foundation\Contracts\SemanticActionRecorder` when a workflow needs to explain user intent beyond transport-level `http.request` rows. Audit binds that contract to an Audit writer when the module is loaded; Foundation provides a null implementation so callers stay independent.

Semantic actions should use stable machine event names and sanitized payload context:

```php
app(SemanticActionRecorder::class)->record(
    event: 'user.roles.assigned',
    summary: __('Assigned :count roles to user', ['count' => 2]),
    source: __('User'),
    subject: ['name' => 'user', 'id' => $user->id],
    surface: 'admin.users.show',
    uiElement: __('Assign roles button'),
    context: ['role_names' => ['Admin', 'Auditor']],
);
```

Guidelines:
- Keep semantic actions reserved for meaningful business/user-intent operations, not every technical request.
- Do not store secrets or full before/after values in semantic action context; mutation diffs carry field-level data and redaction rules.
- Include `subject` when the action affects a record so Actions search and trace timelines can show handles like `User#3`.
- Include `surface` and `uiElement` when the UI trigger matters for reproduction.
- Semantic actions are retained by default because they explain long-lived mutation rows; pass `retain: false` only for deliberately short-lived product events.

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
| `base_audit_actions` | Configurable (`audit.action_retention_days`, default 90); rows with `is_retained = true`, including default semantic product actions, are not pruned. |

## UI

Two admin pages under the "Audit Log" parent menu:
- `admin/audit/mutations` (route: `admin.audit.mutations`, capability: `admin.audit.log.list`) — Data Mutations with inline field-level diffs.
- `admin/audit/actions` (route: `admin.audit.actions`, capability: `admin.audit.log.list`) — Actions log.

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
│   ├── Actions.php
│   └── SourceHistory.php
├── Middleware/AuditRequestMiddleware.php
├── Models/
│   ├── AuditMutation.php
│   └── AuditAction.php
├── Routes/web.php
├── Services/AuditBuffer.php
├── Services/AuditLogPresenter.php
├── Services/AuditSourceHistory.php
├── Services/AuditTraceTimeline.php
├── Services/AuditSemanticActionRecorder.php
├── AGENTS.md
└── ServiceProvider.php
```
