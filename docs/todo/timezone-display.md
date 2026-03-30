# TODO: Timezone Display Implementation

**Spec:** `docs/architecture/timezone-display.md`
**Status:** Complete

---

## Implementation Checklist

### Phase 1 — Service Layer
- [x] `TimezoneMode` enum (`company`, `local`, `utc`)
- [x] `DateTimeDisplayService` contract (formatDateTime, formatDate, formatTime, currentMode, currentTimezone)
- [x] `DateTimeDisplayService` implementation using SettingsService cascade
- [x] ServiceProvider binding (auto-discovered via `app/Base/DateTime/ServiceProvider.php`)
- [x] Unit tests for mode resolution and formatting

### Phase 2 — Blade Component
- [x] `<x-ui.datetime>` component — unified datetime rendering
  - Server-side formatting for Company and UTC modes
  - Alpine.js `x-effect` for Local mode (browser `Intl.DateTimeFormat`)
  - Props: `value`, `format` (datetime/date/time)
  - Emits `datetime` attribute with UTC ISO-8601 for accessibility

### Phase 3 — Migrate Existing Views
- [x] Replace ad hoc `->format('Y-m-d H:i')` calls with `<x-ui.datetime>`
- [x] Remove the inline UTC/Local Alpine toggle from database-queries and database-tables
- [x] Audit all Blade views for direct Carbon formatting

### Phase 4 — UI Toggle
- [x] Add timezone mode toggle to top bar (cycles Company → Local → UTC)
- [x] Persist user preference via SettingsService (employee/company scope, `ui.timezone.mode`)
- [x] Show active timezone label in top bar
- [x] Feature test for cycle endpoint

### Phase 5 — Geonames Integration
- [x] Suggest initial company timezone from Geonames city data during setup
- [x] Company settings page: timezone selector

---

## Settings Keys

| Key | Scope | Type | Description |
|-----|-------|------|-------------|
| `ui.timezone.default` | Company | string (IANA) | HQ timezone (e.g., `Asia/Kuala_Lumpur`) |
| `ui.timezone.mode` | Employee | string | Display mode: `company`, `local`, `utc` |
