# Locale & Timezone Consolidation

**Status:** Complete  
**Last Updated:** 2026-04-13  
**Sources:** `app/Base/Locale/`, `app/Base/DateTime/`, `app/Modules/Core/Company/Services/CompanyTimezoneResolver.php`, `app/Modules/Core/Geonames/Models/Country.php`

## Problem Essence

Locale and timezone configuration is scattered across five UI surfaces with duplicated resolution logic and a confirmed formatting bug: the Localization preview and `DateTimeDisplayService` render US-style dates (MM/DD/YYYY) for `en-MY` because Carbon's bundled locale file `en_MY.php` is a bare `return require __DIR__.'/en.php'` — it inherits the US `L => 'MM/DD/YYYY'` format with no regional override. PHP's ICU `IntlDateFormatter` has the correct CLDR data and produces DD/MM/YYYY for `en-MY`. Any other Carbon locale file that similarly delegates to its base language without overriding `formats.L` will have the same problem; `en_SG` and `en_AU` already carry their own overrides and are not affected.

## Desired Outcome

1. **Correct locale-aware date/time display** — server-side display strings that today use Carbon `isoFormat` with `L`/`LT` tokens must switch to ICU `IntlDateFormatter` so regional formatting is correct. Number and currency formatting already use `Illuminate\Support\Number` (backed by Symfony/intl `NumberFormatter`), which is unaffected; the Localization preview rows that call `Number::format()` and `Number::currency()` are already correct.
2. **Single source of truth** for company timezone resolution — one public method (`currentCompanyTimezone()`), used by every consumer.
3. **Graceful UX when company timezone is unset** — top bar clearly communicates "(not set)" instead of silently showing UTC.
4. **Localization page becomes the central hub** — timezone, currency, and format previews all visible here (read-only context for settings owned elsewhere).
5. **Geonames-driven automation** — when locale is confirmed, currency sample data auto-populates from the licensee's Geonames country record.

## Current Landscape

Five settings surfaces exist today:

| Surface | What it controls | Where stored |
|---------|-----------------|--------------|
| System > Localization | Locale code (e.g. `en-MY`) | `ui.locale` (global) |
| Company > Timezone | IANA timezone per company | `ui.timezone.default` (company scope) |
| Top bar selector | Display mode: Company/Local/UTC | `ui.timezone.mode` (employee/company scope) |
| DB Tables browser | Timezone mode (aligned with system) | Alpine `tableTzMode`, initialized from system `TimezoneMode` |
| Geonames | Country ↔ currency_code, languages | `geonames_countries` table |

## Design Decisions

### D1: ICU IntlDateFormatter replaces Carbon isoFormat for locale-aware date/time display

Carbon's bundled `Lang/en_MY.php` is `return require __DIR__.'/en.php'` — no regional date format override. The `L` token therefore produces `MM/DD/YYYY` (US order). PHP's ICU extension has the correct data (verified: ICU 74.2, `IntlDateFormatter('en-MY', SHORT)` → `13/04/2026`). The `en_SG.php` and `en_AU.php` files already override `formats.L` and are not affected.

Scope of the switch: server-side display strings in `DateTimeDisplayService::format()` (COMPANY mode) and the Localization preview page (`Index.php render()`), both of which previously used Carbon `isoFormat('L')` / `isoFormat('LT')`. This does not affect `diffForHumans()` or other Carbon translation features — only the `L`/`LT`-style format tokens for date and time display.

Implementation details:
- **Locale ID:** `IntlDateFormatter` receives the `LocaleContext::forIntl()` value (BCP-47 form like `en-MY`).
- **Timezone:** The formatter receives the same resolved timezone as before (`currentTimezone()` / company zone).
- **Fallback:** If `IntlDateFormatter::format()` returns `false`, falls back to Carbon `isoFormat` as before.
- **Note:** ICU uses U+202F (narrow no-break space) before AM/PM markers per CLDR rules. Tests normalize this for assertion.

### D2: Consolidate company timezone resolution into DateTimeDisplayService

`TimezoneController` previously had its own private `resolveCompanyTimezone($user)` duplicating `DateTimeDisplayService::resolveCompanyTimezone()`. The controller now delegates fully to `currentCompanyTimezone()`. The duplicate private methods (`resolveCompanyTimezone`, `label`) have been removed.

