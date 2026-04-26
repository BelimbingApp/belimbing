# FEAT-NEW-BUSINESS-MODULE

Intent: create a complete business module from scratch by composing atomic playbooks in the correct sequence.

## When To Use

- Building a new module under `app/Modules/{Layer1}/{Module}/` (`Layer1` is `Core`, `Operation`, or `Commerce`).
- Module requires the full surface: model, migration, CRUD pages, routes, menu, authz, seeders.

## Do Not Use When

- Adding a feature to an existing module (use the specific atomic playbook).
- Building Base infrastructure (not a module).

## Canonical Reference

The IT Ticket module (`app/Modules/Operation/IT/`) is the canonical first business module. Use it as the template for all new business modules.

## Module File Manifest

Every business module produces this file set. The agent should create all files — no discovery sweeps needed.

```
app/Modules/{Layer1}/{Module}/
├── ServiceProvider.php                          # Auto-discovered; usually empty
├── Config/
│   ├── authz.php                                # Capability keys
│   └── menu.php                                 # Navigation items
├── Models/
│   └── {Entity}.php                             # Eloquent model
├── Livewire/
│   └── {Entities}/
│       ├── Index.php                            # List + search + pagination
│       ├── Create.php                           # Create form
│       └── Show.php                             # Detail + transitions (if workflow)
├── Routes/
│   └── web.php                                  # Route definitions
├── Database/
│   ├── Migrations/
│   │   └── {prefix}_create_{table}_table.php    # Schema + seeder registration
│   ├── Seeders/
│   │   ├── {Entity}WorkflowSeeder.php           # If workflow module
│   │   └── Dev/
│   │       └── Dev{Entity}Seeder.php            # Dev data
│   └── Factories/
│       └── {Entity}Factory.php                  # Test factory

resources/core/views/livewire/{module}/{entities}/
├── index.blade.php
├── create.blade.php
└── show.blade.php
```

## Implementation Sequence

Execute these phases in order. Each phase maps to a playbook for detailed contract.

### Phase 1: Schema & Seeder — `FEAT-MODULE-SCHEMA`

1. Create migration in `Database/Migrations/` with the correct prefix (`0300_*` for `Operation`, `0310_*` for `Commerce`; see `docs/architecture/database.md`).
2. Define schema with proper indexes and foreign keys.
3. Register workflow seeder (if applicable) via `RegistersSeeders` trait.
4. Create workflow seeder in `Database/Seeders/` (if workflow module) — see `FEAT-WORKFLOW-CONSUMER`.
5. Create factory in `Database/Factories/`.

**Reference files:**
- `app/Modules/Operation/IT/Database/Migrations/0300_01_01_000000_create_operation_it_tickets_table.php`
- `app/Modules/Operation/IT/Database/Seeders/TicketWorkflowSeeder.php`
- `app/Modules/Operation/IT/Database/Factories/TicketFactory.php`

### Phase 2: Model — `FEAT-WORKFLOW-CONSUMER` (if workflow) or standalone

1. Create model in `Models/` with `$table`, `$fillable`, `casts()`.
2. Add `HasWorkflowStatus` trait and implement `flow()` if workflow module.
3. Define relationships (`BelongsTo`, etc.).
4. Override `newFactory()` to return the module factory.

**Reference file:**
- `app/Modules/Operation/IT/Models/Ticket.php`

### Phase 3: Feature Pages — `FEAT-MODULE-FEATURE`

1. Create `Config/authz.php` with capability keys following `<domain>.<resource>.<action>` grammar.
2. Create `Config/menu.php` with menu items, parent, permission, route name.
3. Create `Routes/web.php` with auth + `authz:` middleware per route.
4. Create `ServiceProvider.php` (auto-discovered; typically empty for pure modules).
5. Create `Livewire/{Entities}/Index.php` — use `ResetsPaginationOnSearch`, `WithPagination`.
6. Create `Livewire/{Entities}/Create.php` — validate, persist, record initial `StatusHistory` (if workflow).
7. Create `Livewire/{Entities}/Show.php` — detail view, transitions (if workflow), timeline.
8. Create matching Blade views in `resources/core/views/livewire/{module}/{entities}/`.
9. Create tests covering authz, CRUD, and search.

