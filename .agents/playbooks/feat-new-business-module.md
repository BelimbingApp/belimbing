# FEAT-NEW-BUSINESS-MODULE

Intent: create a complete business Module from scratch after choosing its owning Core, Domain, or Extension boundary.

## When To Use

- Building a new full-stack Module under `app/Core/{Module}`, `app/Domains/{Domain}/{Module}`, or `app/Extensions/{Extension}/{Module}`.
- The Module requires schema, models, routes, authorization, menu contribution, Livewire pages, views, seeders, and tests.

## Do Not Use When

- Adding a feature to an existing Module; use the specific feature playbook.
- Building framework infrastructure; Base uses `app/Base/{Component}` and is not a business Module.
- Creating an empty speculative Module without a real ownership or lifecycle boundary.

## Choose Ownership First

| Owner | Module root | Namespace prefix | Use when |
|---|---|---|---|
| required Core Domain | `app/Core/{Module}` | `App\Core\{Module}` | capability is part of every Belimbing installation and updates with the platform |
| optional Domain | `app/Domains/{Domain}/{Module}` | `App\Domains\{Domain}\{Module}` | capability belongs to an installable enterprise Domain |
| Extension | `app/Extensions/{Extension}/{Module}` | `App\Extensions\{Extension}\{Module}` | deployment-owned customization needs relaxed cross-Domain placement |

An optional Domain is the install/enable/disable/update unit and contains Modules. Do not create a separate lifecycle switch for each Module unless the architecture explicitly defines a slot or contribution seam.

All physical ownership segments are PascalCase. Give the Module a stable, path-independent kebab-case ID such as `core/company`, `people/payroll`, or `sb-group/qac`.

## Canonical Reference

The IT Ticket Module at `app/Domains/Operation/IT/` is the reference for a complete optional-Domain business Module. Copy its contracts and internal shape, not its business names or migration prefix.

## Module File Manifest

`{ModuleRoot}` below means one of the three business Module paths selected above.

```text
{ModuleRoot}/
├── ServiceProvider.php
├── composer.json                              # when identity/dependencies/events need metadata
├── Config/
│   ├── authz.php
│   └── menu.php
├── Models/
│   └── {Entity}.php
├── Livewire/
│   └── {Entities}/
│       ├── Index.php
│       ├── Create.php
│       └── Show.php
├── Routes/
│   └── web.php
├── Views/
│   └── livewire/{feature}/{entities}/
│       ├── index.blade.php
│       ├── create.blade.php
│       └── show.blade.php
├── Database/
│   ├── Migrations/
│   │   └── {prefix}_create_{table}_table.php
│   ├── Seeders/
│   │   ├── {Entity}WorkflowSeeder.php
│   │   └── Dev/
│   │       └── Dev{Entity}Seeder.php
│   └── Factories/
│       └── {Entity}Factory.php
└── Tests/
    ├── Feature/
    └── Unit/
```

Core tests conventionally live in root `tests/Feature/Core/{Module}` and `tests/Unit/Core/{Module}`. Optional Domain and Extension tests travel with their source under the Module or Domain `Tests/` tree.

## Implementation Sequence

### Phase 1: schema and seeders — `FEAT-MODULE-SCHEMA`

1. Read `docs/architecture/database.md` and choose the owning Domain's migration prefix.
2. Create the migration in `{ModuleRoot}/Database/Migrations/` with explicit indexes and foreign keys.
3. Register production seeders from the migration with `RegistersSeeders` when schema lifecycle should control them.
4. Create factories and idempotent production seeders.
5. Keep local sample data in `Database/Seeders/Dev/` using `DevSeeder`.

Reference files:

- `app/Domains/Operation/IT/Database/Migrations/0300_01_01_000000_create_operation_it_tickets_table.php`
- `app/Domains/Operation/IT/Database/Seeders/TicketWorkflowSeeder.php`
- `app/Domains/Operation/IT/Database/Factories/TicketFactory.php`

### Phase 2: model and domain behavior

1. Create the model with explicit `$table`, `$fillable`, casts, and relationships.
2. Put business invariants in domain services or value objects rather than Livewire actions.
3. If the entity has a status lifecycle, apply `FEAT-WORKFLOW-CONSUMER` and seed the workflow contract.
4. Override `newFactory()` when the module-owned factory is outside Laravel's default location.

Reference: `app/Domains/Operation/IT/Models/Ticket.php`.

### Phase 3: route, authz, menu, and pages — `FEAT-MODULE-FEATURE`

