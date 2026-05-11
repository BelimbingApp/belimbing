# PDF Rendering

**Document Type:** Architecture Specification
**Purpose:** Define the renderer surface, template convention, auth model, and artifact contract for PDF generation in BLB
**Related:** `docs/plans/people/04_pdf-generation-strategy.md`, `docs/guides/pdf-rendering.md`

---

## Overview

BLB renders PDFs by driving headless Chromium through the same Playwright runner that powers the AI browser tool. There is **one** rendering stack across modules: Payroll, future Quality/Operation/Commerce modules, and licensee extensions all consume the same `PdfRenderer` service. There is no second engine (mPDF, dompdf, Browsershot, Gotenberg) — those are rejected or deferred per the plan.

The renderer lives at `app/Base/Pdf/`. It is auto-discovered by `App\Base\Foundation\Providers\ProviderRegistry::discoverBaseProviders` and its routes are auto-loaded by `App\Base\Routing\RouteDiscoveryService` under the `web` middleware group.

---

## Renderer surface

`App\Base\Pdf\Services\PdfRenderer` exposes two rendering paths. Both return a `PdfArtifact` value object.

### `renderView(string $view, array $data, ?Authenticatable $actor, ...)`

Use when the Blade view depends on `auth()->user()`, policies, request context, or any side effect that needs to execute inside a BLB request.

Flow: mint a single-use token bound to `(view, data, user_id)` → store claims in cache → mint a short-lived signed URL pointing at `/pdf/render/{token}` → Chromium fetches the URL → `signed` middleware validates the URL → `VerifyRenderToken` middleware atomically consumes the token and impersonates the actor via `Auth::onceUsingId` → `SignedRenderController` renders the view → Chromium prints the page to PDF.

A token is single-use. A replay of the same URL returns 404 because `SignedRenderTokenStore::consume` is an atomic get+delete on the cache. An unsigned URL returns 403 via Laravel's built-in `signed` middleware.

### `renderInline(string $view, array $data, ...)`

Use when the Blade view is purely a template applied to data — no auth, no policies, no request-context side effects.

Flow: PHP renders the Blade view to HTML in-process → the HTML is passed directly to Chromium via `page.setContent` → Chromium prints to PDF. There is no signed URL, no controller dispatch, and no token. This is cheaper than `renderView` because it skips a round-trip through the BLB HTTP stack, but it cannot satisfy auth-dependent views.

### Picking between them

| Need | Use |
|---|---|
| The view calls `auth()->user()`, uses policies, or hits middleware-resolved state | `renderView` |
| The view is `{{ $data->whatever }}` applied to data the caller already loaded | `renderInline` |
| The view embeds a Livewire component or full Blade layout that does its own auth | `renderView` |
| Generating a static report from a pre-computed dataset (e.g. a finalized payroll run) | `renderInline` |

---

## Template convention

PDF templates live under `resources/core/views/pdf/<module>/<template>.blade.php`. Examples:

```
resources/core/views/pdf/
├── payroll/
│   ├── payslip.blade.php
│   ├── payroll-summary.blade.php
│   ├── ea-form.blade.php
│   └── pcb2.blade.php
├── quality/
│   └── ncr-report.blade.php
└── commerce/
    └── invoice.blade.php
```

Resolved by Blade as `pdf.payroll.payslip`, `pdf.quality.ncr-report`, etc.

Licensees override templates by placing identically-named files under `resources/extensions/<licensee>/views/pdf/<module>/<template>.blade.php` — the licensee path is registered first in `config/view.php`, so the override wins.

### Styling

- **Use `@page` rules**, `@media print`, and `page-break-inside: avoid` for paged-media behavior.
- **Inline CSS in a `<style>` block** is preferred over external stylesheets when using `renderInline`, because `page.setContent` does not resolve relative asset URLs the same way a real navigation does. For external Tailwind/Vite assets, prefer `renderView` so the page is fetched through the BLB HTTP stack.
- **Avoid web fonts** unless they are embedded or pre-installed in the Chromium binary. The OS font fallback differs across deployment hosts and is a known source of print-vs-screen divergence.
- **Use `font-variant-numeric: tabular-nums`** on numeric columns so amounts align.

Print-vs-screen parity is verified **per template**, not assumed. There is no global guarantee that what you see in the browser is exactly what Chromium prints.

---

## Artifact contract

`App\Base\Pdf\ValueObjects\PdfArtifact` is the renderer's return shape:

| Field | Meaning |
|---|---|
| `disk` | Laravel filesystem disk name (e.g. `local`, `pdf-artifacts`, `s3`) |
| `path` | Path within the disk: `<artifact_directory>/YYYY/MM/DD/<sha256>.pdf` |
| `templateVersion` | Caller-provided template version tag (e.g. `payslip@v1`) — included in lineage |
| `dataVersion` | Caller-provided data version tag (e.g. `payroll_run_id=42`) — included in lineage |
| `bytes` | Byte length of the PDF |
| `sha256` | SHA-256 of the PDF contents; the path is derived from this so duplicate renders deduplicate |
| `producedBy` | User ID that triggered the render, or `null` |
| `producedAt` | UTC timestamp of persistence |

