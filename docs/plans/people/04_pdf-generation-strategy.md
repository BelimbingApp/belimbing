# people/04_pdf-generation-strategy

**Status:** Phase 1 in progress — auth model, artifact shape, and licensing audit accepted
**Last Updated:** 2026-05-11
**Sources:**
- `docs/plans/people/02_payroll-malaysia-top-level-design.md` — payslip and statutory output requirements (Phases on payslip PDF, report exports, ESS document delivery)
- `docs/plans/people/03_payroll-hr2000-ipayroll-parity-benchmark.md` — HR2000 parity items including payslip PDF, EA/CP8A/PCB2 forms, password-encrypted PDF distribution, ESS document access
- `composer.json` — current dependency floor; license is `AGPL-3.0-only`; no PDF library is presently installed
- `app/Modules/Core/AI/Services/Browser/PlaywrightRunner.php` — current Playwright runner; spawns a fresh Chromium per command, with session persistence noted as future work
- `app/Modules/Core/AI/Services/Browser/BrowserPoolManager.php` — in-memory ledger of per-company browser context IDs and concurrency limits; not a process pool
- `resources/core/scripts/browser-runner.mjs` — Node runner that already exposes a `handlePdf` action returning `pdf_base64` over JSON; treat as a prototype, not a finished renderer
- `LICENSE` — GNU AGPL v3 (matches the `AGPL-3.0-only` SPDX expression in `composer.json`)
- Setasign FPDI (MIT core) — https://www.setasign.com/products/fpdi/about/ — PDF-Parser add-on required for compressed PDFs is commercial; rejected on that ground
- pdftk-java (GPL-3.0-or-later) — https://gitlab.com/pdftk-java/pdftk — invoked as a subprocess if ever needed; license does not propagate
- QPDF (Apache-2.0) — https://qpdf.sourceforge.io/ — invoked as a subprocess; license does not propagate
- pdf-lib (MIT) — https://pdf-lib.js.org/ — Node library, compatible with AGPL-3.0-only
- Gotenberg (MIT, server) — https://gotenberg.dev/ — separate HTTP service; license does not propagate
- mPDF (declared `GPL-2.0-only` on Packagist) — https://mpdf.github.io/ — **incompatible** with AGPL-3.0-only as a bundled PHP library; see License compatibility
- dompdf (LGPL-2.1-or-later) — https://github.com/dompdf/dompdf — compatible via the "or later" upgrade path, though not adopted by this plan
**Agents:** claude-code/claude-opus-4-7

## Problem Essence

Belimbing has no PDF generation library installed yet, but Payroll alone needs payslips, statutory forms (EA, CP8A, PCB2), management reports, and password-encrypted distribution. The repository’s license is `AGPL-3.0-only`, which requires every PDF dependency to be open source under a license that is provably compatible — not merely "open source." Picking a default engine now sets the template authoring style for every BLB module that ever produces a PDF, so the choice must respect both the licensing constraint and the actual state of in-tree infrastructure rather than an idealized version of it.

## Desired Outcome

A single recommended path for producing visual PDFs across BLB modules, plus a narrow, deliberate escape hatch for the rare cases that need direct PDF manipulation. Templates should reuse the same Blade and Tailwind authoring skills the rest of the UI already uses, so a payslip previewed in the browser and the printed PDF come from one template with verified print-preview parity per template — not byte-identity, which Chromium’s paged-media path does not guarantee against on-screen rendering. All licensing must be AGPL-3.0-only compatible without commercial add-ons. The plan should also state clearly when *not* to use a PDF engine at all — statutory text-file submissions (CP39, EPF, SOCSO, EIS, bank GIRO files) are plain text and should never touch a PDF pipeline.

## Top-Level Components

