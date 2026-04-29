# Private Extension Repositories

**Purpose:** Keep private licensee extension code out of the public BLB framework repository while developing both in one working tree.

Use this workflow when the framework checkout pushes to a public or shared `origin/main`, but a licensee extension must stay private.

## Recommended Shape

```text
belimbing/                         # Public BLB framework git repo
├── app/
├── docs/
├── extensions/
│   └── ham/
│       └── auto-parts/            # Private nested git repo
│           ├── .git/
│           ├── Config/
│           ├── Database/
│           ├── Livewire/
│           ├── Routes/
│           ├── Tests/
│           └── ServiceProvider.php
└── resources/
    └── extensions/
        └── ham/                   # Private UI overrides; use a matching private repo or keep in the extension repo by convention
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
EOF
```

Use `.git/info/exclude` rather than the committed `.gitignore` when the ignore
is specific to one private implementation. This keeps the public framework repo
neutral.

## Create the Private Extension Repo

```bash
mkdir -p extensions/ham/auto-parts
cd extensions/ham/auto-parts

git init -b main
git remote add origin <private-blb-ham-auto-parts-repo-url>
```

The remote name should be `origin` inside the nested repo. It must point to the
private Ham extension repository, not to the parent BLB framework repository.

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
cd extensions/ham/auto-parts
git status
git commit -m "Ham extension change"
git push origin main
```

Before every framework commit, verify that no private extension paths are staged:

```bash
git diff --cached --name-only | rg '^(extensions/ham|resources/extensions/ham)/' && exit 1 || true
```

## What Belongs Where

Framework repo:

- Generic modules under `app/Base` and `app/Modules`.
- Generic Commerce schemas, contracts, services, and reusable UI.
- Documentation about extension conventions.

Private extension repo:

- Ham-specific catalog seeds, eBay category mappings, policy defaults, and report pages.
- Ham-specific prompt overrides and description boilerplate.
- Ham-specific settings defaults that are not secrets.
- Extension tests under `extensions/ham/auto-parts/Tests`.

Secrets, OAuth tokens, and API keys never belong in either repository. Store
them through `base_settings`.

## Notes

Do not use a branch in the public framework repository for private licensee
code. GitHub visibility is repository-wide, and it is too easy to push the
wrong branch. A nested private repo gives separate remotes, separate history,
and a clear filesystem boundary.
