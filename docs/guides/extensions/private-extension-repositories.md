# Private Extension Repositories

**Purpose:** Keep private licensee extension code out of the public BLB framework repository while developing both in one working tree.

Use this workflow when the framework checkout pushes to a public or shared `origin/main`, but a licensee extension must stay private.

## Recommended Shape

```text
belimbing/                         # Public BLB framework git repo
├── app/
├── docs/
├── extensions/
│   ├── ham/                       # Private nested git repo root (licensee: Ham)
│   │   ├── .git/
│   │   ├── auto-parts/            # Ham auto-parts module
│   │   │   ├── Config/
│   │   │   ├── Database/
│   │   │   ├── Livewire/
│   │   │   ├── Routes/
│   │   │   ├── Tests/
│   │   │   └── ServiceProvider.php
│   │   └── <future-module>/       # Additional Ham modules live here
│   └── sb-group/                  # Private nested git repo root (licensee: SBG)
│       ├── .git/
│       ├── qac/                   # SBG QAC module
│       │   ├── Config/
│       │   ├── Database/
│       │   ├── Models/
│       │   ├── Routes/
│       │   ├── Services/
│       │   └── ServiceProvider.php
│       └── <future-module>/       # Additional SBG modules live here
└── resources/
    └── extensions/
        ├── ham/                   # Private UI overrides for Ham
        └── sb-group/              # Private UI overrides for SBG
```

The parent BLB repo ignores the private extension path locally. The extension
has its own `.git`, its own `origin`, and its own commits.

## Parent Repository Guard

Add these entries to the parent repository's local exclude file:

```bash
cat >> .git/info/exclude <<'EOF'

# Private licensee extension repositories.
/extensions/ham/
/resources/extensions/ham/
/extensions/sb-group/
/resources/extensions/sb-group/
EOF
```

Use `.git/info/exclude` rather than the committed `.gitignore` when the ignore
is specific to one private implementation. This keeps the public framework repo
neutral.

## Create the Private Extension Repo

Ham example:

```bash
mkdir -p extensions/ham
cd extensions/ham

git init -b main
git remote add origin <private-blb-ham-repo-url>
```

SBG example:

```bash
mkdir -p extensions/sb-group
cd extensions/sb-group

git init -b main
git remote add origin <private-blb-sbg-repo-url>
```

The remote name should be `origin` inside the nested repo. It must point to the
licensee's private repository, not to the parent BLB framework repository.

After the remote exists:

```bash
git push -u origin main
```

## Daily Workflow

Framework work:

```bash
git status
git commit -m "Framework change"
git push origin main
```

Ham extension work:

```bash
cd extensions/ham
git status
git commit -m "Ham extension change"
git push origin main
```

SBG extension work:

```bash
cd extensions/sb-group
git status
git commit -m "SBG extension change"
git push origin main
```

Before every framework commit, verify that no private extension paths are staged:

```bash
git diff --cached --name-only | rg '^(extensions/(ham|sb-group)|resources/extensions/(ham|sb-group))/' && exit 1 || true
```

## What Belongs Where

Framework repo:

- Generic modules under `app/Base` and `app/Modules`.
- Generic Commerce schemas, contracts, services, and reusable UI.
- Documentation about extension conventions.

Private extension repo:

- Licensee-specific seeds, mappings, policy defaults, and report pages.
- Licensee-specific prompt overrides and description boilerplate.
- Licensee-specific settings defaults that are not secrets.
- Extension tests under `extensions/<licensee>/<module>/Tests`.

Secrets, OAuth tokens, and API keys never belong in either repository. Store
them through `base_settings`.

## Notes

Do not use a branch in the public framework repository for private licensee
code. GitHub visibility is repository-wide, and it is too easy to push the
wrong branch. A nested private repo gives separate remotes, separate history,
and a clear filesystem boundary.