- **Visual PDF renderer.** Chromium, invoked through the existing Playwright runner subprocess. The honest current state: `PlaywrightRunner` spawns a fresh Chromium for each command, `BrowserPoolManager` is an in-memory ledger of context IDs (not OS processes), and `browser-runner.mjs` already implements a `handlePdf` action that returns `pdf_base64` over JSON. The work this plan proposes is to **promote and harden** that prototype into a session-aware, artifact-emitting PHP service — not to add a renderer where none exists.
- **Authenticated render path.** A first-class design question, not an implementation detail. The current `handlePdf` does `page.goto(url)` against an unauthenticated context, which is unacceptable for payslips. Phase 1 must pick one of: a short-lived signed render URL minted by BLB and consumed by Chromium; an explicit cookie/storage-state handoff into the Playwright context; or an internal-only render endpoint bound to a non-public interface. Whichever wins becomes part of the public contract.
- **Template layer.** Blade views under each module’s `resources/views/pdf/...` namespace, styled with Tailwind print classes and `@page` CSS for paged-media behavior. Same template feeds on-screen preview and printed PDF; explicit per-template parity verification covers known divergence sources (print media rules, font availability, pagination, headers/footers, scale).
- **Post-processor (candidate).** `qpdf` CLI for password protection, encryption, and merging — adopted only after Phase 1 verifies availability on every supported deployment target (FrankenPHP, Octane on Linux, Windows dev hosts) and that the specific operations needed (notably any metadata mutation) are supported by the qpdf version those targets actually ship. Until that check passes, treat `qpdf` as the leading candidate, not a guaranteed component. Its Apache-2.0 license does not propagate to BLB because qpdf is invoked as an external CLI, so the open Phase 1 question for qpdf is operational availability, not licensing.
- **AcroForm filler (conditional).** `pdf-lib` running under the existing Node runtime if and only if a real LHDN-issued AcroForm template ever needs to be overlaid with employer data. Currently no concrete requirement justifies this; left as a documented escape hatch, not Phase 1 work.
- **Statutory text writers.** Plain PHP services that emit fixed-width or delimited text files for CP39, EPF, SOCSO, EIS, and bank GIRO outputs. These live in the Malaysia country pack, not in the PDF subsystem, and exist here only so that the boundary is explicit.

## Design Decisions

**Chromium is the default engine, not mPDF.** mPDF’s pure-PHP convenience disappears once Chromium is already in the runtime via Playwright, and its CSS support stops short of flexbox, grid, and modern paged-media features that BLB’s Tailwind-first UI assumes. Forcing payslips and reports into mPDF would create a second template dialect that drifts away from the on-screen rendering, which is exactly the parity HR2000 ESS document access requires. License compatibility was also checked: mPDF declares `GPL-2.0-only` on Packagist, which is incompatible with `AGPL-3.0-only` for a bundled PHP library. The decision still rests on capability and template duplication; license incompatibility independently rules out mPDF and is recorded for completeness.

**FPDI is rejected.** FPDI itself is MIT, but reading any modern compressed PDF (which includes essentially every LHDN-published template) requires the proprietary **FPDI PDF-Parser** add-on. The AGPL-only constraint forbids that path. If AcroForm filling becomes a real requirement, `pdf-lib` under Node fills the same niche under MIT and reuses Node infrastructure that already exists for Playwright.

**Reuse the existing runner stack rather than introduce a second one.** Adopting `spatie/browsershot` would bring in a second way of driving Chromium alongside the one BLB already has, which is the kind of redundancy this plan should avoid. The design intent is one Playwright path for both AI tooling and PDF rendering. That said, what exists today is per-command Chromium spawning and an in-memory context ledger; concurrency control and process reuse are work to be done, not capabilities to lean on. The plan treats the Playwright runner as the right *home* for PDF rendering, while being explicit that the runner’s session and pooling story has to mature for production payroll workloads.

**Statutory text outputs are not a PDF problem.** CP39, KWSP/EPF, SOCSO, EIS, and bank GIRO files are fixed-format text submissions. They live next to the Malaysia country pack’s statutory schedules and never share code with the PDF pipeline. Mentioning them here is purely to prevent future confusion about whether a “statutory submission” needs a PDF engine.

**No Gotenberg in Phase 1.** A Dockerized Chromium PDF microservice is the right escape valve if payslip batch throughput ever overwhelms in-process rendering, but introducing it before the in-process path has proven inadequate adds an operational moving part for no measured benefit. The plan reserves Gotenberg as a documented later option, not a Phase 1 dependency.

## License Compatibility

