# ai-provider-families

**Status:** In progress
**Last Updated:** 2026-06-17
**Sources:** `app/Base/AI` (LLM provider engine: `LlmClient`, `AiApiType`, protocol clients), `app/Modules/Core/AI` (provider admin: `AiProvider`/`AiProviderModel`, `ModelCatalogService`, `Providers` hub), `app/Base/Media/PhotoCleanup` (image provider: `PhotoCleanupProvider`, `PhotoRoomConfiguration`), `app/Base/AI/Contracts/Tool.php` (the container-tag registry pattern this mirrors), `docs/architecture/module-system.md` (Contract + adapters, discovery), `docs/plans/media-photo-cleanup-providers.md` (the image family's internal sub-plan).
**Agents:** claude/Sonnet-4.6

## Problem Essence

`/admin/ai/providers` is named for all of AI but only models **LLM/chat** providers: a provider has models, speaks a chat/responses `AiApiType`, runs through `LlmClient`, and bills in tokens. BLB is a platform and external AI is no longer only language — image is live today (PhotoRoom), and world/video/speech are foreseeable. The two naive options are both wrong: cramming non-LLM providers into the LLM model (`AiProvider`/`AiProviderModel`/`AiApiType`/`LlmClient`) makes a shallow god-model, and scattering a separate page per family fragments the IA.

## Desired Outcome

One "AI Providers" hub backed by a **thin family-neutral spine**, where each AI family owns its own thick parts (capability model, runtime, selection, billing). Adding a family — or a provider within a family — touches only that family. The existing LLM provider becomes **family #1**, re-seated under the spine without breaking its mature, heavily-tested behavior; image (PhotoRoom, then Claid) is **family #2**. The `admin/ai/*` hub already exists (providers, task-models, pricing, tools, control-plane), so this is convergence within it, not a new top-level surface.

## Top-Level Components

- **Spine.** `AiProviderFamily` contract — self-describing and container-tagged, exactly like `Tool`. `AiProviderSummary` DTO — neutral provider identity + connection state + manage affordance. `AiProviderFamilyRegistry` — collects tagged families.
- **Families.** LLM (`Core/AI`, wraps the existing `AiProvider` rows). Image (`Base/Media`, wraps PhotoRoom; Alibaba, Claid, Poof, Stability, and AWS Bedrock are catalogued as credential-only entries — no built client yet).
- **Hub.** `admin/ai/providers` renders families from the registry.

## Design Decisions

- **Generalize at the spine, not the LLM model.** The shared layer is thin: identity, family, connected-state, configured-state, and a one-line description. Families own the thick layers — capability (models+sampling+tools vs operations+params), runtime (`LlmClient`/SSE vs `IntegrationGateway`/REST), selection (task→model vs task→operation), billing (tokens vs operations/credits). `AiProvider`/`AiProviderModel`/`AiApiType`/`LlmClient` are the **LLM family's internals**, not a base to widen.
- **Families register via container tag** (`AiProviderFamily::CONTAINER_TAG`), the same mechanism AI Tools use, so Core/AI never imports another family's domain classes and any module can contribute a family. The image family is contributed by `Base/Media`.
- **The contract lives in `Base/AI`** (framework) so both `Core/AI` (LLM) and `Base/Media` (image) implement it without a wrong-way `Base → Modules` dependency.
- **Credential scope stays family-owned.** LLM and Vision providers are both company-scoped `AiProvider` rows, distinguished by `family` (`llm` vs `image`).
- **Keep the spine thin enough for the world-model test.** A future world/video/speech family shares even less with an LLM than image does; if the spine ever assumes models/tokens/chat it has already failed. The more families anticipated, the thinner the shared layer must be.
- **No big-bang rewrite of the LLM subsystem.** It is mature and carries heavy test coverage (connect flows, model discovery, task-model routing, pricing). Re-seat it under the spine incrementally. Phase 1 wraps it read-only for the registry/overview and does not touch its management UI, discovery, routing, or pricing.

## Public Contract

- `AiProviderFamily`: `key()`, `label()`, `capabilityLabel()`, `providers(?int $companyId): list<AiProviderSummary>`.
- `AiProviderSummary`: family key, provider key, display name, `connected` (bool), `configured` (bool), description. (No status line / manage URL: the hub frames the action — Connect when unconfigured, Edit when configured — and the modal is opened by provider key.)

## Phases

### Phase 1 — Spine + families + hub overview ✅ (done 2026-06-16, claude/Sonnet-4.6)

- [x] `AiProviderFamily` contract (`Base/AI`) with `CONTAINER_TAG`; `AiProviderSummary` DTO; `AiProviderFamilyRegistry` (`Core/AI`) collecting tagged families.
- [x] `LlmProviderFamily` (`Core/AI`) wraps configured `AiProvider` rows into summaries; tagged. No change to LLM management/discovery/routing.
- [x] `ImageProviderFamily` (`Base/Media`) surfaces PhotoRoom plus credential-only Vision providers; `connected` reflects a working cleanup client where wired; credentials live in company-scoped `AiProvider` rows (`family = image`). Tagged from the Media service provider.
- [x] `admin/ai/providers` is organized as primary family **tabs** (LLM | Image), each with the same two-card shape: connected (activated) providers and providers ready for connection. The LLM tab reuses the existing connected-providers table + catalog island; the Image tab is driven by the registry. Page help copy reframed to be family-aware (honesty). Roadmap placeholder tabs (Speech/Video/Predictive) were trialed then removed — only real families appear; the roadmap lives in Phase 3.
- [x] Tests: `AiProviderFamiliesTest` — registry contains both families; image reports configured/not from settings; LLM maps connected providers; the hub renders the LLM/Image tabs with the two-card shape. Existing `ProvidersUiTest` (LLM table/modals inside the tab) + full AI suite stay green.

### Phase 2 — Per-family setup + connection test ✅ (done 2026-06-16, claude/Sonnet-4.6)

**Design correction (made during build):** the original "render the LLM family through the spine / retire the bespoke table" bullet pulled toward a fat shared *rendering* contract — the same god-abstraction we rejected, one layer up. The correct expression of the thin spine is: **the spine owns the uniform overview + a per-family setup/manage entry; each family keeps its own management surface.** LLM keeps its rich table (models, priority, sync, execution controls); image gets a lean setup. Forcing LLM's affordances through a generic renderer would re-bloat the spine, so that is not done by design, not deferred.

- [x] Per-family setup for the Vision family: `ImageProviderSetup` modal — API key (+ region where applicable) via `ImageProviderCredentialStore` into `ai_providers` (`family = image`), no model discovery.
- [x] ~~Live connection test: `PhotoRoomConnectionTester` sends a 1×1 PNG through the real Remove Background call~~ — removed 2026-06-17: credential storage is sufficient; key validity is established when the provider is first used, not via a costly on-modal feature test.
- [x] LLM family keeps its existing management surface unchanged, reached via the spine overview — the rejected fat-spine rewrite is deliberately avoided.
- [x] Tests: `ImageProviderSetupTest` (setup saves keys; unknown-provider ignored; endpoint panel layout). Core/AI + AI/Media sweeps green.

### Phase 3 — Usage/credits + more families

- [ ] Family-specific usage/spend surfaced uniformly (LLM tokens; image operations/credits) — carries the open item from `media-photo-cleanup-providers.md`.
- [ ] A second image provider with a *working* cleanup client — the trigger for the registry in `media-photo-cleanup-providers.md`. Stability's synchronous Remove Background is the most natural fit for the current sync engine; Alibaba/Claid/Poof/Stability/AWS Bedrock are already catalogued as credential-only entries (keys store, no client built yet).
