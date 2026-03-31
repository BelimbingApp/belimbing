# TODO: Locale Support Rollout

**Status:** Implemented
**Related:** `docs/architecture/settings.md`, `docs/architecture/timezone-display.md`

---

## 1. Problem Essence

BLB needs a single locale model that consistently drives translations and regional formatting across the UI instead of letting date, number, and currency rendering fall back to ad hoc defaults.

---

## 2. Why This Matters

Timezone alone does not answer how values should look to users.

Locale affects at least:

- date ordering and separators
- 12-hour vs 24-hour time
- decimal and thousands separators
- currency symbol placement and spacing
- pluralization and translation choice
- week-start conventions and other regional defaults

Without a shared locale layer, BLB will drift into mixed behavior:

- some values use server defaults
- some values use browser defaults
- some values use hard-coded formats
- translated strings and formatted values can disagree about region

---

## 3. Design Direction

BLB should treat **language**, **locale**, and **timezone** as related but distinct concerns:

- **Language** answers which translation strings to show
- **Locale** answers how regional values are rendered
- **Timezone** answers which wall-clock time to show

For the first rollout, BLB should use one explicit application-level locale setting and derive language from locale unless a real product need emerges to separate them later.

Examples:

- `en-MY` = English text with Malaysian regional conventions
- `ms-MY` = Malay text with Malaysian regional conventions
- `en-US` = English text with US regional conventions

This keeps the public model simple while avoiding the false assumption that timezone implies date format.

---

## 4. Public Interface First

Introduce a dedicated locale contract that hides resolution and normalization details.

Possible shape:

```php
interface LocaleContext
{
    public function currentLocale(): string;

    public function currentLanguage(): string;

    public function fallbackLocale(): string;

    public function forCarbon(): string;

    public function forIntl(): string;
}
```

The exact method names can change, but the architectural rule should remain:

- callers ask one service for locale context
- callers do not infer locale from timezone
- callers do not each invent their own fallback chain

---

## 5. Resolution Policy

Recommended locale resolution order:

1. explicit confirmed `ui.locale`
2. explicit unconfirmed `ui.locale` derived from the licensee address country
3. app default locale from config

This is intentionally a narrow model: one explicit source of truth, one persisted inferred default when setup can determine a country-based suggestion, and one deterministic bootstrap fallback.

### 5.1 Explicit Source Of Truth

Browser locale is **not** part of locale resolution.

Why:

- two users looking at the same business record should not see different defaults just because their browsers differ
- screenshots, support reproduction, and QA become harder when rendering depends on local browser state
- BLB should prefer persisted application policy over client-side inference

Browser locale may still be used outside the resolution model as a non-binding setup hint, but the persisted application setting remains the authoritative source of truth.

### 5.2 Licensee Address Bootstrap

When `ui.locale` has not been explicitly set, BLB should try to derive an **initial suggested locale** from the licensee company's address country.

Important constraints:

- do not infer locale from the address on every request
- do not derive locale from timezone
- do not generate `en-{iso2}` mechanically
- do persist the inferred locale once it is chosen
- do mark that value as **unconfirmed** until an administrator reviews it

The licensee address is a good bootstrap signal, but not a reliable long-term source of truth. Address data should initialize the locale, not continuously control it.

### 5.3 Country Mapping Policy

BLB should use explicit country overrides and country language data rather than a formula.

Examples:

- `MY -> en-MY`
- `SG -> en-SG`
- `ID -> id-ID`
- `TH -> th-TH`
- `JP -> ja-JP`
- `FR -> fr-FR`
- `DE -> de-DE`

The exact mapping should follow BLB's supported locale list. In implementation, BLB first checks explicit country overrides, then tries the licensee country language list from Geonames before falling back to `config('app.locale')` and surfacing an admin notice.

---

## 6. Settings Model

Use the existing settings system rather than introducing a new table or special-case storage.

Recommended keys:

| Key | Scope | Type | Description |
|-----|-------|------|-------------|
| `ui.locale` | Global | string | Effective locale tag such as `en-MY`, `ms-MY`, `en-US` |
| `ui.locale_source` | Global | string | `manual`, `licensee_address`, or `config_default` |
| `ui.locale_confirmed_at` | Global | string/null | Timestamp recording when an administrator explicitly confirmed the locale |
| `ui.locale_inferred_country` | Global | string/null | Country ISO code captured when BLB inferred the locale from the licensee address |
| `ui.language` | none in phase 1 | string | Optional future override if BLB later separates language from locale |

Phase 1 should manage locale at application scope.

There is no scoped locale cascade in the initial design. The runtime fallback is `config('app.locale')`, but BLB should prefer persisting a country-derived unconfirmed locale first when the licensee address provides enough signal.

Language behavior in phase 1:

- derive translation language from the locale's language subtag
- `en-MY` resolves translation language `en`
- `ms-MY` resolves translation language `ms`