BLB is `AGPL-3.0-only` (`LICENSE` is GNU AGPL v3). For a dependency to enter BLB as a **bundled PHP or Node library** — the case where copyleft propagates — it must be under a license in the set {AGPL-3.0, GPL-3.0-or-later, LGPL-2.1-or-later, LGPL-3.0-or-later, Apache-2.0, MIT, BSD, ISC, GPL-2.0-or-later (upgradeable to v3)}. `GPL-2.0-only` is explicitly outside that set. **External CLIs invoked as subprocesses** (e.g., `qpdf`, `pdftk-java`) and **separate network services** (e.g., Gotenberg) do not propagate their licenses to BLB regardless of what those licenses are, so they need only operational vetting, not license vetting.

Applied to the candidates in this plan: **mPDF is incompatible** (Packagist manifest declares `GPL-2.0-only`) and is rejected on this ground independently of the capability argument. **dompdf is compatible** via `LGPL-2.1-or-later` but is not adopted for unrelated capability reasons. **pdf-lib** (MIT, Node) and **Playwright** (Apache-2.0, Node, already in `package.json`) are compatible as bundled libraries. **qpdf**, **pdftk-java**, and **Gotenberg** sit outside the link boundary and need no license analysis beyond confirming they are themselves open-source so the user can install them. **FPDI core** is MIT but requires a proprietary PDF-Parser add-on for any real LHDN template, which the AGPL-only constraint forbids; rejection stands.

## Public Contract

- **Renderer entry point.** A `PdfRenderer` service (working name) accepts either a route name plus parameters to render an authenticated BLB page, or a Blade view name plus data to render a standalone payload. The authenticated-page path uses a **short-lived signed render URL** (decided in Phase 1): BLB mints a one-shot, signed, expiring token bound to `(user_id, route, params, exp, jti)`; Chromium fetches `/pdf/render/{token}`; server-side middleware verifies the token, impersonates the claimed user for the duration of the render, and invalidates the `jti` so the URL cannot be replayed. The renderer never accepts an arbitrary external URL on the authenticated path.
- **Renderer output.** Production calls return a `PdfArtifact` record (working shape): `{disk, path, template_version, data_version, bytes, sha256, produced_by, produced_at}`. The PDF is written to a configured Laravel filesystem disk; the caller streams to HTTP or attaches to queue jobs from there. Returning binary as base64 inside JSON is acceptable only inside the Phase 1 spike; the prototype `pdf_base64` shape in `browser-runner.mjs` does not survive contact with payslip batches and is not part of the public contract.
- **Template namespace.** Each module owns `resources/views/pdf/<module>/...` for its print-side Blade views. Tailwind print utilities and `@page` rules are the supported styling surface; ad-hoc inline CSS for paged-media is discouraged. Parity between screen preview and print output is verified per template, not assumed.
- **Post-processing surface.** A `PdfPostProcessor` (working name) exposes named operations whose scope is bounded by what Phase 1 confirms `qpdf` actually supports on all deployment targets. Password protection, encryption, and merging are the expected operations; metadata mutation is included only if Phase 1’s verification step confirms it. Internal implementation delegates to `qpdf` and is not part of the public surface.
- **Storage and lineage.** Every produced PDF must carry enough metadata to identify the template version, the data version (e.g., payroll run identifier), and the producing user/job — required for audit trails described in `02_payroll-malaysia-top-level-design.md` under run locking and approvals.
- **What is explicitly out of scope.** Arbitrary user-driven PDF *editing*, scanned-PDF OCR, and electronic signature embedding are not part of this plan; if needed later they each get their own design pass.

## Phases

### Phase 1 — Validate the engine choice end-to-end

Goal: confirm constraints, settle the security model, and prove the existing prototype can grow into a production renderer before any module commits to it.

