# Timezone Display Architecture

**Document Type:** Architecture Specification
**Status:** Proposed
**Last Updated:** 2026-03-29
**Related:** `docs/architecture/settings.md`, `docs/architecture/user-employee-company.md`, `docs/modules/geonames/cities-integration-review.md`

---

## 1. Problem Essence

BLB needs a coherent timezone display model so datetimes render consistently across tables, detail pages, and partial date/time fragments while still supporting both shared business context and user-specific viewing needs.

---

## 2. Goals

- Make the default datetime display feel natural for a business application.
- Preserve UTC as an audit-friendly reference view.
- Support browser-local viewing when explicitly desired by the user.
- Keep timezone rules consistent across the UI instead of mixing PHP formatting and browser-local ad hoc formatting.
- Allow city-derived timezone data to enrich the experience without making every render depend on Geonames coverage.

---

## 3. Non-Goals

- Changing the framework's runtime timezone away from UTC.
- Rewriting stored timestamps to non-UTC values.
- Inferring a live timezone from address data on every request.
- Treating Geonames city resolution as a hard requirement for rendering datetimes.

---

## 4. Recommended Display Model

BLB should support three timezone display modes:

- **Company**: the configured licensee or company timezone
- **Local**: the browser timezone of the current viewer
- **UTC**: the canonical reference view

### 4.1 Default Mode

The default mode should be **Company**.

Why:

- BLB is primarily a shared business application.
- Most tables and workflows are interpreted relative to the operating timezone of the licensee.
- Browser-local time is useful, but it is a personal convenience view rather than the best shared default.
- UTC remains important for diagnostics, support, and auditability, but is less intuitive as the primary UI mode.

### 4.2 Single HQ Default

The company timezone setting represents the headquarters timezone — one canonical default per licensee. Multi-branch or location-specific timezone rendering is a future concern and out of scope for the initial implementation.

### 4.3 User Override

Users should be able to switch the display mode to:

- `Company`
- `Local`
- `UTC`

The selected mode should be sticky and apply across the whole UI until changed again.

---

## 5. Source of Truth

The company timezone should be an explicit setting, not a live derivation from address geography on every render.

### 5.1 Resolution Strategy

Recommended policy:

1. derive an initial timezone suggestion from the licensee address when enough geography is available
2. store that value as a canonical company or licensee timezone setting
3. use that stored setting as the default display timezone
4. allow manual correction without requiring address changes

This keeps the UI stable even when:

- the registered address is not the operational timezone
- the company has multiple addresses
- city resolution is unavailable
- the address changes for legal or postal reasons unrelated to timezone

### 5.2 Why Not Live Address Inference

Deriving timezone directly from address data on every render creates a shallow and brittle interface:

- it ties formatting to incomplete geospatial coverage
- it makes display behavior change when address data shifts
- it obscures the actual configured business timezone

BLB should instead treat address-derived timezone as an initialization aid, not the ongoing source of truth.

---

## 6. Fallback Policy

When rendering datetimes, the resolution order should be:

1. explicit user-selected display mode
2. configured company timezone
3. UTC

For the `Local` mode:

- use the browser timezone only when the user explicitly selects it

When no city-derived timezone is available during setup:

- keep the company timezone unset until configured, or
- fall back to UTC as the safe system reference

The system should prefer "timezone unknown" or a safe fallback over an unreliable guess.

---

## 7. Rendering Policy

All user-facing datetime rendering should flow through a shared display layer.

That layer should support:

- full datetime rendering
- date-only rendering
- time-only rendering
- timezone badge or label rendering where useful

### 7.1 Local Mode Implementation

For `Company` and `UTC` modes, the PHP `DateTimeDisplayService` renders the formatted string server-side — it knows the company timezone from the stored setting.

For `Local` mode, the server cannot know the browser's IANA timezone. Instead:

- The PHP service emits a UTC ISO-8601 value in a `datetime` attribute on a Blade component (e.g., `<x-ui.datetime>`).
- A thin Alpine.js `x-effect` on that component formats the value using the browser's `Intl.DateTimeFormat`.
- The PHP service still owns the **policy** (which mode is active); only `Local` delegates the **formatting** to the browser.

This avoids cookie/header gymnastics, stays within the TALL stack, and keeps the PHP service as the single policy owner. No mixed model — every datetime goes through the same Blade component regardless of mode.

### 7.2 Surfaces Covered

The selected timezone mode should apply consistently across:

- table datetime columns
- detail pages
- tooltips
- badges
- piecemeal date and time fragments
- reusable UI components

### 7.3 Surfaces to Avoid

Avoid a mixed model where:

- some tables render in browser local time via JavaScript
- some Blade templates render in UTC
- some components silently use company timezone

That inconsistency would create a confusing user experience and make support harder.

---

## 8. Relationship to Geonames Cities

`geonames_cities.timezone` is a useful enrichment source, but not the hard dependency for the display system.

### 8.1 Appropriate Use

City timezone data is appropriate for:

- suggesting the initial company timezone
- deriving local time for an address or office when a canonical city is known
- future branch- or location-specific displays

### 8.2 Inappropriate Use

City timezone data should not be used as:

- the only way to render datetimes
- a required dependency for saving addresses
- a live lookup for every formatted timestamp

This matches the broader BLB policy that validation should enforce only true invariants, while enrichment remains best-effort.

---

## 9. Persistence Model

The timezone preference system has two distinct concerns:

### 9.1 Company Default

Persist a canonical business timezone in settings, for example through a company-scoped key such as:

- `ui.timezone.default`

or another equivalent, well-named settings key.

This value represents the shared default display timezone for the licensee.

### 9.2 User Display Preference

Persist the current viewer's preferred display mode separately, for example:

- `ui.timezone.mode = company|local|utc`

This is a display preference, not business data.

The actual key names can be finalized during implementation, but the conceptual separation should remain:

- company setting = shared default
- user setting = viewer-specific override

---

## 10. Public Interface Direction

The implementation should converge on a simple formatting interface that hides timezone resolution.

Possible shape:

```php
interface DateTimeDisplayService
{
    public function formatDateTime(\DateTimeInterface|string|null $value): string;

    public function formatDate(\DateTimeInterface|string|null $value): string;

    public function formatTime(\DateTimeInterface|string|null $value): string;

    public function currentMode(): string;

    public function currentTimezone(): string;
}
```

The exact interface can vary, but the important part is architectural:

- callers should ask for formatted output
- callers should not each resolve timezone rules independently

---

## 11. UX Recommendations

- Default to `Company` mode for most business screens.
- Expose a clear toggle for `Company`, `Local`, and `UTC`.
- Keep the toggle state sticky across pages.
- Show the active timezone label or abbreviation where ambiguity matters.
- On audit-heavy screens, make UTC easy to access even if it is not the default.

---

## 12. Implementation Sequence

Recommended order:

1. define settings keys for company default timezone and user display mode
2. introduce a shared datetime display service or helper layer
3. replace ad hoc datetime rendering in tables and reusable components
4. add the UI toggle for `Company`, `Local`, and `UTC`
5. use Geonames city data to suggest the initial company timezone during setup or company configuration

---

## 13. Summary

BLB should render datetimes in a company-default timezone by default, with explicit user-switchable `Local` and `UTC` views.

The company timezone should be a stored setting, not a live inference from address data. Geonames city timezone data should help establish and enrich that setting, but should not become a hard dependency for general datetime rendering.
