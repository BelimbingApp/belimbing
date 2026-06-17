# media-photo-cleanup-providers

**Status:** Proposed
**Last Updated:** 2026-06-16
**Sources:** `app/Base/Media/PhotoCleanup/*` (the shipped engine + PhotoRoom adapter), `docs/architecture/module-system.md` (Contract + adapters), `extensions/ham/docs/plans/ham-auto-parts-continuation.md` (PhotoRoom chosen as the *first* provider), `docs/plans/ai-lara-collapse-into-provider-priority.md` (existing provider-priority shape to mirror, not duplicate).
**Agents:** claude/Sonnet-4.6

## Problem Essence

`photo-cleanup` runs background removal through PhotoRoom, chosen as the *first* of presumably several providers. The engine now depends on a `PhotoCleanupProvider` contract (PhotoRoom is the bound default), but provider selection is still a single hard container binding — there is no registry, no per-provider settings surface, and no operator choice. A second provider cannot be added without editing `Base\Media\ServiceProvider`.

## Desired Outcome

A second background-removal provider can register and be selected without touching the engine or the first provider: the engine keeps owning the derivative lifecycle (read bytes → call provider → write `background_removed` derivative + provenance), while each provider ships as a discoverable adapter with its own credentials and an operator-visible selection. Done when adding a provider is "create an adapter + settings entry," not "edit Base."

## Top-Level Components

- **Engine** — `PhotoCleanupService` (shipped). Provider-agnostic; records `provider`, `provider_label`, `environment` provenance on each derivative.
- **Contract** — `PhotoCleanupProvider` (shipped). `removeBackground()` returns cleaned bytes + provenance.
- **Registry/selection** — not built. Resolves the active provider from settings instead of a fixed container binding.
- **Per-provider config** — Vision credentials live in company-scoped `ai_providers` rows (`family = image`); PhotoRoom uses a single `api_key`, region-style fields use `connection_config`.
- **Operator surface** — not built. A settings control to pick the active provider and see configured/unconfigured state.

## Design Decisions

- **Reuse, don't reinvent, provider-priority.** BLB already has provider-priority machinery for Lara/AI (`docs/plans/ai-lara-collapse-into-provider-priority.md`). Photo cleanup should mirror that selection shape rather than grow a parallel registry idiom. Decide during build whether the AI provider-priority service is genuinely shared or only a template.
- **Keep the engine sealed.** The registry resolves and returns a `PhotoCleanupProvider`; `PhotoCleanupService` must not gain provider branches. Provenance already carries the discriminator, so historical derivatives stay attributable across provider changes.
- **`environment` stays generic.** Sandbox/live is PhotoRoom-shaped but persisted as a neutral provenance string. A provider without that concept returns its own mode; do not special-case PhotoRoom in the engine or schema.
- **Adapter ownership.** Background removal is a generic platform capability, so default adapters live in `Base/Media` (unlike geo-specific marketplace channels, which are extension-owned). A licensee-specific provider could still register from an extension.
- **Don't build speculative slots.** Per `module-system.md`, this stays a single bound default until a real second provider exists; this plan is the trigger to convert the binding into a registry at that point, not before.

## Phases

### Phase 1 — Contract seam ✅ (done 2026-06-16, claude/Sonnet-4.6)

- [x] Engine depends on `PhotoCleanupProvider`; PhotoRoom implements it and is bound as the default in `Base\Media\ServiceProvider`.
- [x] Provenance carries `provider` + `provider_label`; UI renders the persisted provider instead of a hardcoded brand.

### Phase 2 — Registry + selection (when a 2nd provider is real)

- [ ] Resolve the active `PhotoCleanupProvider` from settings (provider id) rather than a fixed binding; unknown/unconfigured id fails with a clear operator-facing error, not a container exception.
- [x] Define the uniform per-provider credential shape on `ai_providers` (`family = image`).
- [ ] Operator surface to choose the active provider and show per-provider configured/unconfigured state, alongside the existing PhotoRoom environment toggle.
- [ ] Confirm `PhotoCleanupService` still has zero provider branches after the registry lands (engine-sealed check).

### Phase 3 — Usage/credits visibility (tracked, not provider-pluggability)

- [ ] Surface provider usage/remaining credits through the shared operator status UI (carried from the Ham Phase 2 checklist; applies per active provider).