1. Create `Config/authz.php` using the established capability grammar.
2. Create `Config/menu.php` with stable item IDs, route names, and permission requirements.
3. Create authenticated routes with `authz:` middleware.
4. Create the provider and register Module-owned views with a stable view namespace.
5. Build Index/Create/Show components with existing Livewire concerns.
6. Keep Blade views in `{ModuleRoot}/Views/`; promote only reusable framework components to `resources/core`.
7. Add authorization, validation, CRUD, search, and failure-path tests.

Reference files:

- `app/Domains/Operation/IT/Config/authz.php`
- `app/Domains/Operation/IT/Config/menu.php`
- `app/Domains/Operation/IT/Routes/web.php`
- `app/Domains/Operation/IT/ServiceProvider.php`
- `app/Domains/Operation/IT/Livewire/Tickets/Index.php`
- `app/Domains/Operation/IT/Livewire/Tickets/Create.php`
- `app/Domains/Operation/IT/Livewire/Tickets/Show.php`
- `app/Domains/Operation/IT/Views/livewire/it/tickets/index.blade.php`
- `app/Domains/Operation/IT/Views/livewire/it/tickets/create.blade.php`
- `app/Domains/Operation/IT/Views/livewire/it/tickets/show.blade.php`

### Phase 4: manifest and contracts

Add `composer.json` → `extra.blb` when the Module publishes identity or dependency metadata:

- `module`: stable ID;
- `version`: contract version;
- `requires-modules`: hard dependencies;
- `optional-modules`: optional integrations;
- `publishes-events` / `consumes-events`: public event seams.

Do not expose another Module's tables or internal services as the integration contract. Use events for published facts and service contracts for synchronous collaboration.

### Phase 5: verify

1. Run the owning source's focused tests.
2. Run `php artisan migrate --dev` in local development to prove migration and seeder discovery.
3. Refresh authz data through the established seeder workflow after changing capabilities.
4. Verify route access, menu visibility, view namespace registration, and denied states.
5. For optional Domains, verify disabling the Domain removes the new provider, routes, config, menu, and UI surface.
6. Verify Tailwind production scanning and Vite refresh cover the new installed source.

## Naming Conventions

| Asset | Convention | Example |
|---|---|---|
| ownership directories | PascalCase | `app/Domains/Operation/IT` |
| stable Module ID | kebab-case, path-independent | `operation/it` |
| table | snake_case with ownership prefix | `operation_it_tickets` |
| flow identifier | snake_case | `it_ticket` |
| capability | dot-separated lowercase | `it_ticket.ticket.create` |
| route name | dot-separated lowercase | `it.tickets.index` |
| URL | slash-separated lowercase | `it/tickets` |
| menu item ID | dot-separated lowercase | `it.tickets` |
| view namespace | stable lowercase name | `operation-it::livewire.it.tickets.index` |

## Discovery

The selected Module root participates in the four-root discovery contract:

- Core Module: `app/Core/*/{Artifact}`
- optional Domain Module: `app/Domains/*/*/{Artifact}`
- Extension Module: `app/Extensions/*/*/{Artifact}`

Providers, routes, menu/authz/settings config, migrations, seeders, and Livewire components use the documented scanner for their artifact. Views require the Module provider's `loadViewsFrom()` call. Use `ApplicationTopology`; never add a one-off root glob.

## Required Invariants

- The Module occupies exactly one ownership path; no duplicate or overlay path exists.
- Core is `app/Core/{Module}`, never a child of the optional Domain collection.
- Optional Domain and Extension Modules are exactly two ownership levels below their collection root.
- Physical ownership segments are PascalCase; persisted IDs remain kebab-case and independent of paths.
- No manual provider, route, or Livewire registration when the standard discovery contract already applies.
- Optional-Domain contributions disappear completely when the Domain is disabled.
- Module-owned views stay with the Module; `resources/core` receives only shared framework presentation.
- Extension flexibility does not weaken authz, tenancy, dependency, migration, or test requirements.
- User-facing strings use translation helpers.

## Test Checklist

- Migration and seeders run through the Module-aware flow.
- Production seeders are idempotent; dev seeders are local-only.
- Index renders and search/pagination behavior is correct.
- Create validates and persists atomically.
- Show presents current state and valid actions.
- Authz blocks unauthorized route access and server-side actions.
- Cross-Module dependencies use declared contracts and metadata.
- Disabled optional Domain contributes no runtime surface.
- Production asset build sees Module-owned Blade classes.

## Common Pitfalls

- Treating Core as an optional Domain path.
- Adding a third ownership level below a Domain when it is only an entity or feature.
- Creating a Base component for enterprise behavior.
- Treating an Extension's relaxed taxonomy as permission for hidden dependencies.
- Putting optional-Domain views in `resources/core`.
- Manually registering artifacts already covered by discovery.
- Deriving persisted identity from the current path or PHP class.
- Hardcoding English strings or raw visual primitives instead of shared UI contracts.