### D3: TimezoneMode enum owns its display labels

`TimezoneMode` now has `label()` and `description()` methods. Top-bar Blade and `TimezoneController` both use these instead of inline match expressions.

### D4: Distinguish "no timezone configured" from "UTC chosen"

`isCompanyTimezoneExplicit(): bool` added to `DateTimeDisplayService` contract and implementation. Uses `SettingsService::has()` (already existed) to check whether `ui.timezone.default` key exists at company scope. When absent:
- Top bar shows `Company — (not set)` with `text-status-warning` styling
- Company show page shows an info alert: "No timezone is configured for this company."
- When explicitly set (even to UTC), displays normally.

### D5: DB Tables and Log Viewer aligned with TimezoneMode

Both the DB table browser and log viewer now:
- Initialize their Alpine timezone state from the system-wide `TimezoneMode` value
- Support three modes (UTC → Company → Local) cycling on button click
- Company mode uses the resolved company timezone; Local mode uses browser-native timezone
- Variable names changed from generic `localTime` boolean to descriptive `tableTzMode`/`logTzMode` strings

### D6: Localization page — show timezone and currency context (read-only)

A third card "Regional Context" now shows company timezone (with "Not set" warning when absent), currency code, and language — all read-only, configured elsewhere.

### D7: Auto-populate sample currency from Geonames on locale confirm

On locale confirm, the `save()` method now resolves the `LicenseeLocaleBootstrapSource` and persists the currency code as `ui.locale_currency`. The render method uses the persisted currency first, then bootstrap, then config default (`USD`).

Currency follows the **licensee country** (via `LicenseeLocaleBootstrapSource`), not the locale region subtag. If no licensee address exists, `USD` config default is the correct fallback.

## Phases

### Phase 1 — Fix formatting bug and consolidate timezone

- [x] Switch `DateTimeDisplayService::format()` COMPANY-mode from Carbon `isoFormat('L')`/`isoFormat('LT')` to `IntlDateFormatter` using `LocaleContext::forIntl()` and `currentTimezone()`
- [x] Switch Localization preview page (`Index.php render()`) from Carbon `isoFormat('L')` / `isoFormat('LT')` to `IntlDateFormatter`
- [x] Update `DateTimeDisplayService` contract PHPDoc — replace references to "CLDR via Carbon isoFormat" with ICU `IntlDateFormatter`
- [x] Add `label(): string` and `description(): string` methods to `TimezoneMode` enum; update top-bar Blade and `TimezoneController` to use them
- [x] Refactor `TimezoneController::resolveTimezoneForMode()` to delegate to `DateTimeDisplayService::currentCompanyTimezone()` instead of its own private `resolveCompanyTimezone($user)`
- [x] Remove duplicate private `resolveCompanyTimezone()` and `label()` from `TimezoneController`
- [x] Add `isCompanyTimezoneExplicit(): bool` to `DateTimeDisplayService` contract + implementation, backed by `SettingsService::has()` (already existed in contract)
- [x] Add tests locking ICU output for `en-MY` date/time formatting (and `en-US` control locale) with U+202F normalization

### Phase 2 — UX improvements

- [x] Top bar: show "(not set)" with `text-status-warning` styling when `isCompanyTimezoneExplicit()` returns false
- [x] Company show page: show info alert when timezone is unset suggesting the user configure it
- [x] DB Tables browser: initialize from system `TimezoneMode`, support three modes (Company/Local/UTC) via cycling button
- [x] Log Viewer: same three-mode alignment as DB Tables browser
- [x] Localization page: add read-only "Regional Context" card with company timezone, currency code, and language

### Phase 3 — Automation and polish

- [x] On locale confirm, persist derived currency code from licensee's Geonames country (`ui.locale_currency` setting); fall back to `USD` config default when no licensee address exists
- [x] Verified licensee address country change flow — lazy re-inference via `ApplicationLocaleContext::state()` is correct by design; no event-driven re-evaluation needed since stored locale takes precedence
- [x] Audited `<x-ui.datetime>` LOCAL mode: `data-locale` prop correctly passes `LocaleContext::forIntl()` to browser-side `Intl.DateTimeFormat`, ensuring consistent locale between server and client modes
- [x] `TimezoneController::set()` response now includes `company_timezone_explicit` flag for Alpine state sync
