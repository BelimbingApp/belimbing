# Geonames Cities Integration Review

**Document Type:** Design Review
**Purpose:** Identify high-value ways to weave `geonames_cities` into existing BLB modules and UX flows.
**Last Updated:** 2026-03-29

## Scope

This review covers integration opportunities for the newly added `geonames_cities` dataset across:

- `App\Modules\Core\Geonames`
- `App\Modules\Core\Address`
- `App\Modules\Core\Company`
- timezone-aware UI and related display flows

The goal is to make practical, BLB-aligned use of cities data instead of leaving it as a seeded but mostly unconsumed dataset.

---

## Current State

The Geonames module now stores city records from `cities15000.zip` in `geonames_cities`, including:

- canonical city names
- alternate names
- `country_iso`
- `admin1_code`
- latitude and longitude
- `timezone`

However, runtime consumers still primarily use only:

- `geonames_countries`
- `geonames_admin1`
- `geonames_postcodes`

In particular:

- Address creation/editing derives locality mainly from postcode rows.
- Company address flows reuse the same postcode-oriented lookup behavior.
- No Geonames admin UI exists yet for cities.
- No address or company record currently links canonically to a city row.

This means the new cities data is structurally present, but not yet woven into the rest of the framework.

---

## Existing Integration Points

### Address module

The strongest current integration seam is the Address geo lookup stack:

- `app/Modules/Core/Address/Concerns/HasAddressGeoLookups.php`
- `app/Modules/Core/Address/Livewire/AbstractAddressForm.php`
- `app/Modules/Core/Address/Http/Controllers/PostcodeSearchController.php`

Today these flows support:

- country selection
- admin1 lookup
- postcode search
- locality suggestions from postcode data

This is the most natural place to introduce city-backed lookup behavior.

### Company module

Company address management already reuses the shared address form logic:

- `app/Modules/Core/Company/Livewire/Companies/Show.php`

That makes company address modals an immediate downstream beneficiary of any city integration added in the Address layer.

### Geonames module

The Geonames module currently exposes admin surfaces for:

- countries
- admin1
- postcodes

But not for cities:

- `app/Modules/Core/Geonames/Routes/web.php`
- `app/Modules/Core/Geonames/Config/menu.php`

This leaves the new dataset less observable and harder to validate operationally.

### Timezone-related surfaces

Timezone handling in the current codebase is still mostly:

- app-default timezone via `config('app.timezone')`
- browser-local display via `toLocaleString()`
- scheduler event timezone display

Cities already carry `timezone`, but nothing in the current entity flows resolves or uses it yet.

---

## Highest-Value Opportunities

### 1. Add city search to address creation and editing

This is the best first integration.

Instead of treating locality as only a free-text or postcode-derived field, the Address flow should be able to search `geonames_cities` by:

- `country_iso`
- optional `admin1_code`
- `name`
- `ascii_name`
- `alternate_names`

This would improve:

- locality normalization
- user experience when postcode is unknown
- consistency across Address and Company modules

Recommended entry point:

- extend `HasAddressGeoLookups`
- or, preferably, extract the growing lookup logic into a deeper module service such as `GeonamesAddressLookup`

### 2. Use cities as a fallback when postcode lookup is weak

The current flow is postcode-centric. That works well when postcode data is available early, but many real user flows begin with:

- country
- state/province
- city

Cities should complement postcode lookup, not replace it.

Recommended interaction model:

1. country
2. admin1
3. city
4. postcode refinement

This is especially useful when:

- the user knows the city but not the postcode
- postcode data is missing or incomplete
- multiple postcode rows share similar locality names

### 3. Add a Geonames Cities admin index

If cities are going to support real product behavior, the data must be visible and inspectable in admin.

Recommended additions:

- `Geonames\Livewire\Cities\Index`
- route under `/admin/geonames/cities`
- menu entry in the Geonames admin section

Suggested initial capabilities:

- search by city name
- filter by country
- optional filter by admin1
- sort by population
- display timezone

This aligns cities with the rest of the Geonames module and improves supportability.

### 4. Surface city-derived timezone in company and address UX

The most practical first timezone use is entity-scoped UI, not changing the application timezone.

See also: `docs/architecture/timezone-display.md`.

Once a city is selected or resolved, its `timezone` can be used for:

- company HQ local time display
- office-local timestamps
- timezone suggestions in company setup
- future scheduling features that are tied to a company or address context

This is more BLB-aligned than treating cities as a shortcut for changing `config('app.timezone')`, because the city timezone belongs to an entity, not to the whole framework instance.

#### Recommended timezone display model

The best UX is to make the licensee or company timezone the default display timezone for shared business UI, while still allowing users to switch views when needed.

Recommended display modes:

- **Company:** the configured licensee or company timezone
- **Local:** the browser timezone of the current viewer
- **UTC:** the canonical reference view for auditability and support

Recommended default behavior:

- render datetimes in **Company** timezone by default
- allow switching to **Local** or **UTC**
- keep the selected display mode sticky across the UI
- apply the same display mode to both table datetime columns and piecemeal datetime rendering

This is preferable to defaulting to browser-local time everywhere because BLB is primarily a shared business application, and the most intuitive default is often the timezone the licensee actually operates in.

#### Source of truth for the default timezone

The company timezone should be an explicit setting, not a live inference from address data on every render.

Recommended policy:

