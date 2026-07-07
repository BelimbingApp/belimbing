# Security advisories

Accepted/ignored dependency advisories and the reasoning behind them. The CI
`security` workflow runs `composer audit`, `bun audit`, and a gitleaks secret
scan on every push/PR and weekly; anything not listed here should be fixed
rather than ignored.

## Composer

There are no accepted Composer advisories at this time.

`PKSA-5jz8-6tcw-pbk4` / CVE-2026-41570 was previously ignored, but the ignore
is no longer needed: the affected PHPUnit 12 line is `12.5.21`, the first
patched 12.x release is `12.5.22`, and this repository is locked to
`phpunit/phpunit` 12.5.30.

When adding an entry, record the affected package, why it is not exploitable in
Belimbing's usage (or the remediation timeline), and a concrete review date.
Accepted advisories must live under `config.audit.ignore` in `composer.json`;
remove the entry here and the ignore once the dependency is upgraded past the
advisory.
