# Livewire Volt to HTMX Trade-offs

**Document Type:** Architecture Decision Analysis
**Purpose:** Evaluate replacing Livewire Volt with HTMX in BLB
**Scope:** UI interaction layer for server-driven pages and forms
**Last Updated:** 2026-03-02

---

## Executive Verdict

Given a forced binary choice (no hybrid), BLB should migrate to **HTMX-only**.

Reason: BLB is a framework, not just an app. Owning interaction contracts directly (fragment boundaries, request protocol, validation lifecycle, concurrency policy, navigation behavior, and testing conventions) aligns with BLB’s architectural sovereignty goals better than inheriting Livewire runtime opinions.

### Execution Model Recommendation

For AI-assisted migration implementation, use **GPT-5.3-Codex** as the primary model.

Why this model for BLB’s migration profile:

- Strong multi-file refactor performance for converting Livewire/Volt flows into controller + Blade partial HTMX patterns.
- Better consistency enforcement across module boundaries, which is critical for BLB’s framework-level interaction contracts.
- Reliable at generating and iterating on migration scaffolding (routes, endpoints, fragments, conventions, and tests) in controlled waves.

In short: architecture destination is **HTMX-only**, and **GPT-5.3-Codex** is the best-suited implementation model to execute that migration safely and consistently.

---

## BLB Baseline Context

- BLB currently uses Livewire for server-driven pages and forms (about 55 Livewire/Volt-related files across `resources/views/livewire` and `app`).
- The current stack is intentionally opinionated (Laravel + Livewire Volt + Tailwind + Alpine), matching BLB’s deep-module philosophy.
- A migration decision is therefore architectural, not cosmetic: it changes state management, validation flow, events, testing style, and team ergonomics.

---

## Gains from HTMX

| Gain | Why it can be better |
|------|-----------------------|
| Simpler HTML-first mental model | `hx-*` attributes are straightforward for partial swaps and simple form actions. |
| Lower framework lock-in | HTMX is transport + interaction behavior, not a full component runtime. |
| Better progressive enhancement posture | Baseline HTML works first, JS augments behavior. |
| Leaner runtime on client | No Livewire hydration/diff protocol; browser does regular HTTP + HTML swaps. |
| Flexible server implementation | Any endpoint that returns HTML can participate; not tied to Livewire lifecycle. |

---

## Losses vs Livewire Volt, and Recovery Work in HTMX

| Loss if leaving Volt | What Volt gives today | HTMX work required to recover | Effort |
|----------------------|------------------------|-------------------------------|--------|
| Stateful component model | Typed public props + lifecycle + encapsulated actions in one component | Build explicit state boundaries per page: controller/view-model classes, request DTOs, state persistence rules | Large |
| Built-in form binding and validation cycle | `wire:model`, action methods, validation, error bag integration | Standardize form-post endpoints, validation response fragments, consistent error rendering conventions | Medium |
| Unified eventing inside component runtime | Component method calls, loading states, targeted updates | Define project event conventions (`HX-Trigger`, custom headers, Alpine bridges), plus helper utilities | Medium |
| Built-in pagination/sorting/filter interaction ergonomics | Traits + component refresh loop | Rebuild list interaction primitives: query-string contracts, reusable partial endpoints, debounced search rules | Medium |
| Loading/dirty/offline UX primitives | `wire:loading`, target-specific loading, optimistic UI patterns | Create reusable Blade/Alpine helper components and CSS states for pending/failed requests | Medium |
| Opinionated testing surface for interactive components | Livewire test helpers simulate component actions directly | Shift to endpoint/feature tests for fragment responses; create custom HTMX assertion helpers for headers/fragments | Medium |
| Developer consistency across modules | Single pattern for CRUD/list/detail interactions | Write and enforce a BLB HTMX UI contract doc + stubs + lint rules to prevent drift | Large |
| Existing code reuse | Current Volt components already working | Rewrite component-by-component into controller + partial template pattern | Very Large |

---

## Practical Migration Cost Profile

For BLB specifically, most cost is not in adding HTMX. It is in replacing what Livewire already standardizes:

- Component lifecycle conventions
- Validation/error/flash interaction loop
- Reusable list/filter/sort/pagination interaction patterns
- Testing conventions for dynamic interactions
- Team-wide consistency guarantees

Without a replacement architecture, HTMX adoption tends to fragment into per-page patterns, which conflicts with BLB’s deep-module, simple-interface goals. Therefore the migration should begin with framework policy design, not page rewrites.

---

## Required Foundation Before Migration

Before broad migration, BLB should define and publish one internal “HTMX Interaction Contract” covering:

1. URL and fragment naming conventions.
2. Error and validation response lifecycle (field, form, flash).
3. Event/header conventions (`HX-Trigger`, redirects, refresh chaining).
4. Concurrency policy (`hx-sync` usage patterns by interaction type).
5. Navigation/history/focus/scroll standards.
6. Shared Blade partial composition rules.
7. Test helpers and assertion style for HTMX requests/responses.

This is framework work and should be treated as a first-class deliverable.

---

## Final Verdict

- **If hybrid is allowed:** hybrid remains lower-risk for execution.
- **If binary choice is required (Livewire-only vs HTMX-only):** choose **HTMX-only** as BLB’s long-term architecture.
- **Execution condition:** fund the foundation phase first (the 7 policies above), then migrate modules in controlled waves.

In short: HTMX-only is the stronger framework-level destination for BLB, but only when migration is led by explicit contracts and enforcement, not ad-hoc rewrites.