This keeps the surface simple while preserving room to split language and locale later if the product needs it.

---

## 7. Module Responsibilities

### 7.1 Locale Context

Responsibility:

- resolve the effective locale for the current request
- normalize locale tags for PHP and JS consumers
- expose current locale and derived language

Contract:

- input: persisted locale state, licensee address bootstrap signal, and config fallback
- output: one resolved locale context object
- invariant: one request has one authoritative effective locale

### 7.2 Request Bootstrap

Responsibility:

- apply the resolved locale early in the request lifecycle
- keep Laravel translation locale aligned with the locale context

Contract:

- input: locale context
- output: framework-level locale state set for the request
- invariant: translations, validation messages, and formatting services observe the same effective locale

### 7.3 Formatting Services

Responsibility:

- render user-facing values consistently using locale context

Initial service set:

- `DateTimeDisplayService`
- `NumberDisplayService`
- `CurrencyDisplayService`

Contract:

- input: raw values plus locale context, and timezone context where applicable
- output: display strings for UI surfaces
- invariant: formatting rules are centralized, not duplicated across Blade and JavaScript fragments

---

## 8. Request Flow

Expected call pattern:

1. request enters middleware / bootstrap layer
2. locale context resolves `ui.locale` from persisted application settings
3. app locale is set for translations and validation
4. downstream services ask locale context for formatting locale
5. Blade components render through shared display services instead of inline formatting

For browser-side formatting, the server should still provide the explicit locale value rather than relying on `Intl.DateTimeFormat(undefined, ...)`.

---

## 9. Interaction With Existing Timezone Model

The current timezone work remains valid and should not be folded into locale.

Recommended policy:

- timezone mode chooses **which clock** to display
- locale chooses **how the value is rendered**

Examples:

- `timezone = Asia/Kuala_Lumpur`, `locale = en-MY` → `31/03/2026 20:15:00`
- `timezone = Asia/Kuala_Lumpur`, `locale = en-US` → `03/31/2026 8:15:00 PM`
- `timezone = UTC`, `locale = en-MY` → raw/technical mode may still choose fixed canonical formatting if BLB keeps that policy

The main architectural rule is:

- timezone resolution and locale resolution stay separate
- display services combine them only at the formatting boundary

If BLB uses a country-derived locale, that derived locale should still be persisted and treated as locale state. The address itself does not remain part of steady-state runtime resolution.

---

## 10. Rollout Phases

### Phase 1 — Locale Foundation

- [x] create `LocaleContext` contract and implementation under `app/Base`
- [x] resolve locale from the global `ui.locale` setting
- [x] infer an initial locale from the licensee address country using explicit overrides plus Geonames country language candidates when `ui.locale` is absent
- [x] persist inferred locales as unconfirmed
- [x] fall back to `config('app.locale')` when no supported country mapping is available
- [x] normalize locale for Laravel, Carbon, `Intl`, and number formatting
- [x] define the supported locale list and fallback locale policy
- [x] add focused unit tests for resolution and fallback behavior
- [x] add `ui.locale_inferred_country` to preserve the bootstrap signal without re-reading the address on every request

### Phase 2 — Request Bootstrap

- [x] add middleware or equivalent bootstrap integration to apply the effective locale early
- [x] set Laravel translation locale from the resolved language
- [x] ensure validation and shared framework messages use the same language
- [x] verify guest and authenticated behavior separately

### Phase 3 — DateTime Integration

- [x] refactor `DateTimeDisplayService` to depend on `LocaleContext`
- [x] remove direct use of `app()->getLocale()` inside datetime formatting policy
- [x] pass explicit locale to browser-side formatting for local timezone mode
- [x] keep UTC/Stored mode as the canonical raw surface

### Phase 4 — Number and Currency Formatting

- [x] introduce `NumberDisplayService`
- [x] introduce `CurrencyDisplayService`
- [x] replace ad hoc `number_format()` and currency string assembly where present
- [x] keep technical/admin surfaces explicit while making ordinary numeric display locale-aware

### Phase 5 — Settings and UX

- [x] add application-level locale setting UI
- [x] place the setting under `Administration -> System -> Localization`
- [x] use `Localization` as the menu label and `Language & Region` as the page heading
- [x] show a persistent status-bar warning when the locale is inferred but not yet confirmed
- [x] do not use browser locale in runtime resolution; keep it out of the authoritative model

### Phase 6 — Translation Expansion

- [x] audit existing `__()` coverage gaps in touched localization surfaces
- [x] keep the existing source-string translation pattern; no new language files were required for this slice
- [x] translate high-value shared UI first within the new localization feature
- [x] keep formatting services independent from translation coverage so locale rollout is still useful before full i18n completion

---

## 11. Implementation Policies

### 11.1 Allowed Locale Set

Do not accept arbitrary locale tags without policy.