1. resolve an initial timezone suggestion from the licensee address when enough geography is available
2. store that as a canonical company or licensee timezone setting
3. use that stored setting as the default display timezone
4. allow later manual correction without requiring address changes

This avoids a shallow design where every datetime render depends on fuzzy geographic resolution. It also handles cases where:

- the registered address is not the operational timezone
- the company has multiple addresses
- city resolution is unavailable because the locality is not in `cities15000`

#### Rendering policy

To keep the UX coherent, datetime formatting should flow through a shared rendering layer rather than a mix of ad hoc PHP and browser-local JavaScript formatting.

The display layer should support:

- full datetime formatting
- date-only formatting
- time-only formatting
- timezone badges or labels where useful

The selected timezone mode should apply consistently across:

- tables
- detail pages
- tooltips
- badges
- partial date and time fragments

#### Fallback behavior

When city-based timezone resolution is unavailable:

- first use an explicitly configured company timezone, if present
- otherwise fall back to UTC as the safe default reference view
- only use browser-local time when the user explicitly selects **Local**

This keeps timezone display reliable without pretending that every address can always be mapped to a canonical city timezone.

### 5. Teach `AddressCreator` to resolve city names

`app/Modules/Core/Address/Services/AddressCreator.php` is a good place to use city data for imported or AI-parsed addresses.

If raw input contains a recognizable city name, the service could:

- normalize locality text
- infer `country_iso`
- suggest or infer `admin1Code`
- eventually derive timezone once a canonical city is resolved

This would make the dataset useful beyond manual form interaction.

---

## BLB-Aligned Structural Direction

The biggest architectural question is not whether cities should be used, but where the integration logic should live.

### Better than adding more trait code

`HasAddressGeoLookups` already mixes:

- country options
- admin1 options
- postcode search
- locality resolution
- postcode import orchestration

Adding cities there is functionally possible, but it risks turning the trait into a shallow collection of loosely related helpers.

### Preferred direction

Introduce a deeper interface that owns geospatial lookup behavior for addresses, for example:

- `App\Modules\Core\Address\Services\GeonamesAddressLookup`

Possible responsibilities:

- `searchCountries()`
- `searchAdmin1()`
- `searchPostcodes()`
- `searchCities()`
- `resolveLocality()`
- `resolveTimezoneForAddress()`

This is more in line with BLB's principles:

- deep module
- simple public interface
- hidden implementation complexity

Livewire components should consume the service rather than directly accumulating more lookup rules.

---

## Coverage Gaps and Fallback Strategy

The `cities15000` dataset is intentionally incomplete. In practice, this is acceptable as long as integrations treat city resolution as enrichment rather than as a hard validation dependency.

Recommended policy:

- do not block address or company workflows when no city match exists
- preserve `locality` as free text when the city cannot be resolved canonically
- use postcode-derived locality as the first fallback when postcode data is available
- treat timezone derivation as optional, not required
- prefer no derived timezone over an aggressive but unreliable guess

This means modules should support two valid states:

- **resolved locality:** a user-entered or imported locality that matches a canonical `geonames_cities` row
- **unresolved locality:** a still-valid locality string with no matching city row, but with whatever other structured geography is known, such as `country_iso`, `admin1Code`, and `postcode`

This is the BLB-aligned boundary:

- validation should enforce only true invariants
- enrichment should be best-effort

For example:

- `country_iso` can remain a hard invariant when present
- `admin1Code` can remain a hard invariant when present
- city resolution should remain optional because the upstream city dataset is useful but not exhaustive

Implications for integrating modules:

- **Address:** allow manual locality entry when no city match is found
- **Company:** allow HQ or office addresses without canonical city resolution
- **Timezone-aware UI:** show entity-local timezone only when city resolution succeeds; otherwise fall back to an explicitly configured timezone or show no derived timezone
- **AI/import flows:** preserve raw locality when confidence is low instead of forcing a potentially wrong city match

---

## Longer-Term Opportunity: Canonical City on Address

The strongest long-term move is to let addresses optionally reference a canonical city row.

Possible shape:

- add `city_geoname_id` to `addresses`
- reference `geonames_cities.geoname_id`
- keep `locality` as denormalized display text for flexibility

Benefits:

- reliable timezone derivation
- cleaner reporting and filtering
- less locality spelling drift
- easier future map or geo-aware features

Why this should not be the first step:

- it is a schema decision
- it affects forms, validation, and existing data shape
- the immediate product value can be captured earlier through city lookup and timezone display

Recommended sequence:

1. add city admin UI
2. add city search to address and company flows
3. expose city-derived timezone in entity-scoped UI
4. evaluate whether `Address` should gain a canonical city FK

---

## Recommended First Implementation Slice

The best first slice is:

1. Add query scopes and basic relationships to `App\Modules\Core\Geonames\Models\City`.
2. Add city search to the address geo lookup layer.
3. Reuse that in company address modals.
4. Add a simple Geonames Cities admin browser.
5. Show city-derived timezone where an address or company has enough context.

This delivers visible value quickly while preserving module cohesion.

---

## Review Summary

`geonames_cities` is worth keeping only if it becomes part of the operational lookup surface of BLB.

The most valuable and BLB-aligned uses are:

- city-aware address entry
- better locality normalization
- company and address timezone display
- admin visibility for cities

The strongest implementation path is to treat cities as part of a deeper address/geography lookup interface, rather than as an isolated extra table or a purely Geonames-only concern.