**Reference files:**
- `app/Modules/Operation/IT/Config/authz.php`
- `app/Modules/Operation/IT/Config/menu.php`
- `app/Modules/Operation/IT/Routes/web.php`
- `app/Modules/Operation/IT/ServiceProvider.php`
- `app/Modules/Operation/IT/Livewire/Tickets/Index.php`
- `app/Modules/Operation/IT/Livewire/Tickets/Create.php`
- `app/Modules/Operation/IT/Livewire/Tickets/Show.php`
- `resources/core/views/livewire/it/tickets/index.blade.php`
- `resources/core/views/livewire/it/tickets/create.blade.php`
- `resources/core/views/livewire/it/tickets/show.blade.php`

### Phase 4: Dev Seeder — `FEAT-MODULE-SCHEMA`

1. Create `Database/Seeders/Dev/Dev{Entity}Seeder.php` extending `DevSeeder`.
2. Set `$dependencies` for topological ordering.
3. Create sample data at various lifecycle stages (if workflow, use `WorkflowEngine`).

**Reference file:**
- `app/Modules/Operation/IT/Database/Seeders/Dev/DevTicketSeeder.php`

### Phase 5: Authz Sync & Verify

1. Run `php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"`.
2. Run `php artisan migrate --seed` to verify full bootstrap.
3. Verify route accessibility, menu visibility, and capability enforcement.

## Naming Conventions

| Asset | Convention | Example |
|-------|-----------|---------|
| Module directory | PascalCase | `app/Modules/Operation/IT/` |
| Table name | snake_case with module prefix | `operation_it_tickets` |
| Flow identifier | snake_case | `it_ticket` |
| Capability keys | `<flow>.<resource>.<action>` | `it_ticket.ticket.create` |
| Route names | dot-separated lowercase | `it.tickets.index` |
| URL paths | slash-separated lowercase | `it/tickets` |
| Menu item IDs | dot-separated lowercase | `it.tickets` |
| Migration prefix | `0300_*` Operation, `0310_*` Commerce | `0300_01_01_000000_` |
| Livewire namespace | `{module}.{entities}.{page}` | Auto from directory |

## Auto-Discovery (No Manual Registration Needed)

These are discovered automatically from glob patterns — do not register manually:

- **ServiceProvider**: `app/Modules/*/*/ServiceProvider.php`
- **Routes**: `app/Modules/*/*/Routes/web.php`
- **Menu config**: `app/Modules/*/*/Config/menu.php`
- **Authz config**: `app/Modules/*/*/Config/authz.php`
- **Audit config**: `app/Modules/*/*/Config/audit.php` (optional — only when module needs audit exclusions)
- **Migrations**: `app/Modules/*/*/Database/Migrations/`
- **Livewire components**: `app/Modules/*/*/Livewire/**/*.php`
- **Dev seeders**: `app/Modules/*/*/Database/Seeders/Dev/Dev*Seeder.php`

## Required Invariants

- Module path follows `app/Modules/{Core|Operation|Commerce}/{Module}/` — two levels under `Modules/`, not three.
- All auto-discovered files use the exact directory names above (PascalCase).
- No manual provider, route, or Livewire component registration.
- Authz seeder must be re-run after changing `Config/authz.php`.
- Blade views go in `resources/core/views/livewire/{module}/{entities}/`, not inside the module directory.
- All user-facing strings use `__()` translation helpers.

## Test Checklist

- Migration runs cleanly via `php artisan migrate`.
- Workflow seeder is idempotent (if applicable).
- Index page renders and search filters correctly.
- Create form validates and persists.
- Show page displays detail and supports transitions (if workflow).
- Authz gates block unauthorized access (route middleware + action-level).
- Dev seeder creates sample data at various lifecycle stages.

## Common Pitfalls

- Creating a third directory level (e.g., `Modules/Operation/IT/Ticket/`) — keep it flat at the module level.
- Manually registering providers, routes, or Livewire components instead of relying on auto-discovery.
- Forgetting to run the authz seeder after adding capabilities.
- Placing Blade views inside the module directory instead of `resources/core/views/`.
- Using raw Tailwind primitives instead of semantic tokens.
- Hardcoding English strings instead of using `__()`.
