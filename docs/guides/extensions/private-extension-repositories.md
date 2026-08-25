# Private Extension Repositories

**Purpose:** Keep deployment-owned Extension code out of the public Belimbing platform repository while developing both in one composed working tree.
**Last Updated:** 2026-08-25
**Related:** `docs/architecture/module-system.md`, `docs/guides/extensions/database-migrations.md`, `.agents/skills/blb-repo-sync/SKILL.md`

Use this workflow when the platform checkout pushes to a public or shared `origin/main`, while customer-, operator-, or organization-specific capabilities must remain in a separate private repository.

Extensions are intentionally a mixed bag. A source may contain an adapter, tenant overlay, cross-Domain composition, report set, or complete private capability. That flexibility does not change the two-level Module shape or the platform's quality and safety contracts.

## Recommended Shape

```text
belimbing/                              # Public Belimbing platform repository
├── app/
│   ├── Base/                           # Platform-owned framework components
│   ├── Core/                           # Platform-owned required Domain
│   ├── Domains/                        # Optional Domain source checkouts
│   └── Extensions/
│       ├── AGENTS.md                   # Tracked platform guidance
│       ├── Ham/                        # Private Extension source checkout
│       │   ├── .git/
│       │   ├── AutoParts/              # Extension Module
│       │   │   ├── Config/
│       │   │   ├── Database/
│       │   │   ├── Livewire/
│       │   │   ├── Routes/
│       │   │   ├── Views/
│       │   │   ├── Tests/
│       │   │   └── ServiceProvider.php
│       │   └── <FutureModule>/
│       └── <AnotherExtension>/         # Another private Extension checkout
└── resources/
    └── core/                           # Shared framework presentation
```

Application ownership segments use PascalCase: `Ham`, `AutoParts`. Stable logical IDs remain lowercase and path-independent: `ham/auto-parts`.

Each Extension source has its own `.git`, `origin`, commits, and release history. The platform repository sees only the tracked `app/Extensions/AGENTS.md`; it never stages private source contents.

Extension Modules own their PHP, routes, migrations, config, tests, and Module-specific views below `app/Extensions/{Extension}/{Module}`. Put views in `Views/` and register them from the Module provider with a stable view namespace. Do not create a companion global resource tree.

If a Module owns CSS or JavaScript, keep it under `Assets/`. Private source assets are never injected automatically; the host build must explicitly import a reviewed entry point. Prefer shared `resources/core` tokens and components.

## Parent Repository Guard

The committed platform `.gitignore` ignores Extension source checkouts while preserving the root guide:

```gitignore
/app/Extensions/*
!/app/Extensions/AGENTS.md
```

The rule protects every clone from accidentally staging private code in the public parent repository. Do not weaken it with per-Extension negations. Only an explicit forced add could cross the boundary.

## Create a Private Extension Repository

Ham example:

```bash
mkdir -p app/Extensions/Ham
cd app/Extensions/Ham

git init -b main
git remote add origin <private-blb-ham-repo-url>
git push -u origin main
```

The nested repository's `origin` must point to the private Extension source, not to the parent Belimbing repository.

## Install From Software Administration

The software composition interface offers installable Extensions from two sources, both marked in the UI:

- **Discovered** — for every GitHub owner with a token stored under **Administration → System → Software → GitHub Access**, the screen lists that owner's repositories carrying the `belimbing-extension` topic. The topic is an opt-in published by the repository owner; unmarked repositories never appear, and there is no free-text URL entry. The install key is the PascalCase form of the repository name (`blb-ham` → `BlbHam`); pin the entry in `config/extensions.php` to install under a different directory name.
- **Curated** — entries pinned in `config/extensions.php`. A catalog entry always wins over a discovered candidate with the same key, so the catalog doubles as a pin/override layer (fixed repo URL, deployment-specific description):

```php
return [
    'catalog' => [
        'Ham' => [
            'repo' => '<private-blb-ham-repository-url>',
            'description' => 'Private Ham Extension.',
        ],
    ],
];
```

The catalog key is the PascalCase `{Extension}` directory below `app/Extensions`. The repository URL is delivery provenance and does not define Module identity. Keep the committed default catalog empty unless that deployment is intentionally composed with a specific private source.