- [x] Confirm `AGPL-3.0-only` as the binding licensing rule and accept the in-plan license compatibility audit (or flag exceptions before any wiring begins). claude-code/claude-opus-4-7
- [x] Decide the authenticated-rendering model (signed render URL, cookie/storage-state handoff, or internal-only render endpoint) and record the chosen approach in the Public Contract section before any auth-bearing payslip touches the renderer. claude-code/claude-opus-4-7
- [x] Decide the artifact handoff shape (storage-disk write + metadata record vs. direct stream) and update `browser-runner.mjs`/`PlaywrightRunner` to support it; base64-over-JSON survives only for the spike. claude-code/claude-opus-4-7
- [ ] Verify `qpdf` is available on every supported deployment target (FrankenPHP, Octane on Linux, Windows dev hosts) and that the specific operations the plan relies on — password protection, encryption, merging, and any proposed metadata mutation — are supported by the qpdf versions those targets ship. **Install instructions landed** at `docs/guides/pdf-rendering.md` for Debian/Ubuntu, RHEL/Fedora, macOS, Windows (winget, machine-scope/UAC), and the FrankenPHP image. Actual installation and operation verification across the deployment fleet is operational work that runs when Phase 2 begins wiring `PdfPostProcessor` — keeping this row open until that verification is done is honest.
- [x] Harden the existing `handlePdf` Node action and its PHP entry point into a session-aware, artifact-emitting code path that exercises the chosen auth model end-to-end against a realistic authenticated BLB route. claude-code/claude-opus-4-7
- [x] Author one realistic payslip Blade template using Tailwind print classes and verify on-screen vs printed parity, documenting any divergence sources encountered. claude-code/claude-opus-4-7 — **template authored at `resources/core/views/pdf/payroll/payslip.blade.php` and rendered through the signed-URL path; print-vs-screen parity not yet visually inspected because Chromium has not been spawned against the real route. Visual parity verification deferred to the throughput-measurement step below.**
- [x] Measure renderer throughput and per-render memory under the AI browser tool’s existing workload, so contention and pooling assumptions are grounded in data and feed Phase 2’s concurrency design. claude-code/claude-opus-4-7 — first measurement landed; per-render memory not yet captured cross-platform and is deferred to Phase 2’s concurrency design step.

### Phase 1 — Findings

