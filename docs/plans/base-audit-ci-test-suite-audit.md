# base-audit-ci-test-suite-audit

Status: Complete
Last Updated: 2026-06-20
Sources: GitHub Actions run `27863768193`; `tests/Feature/Audit/AuditLogUiTest.php`; `tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php`; `tests/AGENTS.md`; `.agents/skills/blb-test-suite-audit/references/RUBRIC.md`
Agents: codex/gpt-5

## Problem Essence

The parent GitHub `tests` workflow failed because parent Audit tests referenced nested People and Commerce module code that is present in the local monorepo workspace but not tracked by the parent repository checkout.

## Desired Outcome

Parent Audit tests protect parent-owned source-history behavior without requiring optional nested module checkouts, while module-specific history coverage remains owned by the corresponding module repositories.

## Dispositions

- `tests/Feature/Audit/AuditLogUiTest.php` — tighten. The bridge-rendering case protects the parent record-history bridge on parent-owned detail pages; the nested People employee route reference was removed from the parent slice.
- `tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php` — tighten. The file still protects direct and expanded Audit subject metadata for parent-owned models and framework workflows; the Commerce item-history subcase was removed from the parent slice because it depends on nested module models.

## Phases

- [x] Identify the GitHub failure and select the two failing Audit files as the audit slice. {codex/gpt-5}
- [x] Remove parent-test dependencies on nested People and Commerce modules while preserving parent-owned Audit assertions. {codex/gpt-5}
- [x] Validate the focused Audit test slice locally. {codex/gpt-5}
- [x] Capture any module-owned follow-up coverage that should move to nested repositories. {codex/gpt-5}

Evidence:
- `php artisan test tests/Feature/Audit/AuditLogUiTest.php tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php` passed with 20 tests and 223 assertions.
- `php artisan test tests/Feature/Audit` passed with 39 tests and 278 assertions.
- `vendor/bin/pint --test tests/Feature/Audit/AuditLogUiTest.php tests/Feature/Audit/AuditSourceHistorySubjectCoverageTest.php` passed.
- `git diff --check` passed.
- A full local `php artisan test` run exceeded the 10-minute command limit in this workspace before completing.

Follow-ups:
- Commerce item subject-history coverage belongs in `app/Modules/Commerce` if not already covered there, including item fitment, catalog attribute values, marketplace listing inclusion, and noisy listing draft/snapshot exclusion.
- People employee detail bridge coverage belongs in `app/Modules/People` if not already covered there.
