# Security advisories

Accepted/ignored dependency advisories and the reasoning behind them. The CI
`security` workflow runs `composer audit`, `bun audit`, and a gitleaks secret
scan on every push/PR and weekly; anything not listed here should be fixed
rather than ignored.

## Composer

Ignored advisories live under `config.audit.ignore` in `composer.json`.

| Advisory | Status | Rationale | Review by |
|----------|--------|-----------|-----------|
| `PKSA-5jz8-6tcw-pbk4` | Ignored | Pre-existing ignore carried in `composer.json`. Rationale to be confirmed by maintainers (which package/CVE, and why it is not exploitable here). | 2026-10-07 |

When adding an entry, record the affected package, why it is not exploitable in
Belimbing's usage (or the remediation timeline), and a concrete review date.
Remove the entry — and the `composer.json` ignore — once the dependency is
upgraded past the advisory.