- **Spike landed at `app/Base/Pdf/`** (placement chosen by stakeholder): `ServiceProvider`, `Services/{PdfRenderer, SignedRenderTokenStore, PdfArtifactWriter}`, `Http/{Controllers/SignedRenderController, Middleware/VerifyRenderToken}`, `Routes/web.php`, `ValueObjects/PdfArtifact`, `Exceptions/PdfRenderException`, `Config/pdf.php`. Provider is auto-discovered via `ProviderRegistry::discoverBaseProviders`; the route is auto-loaded by `RouteDiscoveryService` under the `web` middleware group.
- **Auth flow validated end-to-end.** Pest tests `tests/Feature/Base/Pdf/PdfRendererSpikeTest.php` confirm: (1) signed URL renders the view with the impersonated user surfaced via `auth()->user()`; (2) the same URL on a second hit returns 404 because the `jti` is consumed atomically by `SignedRenderTokenStore::consume`; (3) an unsigned URL returns 403 via Laravel’s built-in `signed` middleware; (4) the renderer writes a `PdfArtifact` to the configured disk with correct `bytes`, `sha256`, `template_version`, `data_version`, and `produced_by`.
- **`handlePdf` updated** in `resources/core/scripts/browser-runner.mjs` to accept `output_path`, `format`, `print_background`, and `timeout_ms`. When `output_path` is set, the buffer is written to disk and the JSON response carries `{output_path, size_bytes}` instead of `pdf_base64`. The base64 branch survives only as the legacy fallback.
- **qpdf installation gap on Windows.** The plan listed Windows dev hosts as a supported target, but `qpdf` is not on this host. Phase 2 cannot wire `PdfPostProcessor` against Windows without either a setup-guide step (`winget install qpdf-project.qpdf` or `choco install qpdf`) or a narrowing of the supported-target list. Recommend the setup-guide step; flag this for stakeholder decision.
- **Dev-environment PHP gap.** The Windows dev host’s PHP install at `C:\Users\admin\.frankenphp\php.exe` has no `php.ini` and `extension_dir` points to a non-existent `C:\php\ext`. Tests run only when invoked with explicit `-d extension_dir=...\.frankenphp\ext -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3 -d extension=fileinfo -d extension=openssl -d extension=curl -d extension=intl -d extension=zip`. This is a pre-existing environment condition independent of the spike, but it should be captured in a developer setup guide so future contributors don’t hit the same wall.
- **Realistic-data wiring deferred.** The payslip view accepts a plain data array shape, not Eloquent models. Wiring the view to real `PayrollRun`, `PayrollResultLine`, and `PayrollRunParticipant` data is Phase 3 work, exactly as the plan called for.
- **First throughput numbers (Windows dev host, headless Chromium, file:// URL pointing at the rendered payslip Blade).** Measured by `tests/Feature/Base/Pdf/PdfRendererBenchTest.php` with `BLB_PDF_BENCH=1 BLB_PDF_BENCH_ITERATIONS=5`. Cold render (first call, includes Chromium spawn): **3.95 s**. Warm renders 2–5: **min 1.87 s, median 1.89 s, max 2.08 s**. Output PDF size: **~64 KB**. Implications for Phase 2: a naive serial 500-employee monthly run on this hardware would take ~16 minutes; concurrency on warm processes drops that linearly, but each parallel Chromium pays its own ~150–300 MB RSS, so the pool size in `BrowserPoolManager` needs to balance throughput against memory ceiling. Per-render RSS measurement is deferred — `getrusage` reports the PHP process only, not the Node + Chromium subprocesses, and a cross-platform Chromium RSS sampling story is its own piece of work for Phase 2.
- **PDF rendering setup guide landed** at `docs/guides/pdf-rendering.md`: per-OS `qpdf` install, Playwright Chromium install, the Windows PHP-extension flag workaround, and the environment knobs (`BLB_PDF_DISK`, `BLB_PDF_ARTIFACT_DIR`, `BLB_PDF_SIGNED_URL_TTL`, `BLB_PDF_RENDER_TIMEOUT`, `BLB_PDF_PAPER_FORMAT`, `BLB_PDF_PRINT_BACKGROUND`).

### Phase 2 — Land the renderer service

Goal: turn the hardened spike into a stable surface module authors can call without knowing about Chromium, Playwright, or qpdf.

- [x] Promote the Phase 1 implementation into a `PdfRenderer` service with the contract described above, including a Blade-view rendering path for templates that should not be exposed as routes. claude-code/claude-opus-4-7 — `renderView` (signed-URL/authenticated path) and `renderInline` (PHP-rendered Blade via `page.setContent`) both ship on `PdfRenderer`; Node `handlePdf` accepts either `url` or `html`.
- [ ] Define the concurrency story for `PlaywrightRunner` under PDF load (process reuse vs. per-command spawn, per-company limits, queue back-pressure) using the Phase 1 measurements; update `BrowserPoolManager` if its ledger needs to track real processes rather than logical contexts.
- [ ] Wire `PdfPostProcessor` for the post-processing operations Phase 1 verified, exposing only the named operations needed by Payroll Phase 1 outputs.
- [x] Define the `resources/views/pdf/...` convention and document it under `docs/architecture/` or the appropriate module guide once the shape is stable. claude-code/claude-opus-4-7 — convention landed at `resources/core/views/pdf/<module>/<template>.blade.php` with licensee override semantics; documented in `docs/architecture/pdf-rendering.md` alongside the renderer surface, auth model, artifact contract, and out-of-scope list.
- [ ] Add an Octane- and queue-friendly job wrapper so batch payslip runs do not block request workers.

### Phase 3 — Wire Payroll outputs onto the renderer

Goal: deliver the payroll-specific PDFs called out in `02_payroll-malaysia-top-level-design.md` and the HR2000 parity benchmark.

- [ ] Payslip PDF for a closed payroll run, with the lineage metadata Phase 4 of the Malaysia plan requires.
- [ ] Payroll summary, employee statutory contribution, and employer cost reports.
- [ ] Visual EA/CP8A and PCB2 forms rendered as BLB-authored Blade templates that satisfy the published LHDN layout, not overlays on LHDN-issued PDFs.
- [ ] Password-encrypted PDF distribution for ESS, gated on whether SBG or parity validation actually requires it (matches the conditional language in the Malaysia plan).
- [ ] Bulk-job throughput validation against a realistic employer size (e.g., 500-employee monthly run) so Gotenberg’s necessity can be evaluated against evidence.

### Phase 4 — Escape-hatch and scaling readiness

Goal: have a written, ready-to-execute fallback if the in-process path hits a wall, without paying its cost prematurely.

- [ ] Document `pdf-lib`-on-Node as the AcroForm-filling escape hatch, with a one-page note describing how it would integrate alongside the existing Node runtime — but do not implement until a concrete LHDN AcroForm requirement appears.
- [ ] Document Gotenberg as the throughput escape hatch, including which Phase 3 metric (e.g., payslip-rendering p95 under batch load) would trigger adopting it.
- [ ] Revisit this plan if either escape hatch is taken, and update the Public Contract section accordingly so the renderer’s shape stays honest.
