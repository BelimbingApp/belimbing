# HTMX Interaction Contract

**Document Type:** Architecture Policy  
**Purpose:** Define BLB's HTMX conventions to prevent per-page fragmentation  
**Scope:** All HTMX-driven UI across BLB modules  
**Last Updated:** 2026-03-02  

---

## 1. URL and Fragment Naming Conventions

### Route Naming
- Full-page routes: `{module}.{resource}.{action}` — e.g., `admin.users.index`, `admin.users.show`
- HTMX fragment routes: `{module}.{resource}.{action}.fragment` — e.g., `admin.users.index.fragment`
- Geo lookup routes: `admin.{resource}.lookup.{type}` — e.g., `admin.addresses.lookup.admin1`

### Fragment View Naming
- Full page views: `resources/views/{module}/{resource}/{action}.blade.php`
- Fragment partials: `resources/views/{module}/{resource}/partials/{name}.blade.php`
- Shared partials: `resources/views/partials/htmx/{name}.blade.php`

### HTML Element IDs for HTMX Targets
- List containers: `{resource}-list` — e.g., `users-list`, `companies-list`
- Table bodies: `{resource}-table-body`
- Form containers: `{resource}-form`
- Validation error containers: `{field}-error` (per field) or `form-errors` (global)
- Flash message container: `flash-messages`

---

## 2. Error and Validation Response Lifecycle

### Validation Errors (422)
- Controllers use `$request->validate([...])` which throws `ValidationException`
- On validation failure, re-render the **form partial** with `$errors` bag — same fragment the form lives in
- HTTP status: `422 Unprocessable Entity` (HTMX swaps on non-2xx when `hx-swap-oob` or via `htmx:responseError`)
- Response target: same `#form-container` element; swap strategy `innerHTML`
- Field-level errors rendered inline via `$errors->first('field')` passed to `x-ui.input :error`

### Flash Messages
- Success/error stored in `Session::flash('success', ...)` / `Session::flash('error', ...)`
- Flash container id: `flash-messages` in layout
- On full-page redirect: standard Laravel session flash rendered by layout
- On HTMX fragment response: emit `HX-Trigger: {"blb:flash": {"type": "success", "message": "..."}}` header; Alpine listens and renders in-page

### POST → Redirect (PRG Pattern)
- Successful mutations (create/update/delete) always follow PRG
- Controller returns `redirect()->route('...')` — HTMX follows via `HX-Redirect` header
- Never return HTML on a successful POST that should navigate; always redirect

---

## 3. Event and Header Conventions

### Standard HX-Trigger Events
| Event Name | When Fired | Payload |
|---|---|---|
| `blb:flash` | After successful mutation, before redirect | `{type: "success"|"error", message: "..."}` |
| `blb:row-deleted` | After a row delete fragment | `{id: number}` |
| `blb:modal-close` | To close a modal after success | `{}` |
| `blb:list-refresh` | To trigger a list reload | `{target: "#element-id"}` |

### Standard Response Headers
- Redirect after action: `HX-Redirect: /url`
- Push navigation URL: `HX-Push-Url: /url`
- Trigger events: `HX-Trigger: {"blb:flash": {...}}`
- No-content delete: HTTP 204 + optional `HX-Trigger`

---

## 4. Concurrency Policy

### hx-sync Usage by Interaction Type
| Pattern | hx-sync strategy |
|---|---|
| Search input | `hx-sync="this:replace"` — cancel previous, run new |
| Form submit button | `hx-sync="closest form:queue"` — queue, prevent double-submit |
| Inline field save | `hx-sync="this:drop"` — drop if in flight |
| Delete confirmation | `hx-sync="this:abort"` — abort if in flight |
| Pagination links | `hx-sync="closest [hx-get]:replace"` |

### Loading States
- Use `htmx-indicator` CSS class on spinner elements inside buttons/search inputs
- For table rows being deleted: add `aria-busy="true"` and opacity-50 via Alpine on click
- Use `hx-disabled-elt="this"` on submit buttons to prevent double-submit

---

## 5. Navigation, History, Focus and Scroll Standards

### Browser History
- Full-page HTMX navigations: `hx-push-url="true"` — always push URL
- Fragment swaps (search results, pagination): `hx-push-url="/url?page=N&search=..."` (explicit URL with current query state)
- Modal opens/closes: no history push

### Focus Management
- After form submission error: focus first invalid field (Alpine `$nextTick` + `focus()`)
- After modal open: focus first interactive element in modal
- After delete: focus previous sibling row or list container

### Scroll Behavior
- Default HTMX scroll: `hx-scroll="false"` on fragment swaps (don't scroll to top on search/pagination)
- Exception: new page navigations should scroll to top (default behavior)

---

## 6. Blade Partial Composition Rules

### Fragment Structure
Every HTMX-targetable fragment is a standalone Blade partial that:
1. Contains no `<html>`, `<head>`, or `<body>` tags
2. Has exactly one root element (or `<x-slot>` wrapper)
3. Receives data exclusively via `@include($view, $data)` or controller `view('partial', $data)`
4. Is stored in `partials/` subdirectory alongside its parent view

### List Page Pattern
```
resources/views/{module}/{resource}/
├── index.blade.php          # Full page (extends layout, includes fragment)
└── partials/
    └── table.blade.php      # HTMX-swappable table fragment (id="{resource}-list")
```

### Form Page Pattern
```
resources/views/{module}/{resource}/
├── create.blade.php         # Full page
├── edit.blade.php           # Full page
└── partials/
    └── form.blade.php       # Shared form partial (used by create + edit)
```

### Geo Form Pattern (replaces AbstractAddressForm)
```
resources/views/partials/address/
├── geo-form.blade.php        # Full address geo section
├── admin1-options.blade.php  # <option> fragment for admin1 select
└── locality-options.blade.php # <option> fragment for locality select
```

---

## 7. Test Helpers and Assertion Style

### HTMX Request Simulation
Use Laravel's `actingAs` + standard HTTP test methods. Simulate HTMX by adding the header:

```php
$response = $this->actingAs($user)
    ->withHeaders(['HX-Request' => 'true', 'HX-Target' => 'users-list'])
    ->get(route('admin.users.index.fragment', ['search' => 'alice']));
```

### Standard Assertions for HTMX Responses
```php
// Fragment response assertions
$response->assertStatus(200);
$response->assertSee('Alice');
$response->assertDontSee('Bob');

// Redirect assertion (PRG)
$response->assertRedirect(route('admin.users.index'));

// HTMX header assertions
$response->assertHeader('HX-Redirect', route('admin.users.index'));
$response->assertHeader('HX-Trigger');

// No-content assertion (delete)
$response->assertNoContent();
```

### Test Naming Convention
- Feature tests: `tests/Feature/{Module}/{Resource}/` — one file per resource
- Fragment tests: `.../{Resource}/FragmentTest.php`
- Form submit tests: `.../{Resource}/FormTest.php`

---

## Anti-Patterns to Avoid

| Anti-Pattern | Why | Use Instead |
|---|---|---|
| Per-page `hx-*` conventions that differ from this doc | Fragmentation, BLB framework inconsistency | Always follow this contract |
| Returning full HTML pages from HTMX fragment endpoints | Bloat; HTMX swaps the whole `<body>` | Return minimal partial |
| Using `hx-boost` globally | Unpredictable behavior with forms | Use explicit `hx-*` attributes |
| JavaScript in Blade for HTMX config | Mixes concerns | Config in `app.js` only |
| Inline CSS or `<style>` tags in partials | Violates `resources/views/AGENTS.md` | Tailwind classes only |