The caller is responsible for **what** to do with the artifact: stream to HTTP, attach to a queue job, deliver to ESS portal, etc. The renderer does not surface raw bytes or base64 payloads to production callers — the Phase 1 `pdf_base64` fallback in the Node runner exists only as a legacy compatibility path.

---

## Concurrency model

PDF rendering is **CPU- and memory-bound at the Chromium subprocess**, not at PHP. Phase 1 measured cold render at ~3.95 s and warm renders at ~1.9 s median on a Windows dev host with ~64 KB output per payslip. Per-Chromium RSS is ~150–300 MB depending on document size and embedded resources.

### Queue worker pool is the concurrency primitive

`App\Base\Pdf\Jobs\RenderPdfJob` (a `ShouldQueue` job) is the unit of parallelism. Each running queue worker handles one PDF at a time, each render spawns its own Chromium, and the host's queue-worker count is the upper bound on concurrent renders. There is no in-process browser pool that survives across renders.

This is deliberate. A persistent Chromium pool would save ~2 s per render of cold-start cost but introduce a long-lived, shared subprocess that complicates Octane worker recycling, makes per-request memory accounting unclear, and adds an entire failure mode (orphaned/zombied browsers). Chromium cold start is acceptable overhead given typical Malaysian SMB payroll volumes; if it stops being acceptable, the escape valves in the plan (Gotenberg microservice, or an in-process page pool) are the right next step, not a custom in-house pool.

### Sizing the worker pool

| Constraint | Rule |
|---|---|
| Host memory budget | `max_workers ≈ floor((available_memory_mb − app_overhead) / 300)` using the conservative 300 MB per Chromium |
| Host CPU | `max_workers ≤ cpu_cores` — Chromium is single-core per page, but the OS scheduler still benefits from headroom |
| Wall time for a 500-employee monthly run | `~500 × 1.9 s / max_workers` — at 4 workers, ~4 minutes; at 8 workers, ~2 minutes |
| Queue back-pressure | The same `queue:work` flags that govern other BLB jobs apply; PDF jobs are not special-cased |

For a 16 GB / 8-core production host, a starting point of 4–6 PDF workers leaves room for the rest of the app. Tune against real measurements per environment.

### Per-company throttling

`App\Modules\Core\AI\Services\Browser\BrowserPoolManager` already tracks a per-company context budget (`ai.tools.browser.max_contexts_per_company`, default 3) for the AI browser tool. It records **logical contexts** in memory — not OS processes — and that is the right level of abstraction. PDF rendering does not currently consult `BrowserPoolManager`; the queue worker count is the bound, and per-company fairness is left to queue-level mechanisms (separate queues per tenant, queue prioritization, etc.) if it becomes needed.

If a future requirement is "no single tenant should hog more than N parallel PDF renders," the cleanest implementation is for `RenderPdfJob::handle` to acquire a `BrowserPoolManager` context for the tenant before invoking the renderer and release it after. The Phase 1 spike intentionally does not do this — it would couple `Base/Pdf` to `Modules/Core/AI` and introduce a behavior the renderer's contract does not require.

### What does NOT live in this pool

- **Long-running Chromium daemons.** Each render spawns and tears down its own Chromium.
- **Cross-render page state.** Cookies, storage, history are scoped per render.
- **Process reuse across companies.** The queue worker is shared, but each render is isolated.

If any of these become a real requirement, they belong with an explicit escape hatch (Gotenberg, persistent Playwright server) rather than with the renderer.

---

## Auth model (recap)

The signed render URL carries an opaque token id. The actual claims (view name, view data, user id, template/data versions) live in the cache keyed by that token id with a TTL matching the URL signature expiry. The URL signature prevents tampering; the token's single-use consumption prevents replay; the actor impersonation is server-side and never crosses the HTTP boundary.

The TTL is short by design — see `BLB_PDF_SIGNED_URL_TTL` (default 60 s) in `docs/guides/pdf-rendering.md`. PDF rendering finishes in seconds, so a long TTL would only widen the replay window without enabling any real flow.

---

## What is explicitly out of scope

- **Arbitrary user-driven PDF editing.** BLB produces PDFs from templates; it does not let users open and edit an existing PDF.
- **Scanned-PDF OCR.** Outside the renderer's scope; would belong to a separate document-intelligence module.
- **Electronic signature embedding.** Will require its own design pass (cryptographic key handling, trust chain, signature visualization).
- **Filling AcroForms in LHDN-issued template PDFs.** The plan documents `pdf-lib` as the escape hatch if this ever becomes a real requirement; not implemented.
- **Arbitrary external URL rendering.** `renderView` mints its own signed URL; it does not accept a caller-supplied external URL. This is a security boundary, not an oversight.

---

## See also

- `docs/guides/pdf-rendering.md` — operational setup (`qpdf`, Playwright Chromium, env knobs, Windows extension flags)
- `docs/plans/people/04_pdf-generation-strategy.md` — strategic decisions, license audit, phase status
- `app/Base/Pdf/` — implementation
- `tests/Feature/Base/Pdf/` — `PdfRendererSpikeTest` (auth, artifact, inline paths) and `PdfRendererBenchTest` (opt-in Chromium throughput)