BLB should maintain an explicit allow-list for supported locales, for example:

- `en`
- `en-MY`
- `ms`
- `ms-MY`
- `en-US`

This keeps translations, testing, and support bounded. The exact list should follow product priorities, not theoretical completeness.

### 11.2 Country-To-Locale Bootstrap

BLB should keep explicit country overrides and use Geonames country language data as the secondary inference source.

Rules:

- explicit country overrides are not generated mechanically
- every chosen locale must exist in the supported locale list
- Geonames language candidates are only used when no explicit country override exists
- the bootstrap signal is used only to initialize an unconfirmed locale
- changing the licensee address later must not silently change an already persisted locale

This prevents dangerous assumptions like `en-{iso2}` while still allowing BLB to start from a sensible regional default for countries beyond a small handcrafted list.

### 11.3 Canonical Raw Formatting

Even after locale support exists, some surfaces should remain canonical:

- logs
- audit trails
- database inspection tools
- machine-facing exports
- troubleshooting UI where exact stored representation matters

Those surfaces may intentionally use:

- fixed datetime formatting
- explicit UTC labels
- machine-stable values over localized friendliness

Locale support should not erase this distinction.

### 11.4 One Formatting Boundary

Formatting should happen through shared services and reusable UI components.

Avoid:

- `Intl.DateTimeFormat(undefined, ...)`
- inline `number_format()` scattered across Blade
- per-page locale inference
- hard-coded assumptions like "Asia means dd/mm"

---

## 12. Complexity Hotspots

The main design risks are:

- **Language vs locale separation**: start simple, but avoid a design that makes separation impossible later
- **Library normalization**: Laravel, Carbon, and `Intl` may prefer slightly different locale tag forms
- **Partial translation coverage**: formatting may be locale-aware before all UI strings are translated
- **Mixed raw vs localized surfaces**: admin/debug screens must deliberately opt into canonical formatting
- **Testing matrix growth**: locale x timezone combinations can multiply quickly if not tested selectively

These are manageable as long as BLB centralizes policy instead of letting each component decide independently.

---

## 13. Recommended First Slice

The best first implementation slice is:

1. add global `ui.locale`
2. implement `LocaleContext`
3. add country-to-locale bootstrap from the licensee address
4. persist inferred locale as unconfirmed
5. apply locale in request bootstrap
6. refactor `DateTimeDisplayService` to use it
7. pass explicit locale to browser-side datetime formatting

Why this slice first:

- it solves the immediate datetime-format ambiguity correctly
- it establishes the architectural foundation for numbers, currencies, and translations
- it avoids prematurely building a large translation-management project

---

## 14. Summary

BLB should introduce locale as a dedicated, settings-backed request context with a single resolver and shared formatting services.

That gives the framework one coherent answer for regional formatting, keeps timezone separate from locale, and creates a clean path toward broader i18n without forcing the whole app to become fully translated in one step. When locale is missing, BLB now bootstraps from explicit country overrides plus Geonames language hints, persists the result as unconfirmed, and requires explicit admin confirmation rather than relying on hidden runtime guesswork.

---

## 15. Build Status

- [x] Implemented `app/Base/Locale/` with `LocaleContext`, locale catalog, middleware, and display services
- [x] Added a Core Company adapter that supplies licensee-country bootstrap data to the Base locale module through a contract
- [x] Added `Administration -> System -> Localization` with confirm/save flow and live formatting preview
- [x] Added a persistent status-bar warning when locale is inferred or otherwise unconfirmed
- [x] Wired locale into datetime rendering, browser-local datetime formatting, and existing number/currency display hotspots
- [x] Added focused unit and feature coverage for locale resolution, admin confirmation flow, and status-bar behavior

---

## 16. After-Coding Alignment Review

### 16.1 Alignment

- **Deep module, simple interface**: locale policy now lives behind `LocaleContext`, `NumberDisplayService`, and `CurrencyDisplayService` instead of leaking formatting choices into many callers.
- **No hidden runtime guesswork**: licensee address is used only to bootstrap and persist an unconfirmed locale once. After that, runtime reads locale state, not address data.
- **Layer boundaries preserved**: Base locale code does not depend directly on Core company/address models. Core Company supplies bootstrap data through a dedicated contract binding.
- **Raw vs localized surfaces kept distinct**: UTC/Stored datetime mode remains canonical and explicit, while regular user-facing date and number displays follow the resolved locale.

### 16.2 Conscious Simplifications

- Locale remains **application-scoped** in this slice. There is still no employee- or company-scoped locale cascade.
- Translation language is derived from locale and applied at the request layer, but the repository still primarily uses source-string translations rather than a large dedicated language-pack rollout.
- Country inference currently uses a combination of explicit overrides and Geonames language hints. It is intentionally bootstrap-only and can be expanded later without changing the public locale interfaces.
