# FEAT-GLOBAL-LOCALE

Intent: implement or modify BLB's application-wide locale policy, bootstrap, and formatting integration without scattering locale logic across modules.

## When To Use

- a task adds or changes global locale resolution
- a task introduces localization settings under `Administration -> System`
- a task updates request-time date, number, or currency formatting based on locale
- a task needs a licensee-address bootstrap signal for locale initialization

## Do Not Use When

- the change only affects timezone selection without touching locale policy
- the change is a small translation-string edit with no locale resolution impact
- the change is user-scoped settings work unrelated to global formatting policy

## Minimal File Pack

- `docs/todo/locale-support-plan.md`
- `app/Base/Locale/Contracts/LocaleContext.php`
- `app/Base/DateTime/Services/DateTimeDisplayService.php`
- `resources/core/views/components/layouts/status-bar.blade.php`
- `app/Base/System/Routes/web.php`

## Reference Shape

- Base owns locale policy and formatting contracts
- Core Company supplies licensee-country bootstrap data through a contract adapter
- System owns the admin page, route, menu item, and status-bar confirmation entry point

## Required Invariants

- Base must not depend directly on Core company or address models
- locale bootstrap from the licensee address is one-time initialization, not a per-request live fallback
- inferred locales must be persisted as unconfirmed until an administrator confirms them
- request-time formatting must use shared services or shared UI primitives, not ad hoc `number_format()` / browser-default locale guesses
- canonical raw datetime surfaces must remain explicit and distinct from localized display surfaces

## Implementation Skeleton

1. add or update `app/Base/Locale/` contracts, config, and middleware
2. provide a Core adapter for licensee-country bootstrap through a Base-owned contract
3. wire locale into shared display services such as datetime / numbers / currency
4. add or update the `Administration -> System -> Localization` page, route, menu item, and capability
5. add or update the status-bar warning for unconfirmed locale state
6. update the locale rollout doc with build status and alignment review

## Test Checklist

- unit test locale resolution from persisted setting, inferred bootstrap, and config fallback
- feature test the localization admin flow and persistence
- feature test the status-bar warning visibility and clearing behavior
- regression test datetime formatting that depends on locale

## Common Pitfalls

- using the licensee address as a live runtime locale fallback on every request
- storing language-only locales like `en` when region-specific formatting is the real requirement
- letting Base code query Core company/address models directly
- forgetting to seed authz changes after adding the localization capability
- updating browser-local datetime formatting but leaving it on `Intl.DateTimeFormat(undefined, ...)`