Automated development setup follows the same boundary. Supply optional private sources at runtime through newline-delimited `repository|destination` entries instead of committing them to `.agents/setup`:

```bash
BLB_EXTENSION_SOURCES='<private-blb-ham-repository-url>|app/Extensions/Ham' .agents/setup
```

Authentication belongs in the deployment's Git credential configuration or authorized secret environment, never in the repository URL.

For a private GitHub repository, store a token for its GitHub owner under **Administration → System → Software → GitHub Access** before installing. The installer:

1. clones into `app/Extensions/{Extension}`;
2. runs pending migrations in a fresh Artisan process;
3. reloads runtime discovery;
4. returns the operation log.

Uninstall removes the checkout. Tables, migration ledger rows, and settings remain unless the operator explicitly confirms destructive cleanup.

## Discovery Checklist

BLB discovers Extension Modules at `app/Extensions/{Extension}/{Module}` after Base, Core, and enabled Domains.

| Surface | Expected path |
|---|---|
| Provider | `app/Extensions/{Extension}/{Module}/ServiceProvider.php` |
| Routes | `app/Extensions/{Extension}/{Module}/Routes/web.php` or `Routes/api.php` |
| Menu | `app/Extensions/{Extension}/{Module}/Config/menu.php` |
| Authorization | `app/Extensions/{Extension}/{Module}/Config/authz.php` |
| Settings | `app/Extensions/{Extension}/{Module}/Config/settings.php` |
| Migrations | `app/Extensions/{Extension}/{Module}/Database/Migrations/` |
| Views | `app/Extensions/{Extension}/{Module}/Views/` plus provider `loadViewsFrom()` |
| Tests | `app/Extensions/{Extension}/{Module}/Tests/` |
| Skills | source- or Module-level `.agents/skills/{Skill}/SKILL.md` |

The provider namespace follows normal `App\` PSR-4 mapping. For example:

```text
app/Extensions/Ham/AutoParts/ServiceProvider.php
App\Extensions\Ham\AutoParts\ServiceProvider
```

No bespoke Extension autoloader or kebab-to-Pascal path conversion exists. A lowercase physical source or Module directory is invalid even when its logical ID is kebab-case.

After adding or moving provider/config files, clear cached framework state when the current environment caches it:

```bash
php artisan config:clear
php artisan route:clear
```

If routes are missing, confirm the filename is `web.php` or `api.php` inside `Routes/`. If migrations are missing, confirm the `Database/Migrations/` path and Laravel filename convention; then use `docs/guides/extensions/database-migrations.md`.

## Daily Workflow

Platform work:

```bash
git status
git commit -m "Platform change"
git push origin main
```

Ham Extension work:

```bash
cd app/Extensions/Ham
git status
git commit -m "Ham Extension change"
git push origin main
```

A cross-repository change requires coordinated commits and pushes in every affected source. Record the compatibility-safe landing order whenever one repository depends on another.

Use `.agents/skills/blb-repo-sync/SKILL.md` for full composed-checkout synchronization. The platform syncs first, then optional Domains, then Extensions.

## What Belongs Where

Platform repository:

- Base components and the required Core Domain;
- generic contracts, discovery seams, and shared framework UI;
- topology and Extension authoring guidance;
- no deployment-specific secrets or policy defaults.

Optional Domain repository:

- coherent enterprise Domain Modules intended to install and lifecycle together;
- Domain-wide tests, metadata, and contribution anchors.

Private Extension repository:

- deployment-specific seeds, mappings, policy defaults, reports, and overlays;
- private adapters or cross-Domain composition;
- Extension-owned tests, views, assets, and agent guidance;
- behavior whose relaxed semantic placement is intentional and documented.

Secrets, OAuth tokens, and API keys belong in authorized settings/credential stores, never in any repository.

## Safety Notes

Do not use a private branch in the public platform repository for private code. Repository visibility is not branch-specific, and pushing the wrong branch is too easy. A nested private repository provides separate access control, remotes, history, and an explicit filesystem boundary.

Before deleting or relocating an Extension checkout, verify the exact nested Git root and whether it has uncommitted or unpushed changes. Removing code is recoverable from its remote; deleting its persistent database state is a separate destructive decision.
