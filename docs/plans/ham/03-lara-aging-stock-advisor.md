# ham/03-lara-aging-stock-advisor

**Agents:** claude-code/opus-4.7
**Status:** Identified — design captured, no code yet. Awaiting user approval before Phase 1 implementation.
**Last Updated:** 2026-05-02
**Sources:**
- Parent plan: `docs/plans/ham/01-ebay-car-parts-operations.md` (Phase 7 surfaces the aging-stock data; Phase 6 will eventually carry the write-path actions this advisor recommends).
- Companion research: `docs/plans/ham/02-ebay-sell-api-research.md` (Phase 6 build order — informs which actions become one-click vs. advisory-only at each stage).
- Lara task model conventions: `docs/plans/lara-task-models.md` (per-task `primary`/`recommended`/`manual` model selection, cost UI surfaces).
- In-repo: `app/Modules/Commerce/Sales/Services/SalesInsightsService::daysListedWithoutSale()` already returns the candidate set; this plan turns those candidates into actionable per-listing recommendations.
- Memory: Ham sells used auto parts (one-off items, not repeating SKUs) — `~/.claude/projects/.../memory/project_ham_used_parts_inventory_shape.md`. Anything that assumes SKU restock is wrong here.

## Problem Essence

Ham's ~2,000 active listings inevitably contain multi-hundred-dollar dead capital — items live on eBay for hundreds of days without selling. Phase 7's "Listed without a sale" page makes the *problem* visible, but the diagnosis-and-remedy work (figure out why this one isn't moving, decide what to do, execute) still sits in Ham's head — exactly the kind of compounding cognitive cost that's expensive when he's also undergoing chemotherapy. Lara can absorb that diagnosis and propose a specific recovery action per listing; the operator's role becomes one-click approval over a queue, not original analysis.

## Desired Outcome

For each stuck listing, Ham gets a structured **advisory card** that names a single primary recovery action, the data behind it, and an estimate of confidence. He can approve, reject, or edit each card in under 30 seconds. The system records the disposition and (eventually) the outcome — did the listing sell within N days after the action was applied — so the advisor's hit rate is observable and the prompt can iterate.

The feature ships in two stages aligned with the master plan's write-path readiness:
- **Pre-Phase-6** (write path not yet live): advisor is *advisory only*. Approve marks the advisory as accepted but does not touch eBay. Ham executes manually if he chooses.
- **Post-Phase-6** (write path live): the same approve action triggers the appropriate `MarketplaceChannel::reviseListing` / `endListing` call, closing the loop.

## Top-Level Components

1. **Lara task: `advise-aging-stock`** — registered in the task-model registry alongside `titling`, `research`, `photo-cleanup`, `describe-item`. Defines prompt, model selection, output schema, and the cost UI surface inherits from the framework. Default model is the `recommended` (cost-tuned) variant; operator can override per-task.

2. **`AgingStockAdvisorService`** — orchestration layer. Takes a `Listing`, assembles the prompt context (current state of the listing, comparable sold items the seller has, freshness signals), invokes the Lara task, validates and persists the structured response.

3. **Inputs** — assembled by BLB without external API dependencies in stage 1:
    - The `Listing` itself (title, description, price, days-listed, photos, status).
    - The linked `Item` if any (catalog category, attributes especially `fitment_*`/`oem_number`/`condition_grade`).
    - **Internal sold-comps**: prior `Sale` rows for the same `category_id` (with similar make/model/year if available), filtered to the same currency, last 12 months. Source: a new query method on `SalesInsightsService` (`comparableSoldItems(Listing): Collection`).
    - Aggregate signals: median sold price for category, count of recent sold comps, whether any sold comp's title contains tokens this listing's title omits.
    - **Out of stage 1**: external comps (eBay Browse API), per-listing engagement signals (views/watchers from eBay), seasonality. Add later if hit-rate falls short.

4. **Advisory record (`lara_advisories` table)** — durable per-(listing × generated-at) row capturing the model output plus disposition. Fields: `id`, `subject_type`/`subject_id` (polymorphic-free; for now just `listing_id` + a single-row pivot), `task` (= `advise-aging-stock`), `generated_at`, `action_class`, `suggested_changes` (JSON), `reasoning`, `confidence`, `status` (`pending` | `approved` | `rejected` | `executed` | `expired`), `approved_at`, `approved_by_user_id`, `executed_at`, `outcome_recorded_at`, `outcome_class` (`sold` | `still_aging` | `withdrawn`). Polymorphic-free per the project's preference; if a future advisor targets `Item` instead of `Listing`, that gets its own table or a discriminator column.

5. **Operator UI** — extension of the existing Phase 7 "Listed without a sale" Livewire page rather than a new top-level page. Each row gains a "💡 Lara's take" affordance: clicking it triggers (or surfaces, if cached) one advisory; approve/reject/edit are inline actions on the same row. A header-level "Advise all (N)" button kicks off a batch run with a per-row progress indicator and a clear cost estimate up front (see Cost discipline).

6. **Loop closure** — the table tracks acceptance, execute, and outcome rates. A small admin dashboard surfaces these so the operator (and the plan owner) can decide whether the advisor is actually paying off; if acceptance rate dips below a threshold, that's the signal to revise the prompt.

## Design Decisions

### Why Lara, not heuristic rules

A pure heuristic ("lower 5% after 90 days") would be uniform across 2,000 idiosyncratic items and miss the per-listing context that *actually* explains why each one isn't selling. Used auto parts have low cardinality at the *category* level (headlight, bumper, mirror) but very high cardinality at the *item* level (year/make/model/trim/condition variations) — the right lever for one item is a price drop, for another a title rewrite, for a third end-and-relist. Lara grounds each recommendation in that listing's specific state plus its specific sold-comps. The token cost of doing so is small relative to the dollar value of recovered inventory.

### Action vocabulary

Five action classes, deliberately small so the operator's review is fast and the model output is predictable. Each carries a structured `suggested_changes` payload:

- `lower_price` — params: `delta_pct` (negative number), `suggested_minor_amount`. Used when comps cluster meaningfully below current price.
- `rewrite_title` — params: `current`, `suggested`. Used when the listing's title omits tokens that show up in matching sold comps (OEM number, side, fitment year range).
- `rewrite_description` — params: `current_excerpt`, `suggested_addition` or `suggested_full`. Used when the description lacks condition specifics or fitment that buyers ask about.
- `refresh_photos` — params: `notes` (e.g. "missing close-up of OEM sticker", "lead photo dim"). Operator-executes manually; not suitable for automated revise.
- `end_and_relist` — params: `reason`, optional `suggested_new_title`. Used when comps show this category is not moving at all in the current window — listing freshness sometimes helps even with identical content.
- `archive` — escape hatch: "this isn't worth more time; donate or scrap." Useful for low-value parts where re-listing fees exceed expected return.

Multiple actions per advisory are explicitly *not* supported in stage 1; if the model thinks two are needed, it picks the higher-leverage one and the operator can re-run the advisor after the first lands. Single-action keeps the cards readable, the prompt simpler, and the outcome attribution cleaner.

### Pre-write-path: advisory-only

Until Phase 6's write path lands, "approve" only persists `status = approved` — there's no automatic eBay revise. Ham still gets value: a queue of pre-analyzed recommendations he can act on manually in eBay's seller hub at his own pace. The system records that he approved, so when Phase 6 lands, the same advisory rows can be re-played as actual `reviseListing`/`endListing` calls if desired (or remain as historical records).

### Post-write-path: same UI, real execute

When Phase 6 ships, approve triggers the appropriate channel call. Each `action_class` maps to a write-path verb:
- `lower_price`, `rewrite_title`, `rewrite_description` → `reviseListing` with the targeted Offer / InventoryItem update.
- `refresh_photos` stays manual (no obvious automated path; needs new photos from Ham).
- `end_and_relist` → `endListing` then a fresh `createListing`.
- `archive` → `endListing` + sets the `Item` status to `archived`.

The execute path writes `executed_at` and starts an outcome timer.

### Outcome tracking

The advisor only earns trust by being measured. Each executed advisory gets a follow-up after N days (e.g. 30): did the listing sell? If yes, `outcome_class = sold`. If no but the listing is still up: `still_aging`. If withdrawn or otherwise gone: `withdrawn`. These three rates plus the simpler `acceptance_rate` (approved / generated) and `execute_rate` (executed / approved) are the metrics that drive prompt iteration.

### Comp data source — internal first, external later

Stage 1 uses only the seller's own `commerce_sales_sales` history, joined through the linked `Item.category_id`. This avoids a new external API dependency at the start (no Browse API, no fee preview, no taxonomy lookups) and gives Lara *Ham-specific* signal which is more relevant than a generic eBay-wide median. The cost: small inventories without internal comps will get weak recommendations. Mitigation: if the comp set has fewer than ~3 matches, the advisor downgrades confidence and explicitly flags "not enough internal comps" rather than hallucinating.

Stage 2 expansion adds the **eBay Browse API** (`/buy/browse/v1/item_summary/search`) to pull external sold/active comps for the same category. Adds an HTTP dependency; benefits: works on novel categories, captures market price drift faster than internal data alone.

### Cost discipline

Two safeguards keep per-batch cost predictable for someone on a tight budget:

1. **Per-task model selection.** The `advise-aging-stock` task defaults to the `recommended` model (lower-cost) — the reasoning here is structurally simple: "compare these comps, pick an action class, fill the params." The `primary` (highest-quality) variant is available as an opt-in for when the recommended model's outputs feel weak; the `manual` slot lets the operator pin a specific model. Phase 0's cost UI surfaces per-task spend so Ham can see whether `advise-aging-stock` is consuming his budget faster than expected.
2. **Operator-triggered batches with up-front cost estimate.** The "Advise all (N)" header button shows the projected token cost (= N × avg-tokens-per-call × current-model rate) before kicking off, and a per-row progress indicator while running. No background, always-on advisor.

### Prompt output schema

Strict JSON. Top-level fields: `action_class` (enum from the five above), `suggested_changes` (object whose shape depends on `action_class`), `reasoning` (string, one-paragraph), `confidence` (float 0.0–1.0), `comps_used` (list of `sale_id` references the model cited; for traceability). Validation: schema mismatch ⇒ advisory marked `failed`, surfaced to operator. No silent fallback to free-form text.

### Acceptance criteria for stage 1

- For 10 manually selected stuck listings (mix of categories), the advisor produces a coherent recommendation each time. "Coherent" = the operator agrees the action class is plausible (≥ 8/10) and the reasoning is grounded in observable data, not hallucinated.
- Cost per advisory < $0.05 in the recommended model.
- p95 latency per advisory < 8 seconds.
- Schema validation success rate ≥ 95%.

## Public Contract

- **Lara task**: `advise-aging-stock` registered alongside other tasks. Inherits per-task model selection, cost surface, backup-provider routing.
- **`AgingStockAdvisorService`** in `app/Modules/Commerce/Sales/Services/` (or a new `Lara/AdvisorServices/` namespace if the family grows). Methods: `adviseListing(Listing $listing): LaraAdvisory`, `adviseListings(Collection $listings): Collection<LaraAdvisory>`. Both throw `AdvisoryGenerationException` on irrecoverable model errors, persist the advisory regardless on success or schema failure (with `status = failed` on the latter so the operator sees what happened).
- **`LaraAdvisory` model** + `lara_advisories` migration.
- **Repository / read methods** on `SalesInsightsService` to provide comparable-sold inputs without leaking query shape into the advisor service.
- **Workbench UI**: extends `Extensions\Ham\AutoParts\Livewire\Insights\ListedWithoutSale` with the inline advisory affordances, OR a new sibling `ListedWithoutSale` (kept as the data-only view) plus a `ListedWithoutSaleAdvisor` that adds the Lara surface. Decision deferred until UI design lands.

## Phases

### Phase 1 — Lara task + service, no UI, no storage

- [ ] Register `advise-aging-stock` as a Lara simple task with prompt, default model selection, and the strict output schema.
- [ ] Implement `AgingStockAdvisorService::adviseListing(Listing): LaraAdvisory` — assembles input from `Listing` + linked `Item` + internal sold-comps; calls the Lara task; validates output.
- [ ] Implement comparable-sold lookup (e.g. `SalesInsightsService::comparableSoldItems(Listing): Collection`) — leftJoin sales→items→categories filtered to the same category + currency + last-12-months.
- [ ] Pest tests with mocked Lara responses covering: schema-valid output, schema-invalid output (advisory `failed`), comp-set too small (downgraded confidence), category mismatch (no comps), single-action vocabulary (each of the five classes round-trips).

### Phase 2 — Storage + repository

- [ ] `lara_advisories` table migration.
- [ ] `LaraAdvisory` model with relationship to `Listing` and to the `User` who approved.
- [ ] Repository pattern: `LaraAdvisoryRepository` with `latest(listing)`, `pending()`, `markApproved`, `markRejected`, `markExecuted`, `recordOutcome`.
- [ ] Tests: idempotency (re-running advisor for same listing creates a *new* row, doesn't replace prior history), status transitions, polymorphic-free relationship integrity.

### Phase 3 — Operator surface (advisory-only)

- [ ] Workbench UI extension on the existing "Listed without a sale" page (or sibling — see Public Contract).
- [ ] "💡 Lara's take" per-row action: triggers `adviseListing`, persists, displays the advisory card inline.
- [ ] "Advise all (N)" header action: per-row progress, up-front cost estimate, batch advisory generation.
- [ ] Approve / Reject / Edit inline — record disposition. Approve does *not* call eBay.
- [ ] Tests: row-level approval flow, batch flow, cost-estimate display, schema-failure surface.

### Phase 4 — Phase 6 integration (post-write-path)

- [ ] Wire approve → corresponding `MarketplaceChannel::reviseListing` / `endListing` call.
- [ ] Persist `executed_at`.
- [ ] Schedule outcome check: 30 days after `executed_at`, set `outcome_class` based on listing state.
- [ ] Tests: each `action_class` round-trips through the right write-path verb.

### Phase 5 — Loop closure

- [ ] Admin dashboard surfacing acceptance / execute / outcome rates by `action_class`.
- [ ] Optional: prompt iteration based on observed outcomes (manual; the dashboard is the input, not an autonomous loop).
- [ ] Optional: external Browse API comps (stage 2 of comp data) once internal-only data shows weakness on novel categories.

## Open Questions

1. **One advisor or many?** The plan describes a single `advise-aging-stock` task. Should buyer-message drafting (the second high-leverage gap I identified) ride on the same `LaraAdvisory` table with a different `task` value, or get its own table? Lean toward one table — the lifecycle (generated → approved → executed → outcome) is the same shape for any per-subject advisory.
2. **Approval permission model.** Who in a multi-user company can approve an advisory? Probably the same role that gates Phase 7 insights pages (`commerce.marketplace.list` is a temporary fit; if a `commerce.sales.advise` capability is right, that's a small authz config slice). Defer until Phase 3 UI lands.
3. **Re-running the advisor on the same listing.** Cooldown? If an operator generated an advisory yesterday and the listing is still aging, should "Advise all" re-spend tokens on it? Default: yes if the previous advisory was rejected or expired, no if pending or approved (still actionable).
4. **What does the prompt actually say?** Stage 1 ships with a hand-written prompt. Drafting it lives in Phase 1 as the most-bespoke piece of work and merits its own design pass before code commits to wording. Consider adding `docs/plans/ham/03b-aging-stock-advisor-prompt.md` for that drafting if it gets long.
5. **Cost-cap UX.** Phase 0's AI cost UI is not yet built. Until it lands, the "Advise all (N)" button's cost estimate is informational only — there's no enforced cap. Mitigation: small N defaults (e.g. cap at 50 in one run) to bound the worst-case spend.
6. **Outcome attribution at scale.** A listing might sell after multiple advisories of different action classes are applied, or after non-Lara intervention from Ham. Stage 1 attributes the outcome to the most recent `executed` advisory; later phases may want to weight or split.

## Interaction with the master plan

- This advisor is a parallel track to Phase 6 (eBay write path); it does not block on it but composes with it.
- The advisor's input data (`comparableSoldItems`) is a small extension to `SalesInsightsService` that other future tooling will reuse — Phase 6 dry-run/diff preview, in particular, can show "for this revision, here's what comps suggest."
- The advisor stays in `app/Modules/Commerce/Sales/` (framework, generic) with the Ham extension hosting the Workbench surface — same boundary discipline as the Phase 7 insights split (framework queries vs. Ham layouts).
