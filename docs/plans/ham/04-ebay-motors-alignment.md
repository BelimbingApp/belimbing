# ham/04-ebay-motors-alignment

**Status:** In Progress; Phase 0 complete; Phase 1 fitment capture started
**Last Updated:** 2026-05-20
**Sources:**
- User context: Ham operates from California and sells through the eBay store `rpm*parts`; Belimbing must help him align with US eBay Motors discovery, not only generic marketplace listing.
- `docs/plans/ham/01-ebay-car-parts-operations.md` — Ham operating model, Belimbing Commerce / Ham extension split, eBay read/write integration direction.
- `docs/plans/ham/02-ebay-sell-api-research.md` — eBay Sell API onboarding and capability research.
- eBay Seller Center Motors Parts & Accessories guidance: fitment/compatibility, item specifics, titles, photos, returns, and buyer confidence.
- eBay developer docs: Taxonomy API category aspects and compatibility properties; Metadata API automotive parts compatibility and item condition policies; eBay Motors Parts & Accessories compatibility guidance.
- Oracle review, 2026-05-20 — sharpened gaps between Ham's store, eBay Motors best practice, and Belimbing's missing Motors implementation surfaces.
- Example Ham listing supplied by user: eBay item `236800746699`, ePID `1122066940`, a BMW 135i Brembo rear brake caliper pair. Direct page extraction was blocked from this environment, but eBay product/search snippets show catalog-backed specifics such as part numbers `34206785237` / `34206785238`, type `Brake Caliper`, finish `Powder-Coated`, color `Silver`, piston quantity `2`, and `Performance Part: No`.
**Agents:** Amp/claude-sonnet-4.5

## Problem Essence

Ham is not only selling items on eBay; he is selling auto parts into the eBay Motors buyer journey. Buyers use vehicle-driven discovery such as year, make, model, trim, engine, and “My Garage” compatibility, so listings that only have good titles and photos still miss a major discovery and confidence surface.

Belimbing needs a phased path from Ham’s current generic inventory records to eBay Motors-aligned listings that are easier to find, more trustworthy to buyers, and less likely to create avoidable questions or returns. The biggest implementation gap is not Ham-specific copy polish; it is that Belimbing must model eBay Motors as a distinct listing system with metadata auth, Motors category policy, compatibility data, listing drafts, publish-safe media, seller policies, inventory locations, and explicit ownership of listings it creates.

## Desired Outcome

Belimbing should make Ham’s listings align with eBay Motors Parts & Accessories expectations for the US market:

- Structured vehicle fitment / compatibility is captured and maintained in Belimbing, not buried only in titles or descriptions.
- eBay Motors category, item-specific, condition, and compatibility requirements are visible before publish.
- Ham can see whether an item is ready for eBay Motors, why it is not ready, and which field to fix next.
- Titles, descriptions, identifiers, photos, warranty/return notes, and policy choices reinforce buyer confidence without keyword stuffing.
- The framework stays general-purpose: eBay Motors-specific defaults and vocabulary belong in the Ham auto-parts extension, while reusable marketplace, catalog, inventory, and readiness mechanisms belong in Commerce.

Full alignment means Belimbing can publish or revise an eBay Motors listing with the correct category, required/recommended aspects, structured compatibility where the category supports it, seller policies, photos, identifiers, and confidence signals — with Ham reviewing a clear readiness checklist before the listing leaves Belimbing.

The first implementation priority is therefore **Belimbing ↔ eBay Motors alignment**. Gaps between Ham's current shop and eBay Motors best practice are useful audit input, but the product work should first build the generic mechanisms that make eBay Motors readiness and publishing reliable.

## Top-Level Components

1. **Commerce Catalog** — keeps the generic template/category/attribute mechanism. It should host reusable item attributes and let the Ham extension seed auto-parts vocabulary such as brand, manufacturer part number, OEM number, interchange number, side, placement, material, tested status, and condition grade.
2. **Commerce Inventory** — remains the seller’s stock/work record: SKU, quantity, cost, target price, storage location, photos, notes, catalog assignment, and lifecycle. It should not become eBay Motors-specific.
3. **Marketplace host** — owns channel-neutral listing readiness, listing drafts, policy linkage, sync status, and publish/revise lifecycle.
4. **eBay channel provider** — owns generic eBay API calls, OAuth scopes, marketplace IDs supplied by callers, category/aspect metadata sync, policy lookup, and publish/revise payload shaping. It must not hard-code eBay Motors or Ham assumptions.
5. **Ham auto-parts extension** — owns Ham’s eBay Motors category mappings, US Motors taxonomy identifiers such as `EBAY_MOTORS_US` and category tree `100`, auto-parts attribute seeds, default listing guidance, and any Ham-specific views or reports.
6. **Fitment model** — a new Commerce capability for vehicle compatibility data, reusable by auto-parts sellers beyond Ham but not forced onto unrelated sellers. It should represent normalized eBay-compatible name/value properties for one compatible vehicle/application, universal-fit cases, provenance, confidence, category context, and room for category-specific compatibility modes.
7. **eBay metadata cache** — eBay-channel-owned storage for category metadata, category aspects, compatibility policies, condition policies, and refresh/staleness state. This is separate from seller listing sync because it is application metadata, not Ham's account data; the Ham extension supplies the Motors marketplace/category-tree identifiers when using it.
8. **Marketplace listing draft/readiness** — a durable local draft object that records selected marketplace/category, aspect mapping results, selected policies and inventory location, photo set, readiness snapshot, metadata version checked, publish/revise intent, and last failure summary. Synced external listing rows are not enough for this responsibility.
9. **eBay catalog/ePID bridge** — a narrow eBay-channel capability for recognizing when a listing or comparable product is backed by an eBay catalog product (`epid`) and using that product's public specifics as suggested aspects, identifiers, and confidence hints without treating eBay catalog data as automatically true for Ham's used item.

## Design Decisions

- **Align to US eBay Motors first.** Ham is in California, so the target marketplace behavior is US eBay Motors, especially `EBAY_MOTORS_US` and US Motors category tree behavior. International marketplace support can be added later, but Malaysia-oriented defaults must not drive this workflow.
- **Keep Motors behavior in Ham; extract generic eBay infrastructure.** The reusable Commerce/eBay layer should provide application tokens, metadata caching, listing drafts, aspect mapping contracts, and publish primitives that work for any eBay seller/category. Motors-specific identifiers, category defaults, compatibility vocabulary, and Ham operating guidance belong in the Ham auto-parts extension.
- **Treat eBay Motors as three integration surfaces.** Belimbing must separate seller-authenticated listing APIs, application-authenticated metadata APIs, and category-scoped compatibility data. Inventory/offer listing calls use the seller OAuth context and the normal US listing marketplace, while Motors metadata uses the Motors marketplace context and Motors category tree.
- **Be explicit about eBay identifiers.** For Ham's first build, offer/listing work targets the US marketplace (`EBAY_US`), Motors metadata targets `EBAY_MOTORS_US`, and the US Motors taxonomy tree is category tree `100`. These values should be declared by the Ham extension and passed into generic eBay services, not buried as Commerce defaults.
- **Fitment is the center of eBay Motors alignment.** eBay Motors discovery is vehicle-led. Belimbing should treat fitment as structured data, not title text, because structured compatibility improves buyer discovery, buyer confidence, and listing quality.
- **Compatibility publishing is not just listing payload shaping.** The eBay publish flow must explicitly write product compatibility through the current compatibility API surface using compatibility properties. Legacy/deprecated compatibility shapes must not become the long-term Belimbing contract.
- **Keep titles useful but not overloaded.** Titles should prioritize brand, part type, part number, side/placement, and meaningful variants. Year/make/model ranges should not be the only fitment mechanism once structured compatibility is available.
- **Use eBay metadata instead of hard-coding rules.** Category required aspects, recommended aspects, item condition policies, and compatibility support should come from eBay Taxonomy/Metadata APIs where possible. Extension seeds can provide useful defaults, but eBay policy remains the source of truth.
- **Support category differences explicitly.** Most auto parts use vehicle compatibility, but some categories such as tires and wheels may use specification-based compatibility. The model and readiness checks should not assume every eBay Motors category uses the same compatibility shape.
- **Identifiers are high priority.** Manufacturer part number, OEM number, interchange number, brand, and related identifiers help eBay search, buyer confidence, and returns avoidance. They can start as catalog attributes, then move to a deeper identifier model only if repeated UX or matching rules demand it.
- **eBay catalog/ePID data is a suggestion source, not ownership.** The example Ham listing includes an `epid` and eBay's “About this item” product specifics. Belimbing should be able to import and compare those specifics, but Ham's actual used part facts, condition, photos, included pair/single quantity, side/placement, and damage notes still need seller confirmation.
- **Condition remains catalog vocabulary, not a first-class inventory column.** Belimbing already decided condition/grading belongs in catalog attributes. eBay condition policy mapping should translate from catalog attributes and eBay metadata at listing time.
- **Aspect mapping is its own engine.** Belimbing attributes are not automatically eBay aspects. The eBay channel needs per-category mapping, value normalization, enum validation, and required/recommended checks.
- **Readiness before publishing.** Belimbing should prevent blind publishing. Operators should see missing category, missing required aspects, missing identifiers, weak photo coverage, missing fitment/universal-fit status, policy gaps, and eBay account setup gaps before attempting publish.
- **Publish blockers must be first-class readiness inputs.** eBay Motors publishing requires more than item facts: public HTTPS image URLs, selected seller policies, policy compliance, a merchant inventory location, quantity/price, and account setup must all be visible before publish.
- **Belimbing-published eBay listings are Belimbing-managed.** Listings created through eBay Inventory APIs should be revised through Belimbing so the local draft/readiness state and eBay state do not diverge. The UI should make this ownership clear and surface detected drift.
- **Confidence signals matter.** eBay Motors alignment is not only API compliance. The listing UX should encourage enough photos, visible defects, tested/untested state, warranty/return clarity, accurate condition notes, and truthful compatibility.

## Public Contract

- A sellable inventory item may have zero, one, or many fitment entries. Each entry stores normalized eBay-compatible compatibility properties for one compatible vehicle/application, plus source, confidence, operator notes, and category context.
- Legacy flat Ham fitment attributes such as year/make/model/trim/engine are transitional bootstrap fields. Once the fitment model lands, they must not remain the canonical eBay Motors publish source.
- An item can be marked as universal fit only when the seller intentionally confirms that no vehicle-specific compatibility applies. Universal fit is a listing claim, not a default fallback for missing data.
- eBay Motors readiness is computed from Belimbing data plus eBay metadata for the selected marketplace and category. It returns actionable gaps, not only pass/fail.
- A marketplace listing draft is the durable local contract for publishing. It records the selected eBay category, mapped aspects, policy and location selections, chosen photo set, readiness snapshot, metadata version checked, and publish/revise status.
- The eBay channel publishes Motors listings as a sequence of explicit operations: inventory item upsert, product compatibility upsert when applicable, offer create/update, and offer publish/withdraw. Failures from each operation are recorded in the integration exchange and summarized on the draft.
- Publish-ready media means eBay-accessible HTTPS image URLs or another eBay-supported image handoff. Internal signed Belimbing media URLs are not assumed to be publish-safe.
- eBay policy readiness validates policy suitability, not just the presence of policy IDs. Return policy, fulfillment policy, payment policy, and merchant location must be checked against the selected category/marketplace requirements where eBay exposes enough metadata.
- Imported eBay product/catalog specifics can prefill or suggest aspects only when their source is visible. The operator must be able to accept, override, or reject catalog-derived values before Belimbing publishes or revises a listing.
- Ham-specific category mappings and attribute seeds must be removable with the Ham extension; the Commerce and eBay channel mechanisms remain usable by another auto-parts seller with different defaults.
- Belimbing should never invent compatibility claims. If fitment comes from Ham, an imported eBay listing, or later a catalog/reference dataset, the source should be distinguishable enough for the UI to communicate confidence.

## Phases

### Phase 0 — Motors metadata, auth, and listing foundation

Goal: give Belimbing the platform primitives needed to reason about eBay Motors before building operator-facing fitment workflows.

- [x] Add eBay client-credentials/app-token support for Taxonomy and Metadata API calls that do not use Ham's seller OAuth token. {Amp/claude-sonnet-4.5}
- [x] Declare Ham's eBay Motors identifiers in the Ham extension: listing marketplace `EBAY_US`, Motors metadata marketplace `EBAY_MOTORS_US`, and Motors category tree `100`. {Amp/claude-sonnet-4.5}
- [x] Add cached marketplace metadata storage and a first generic eBay category-aspects pull/cache path that accepts marketplace and category-tree identifiers from the caller. {Amp/claude-sonnet-4.5}
- [x] Extend cached metadata coverage to compatibility-enabled categories, automotive parts compatibility policy, item condition policy, compatibility property values, and richer metadata refresh/staleness state. {Amp/claude-sonnet-4.5}
- [x] Add policy/location import support so Belimbing can present selectable payment, return, fulfillment, and merchant-location choices instead of relying only on manually typed IDs. {Amp/claude-sonnet-4.5}
- [x] Add a durable marketplace listing draft/readiness model separate from synced external listing snapshots. {Amp/claude-sonnet-4.5}
- [x] Define the aspect mapping contract: internal attribute, eBay aspect name, category scope, value normalization, enum validation, required/recommended status, and mapping confidence. {Amp/claude-sonnet-4.5}
- [x] Define how Belimbing stores imported eBay catalog/product references such as `epid` and product-specific facts so they can suggest aspects without becoming unreviewed truth. {Amp/claude-sonnet-4.5}

### Phase 1 — Fitment foundation and operator capture

Goal: make vehicle compatibility a first-class data concept Belimbing can capture before publishing exists.

- [x] Add a fitment model for auto-parts compatibility that stores normalized compatibility property name/value sets, category context, source, confidence, operator notes, and optional display labels such as year/make/model/trim/engine. {Amp/claude-sonnet-4.5}
- [x] Support item-level fitment on Commerce inventory items, with a clear universal-fit path that requires explicit operator confirmation. {Amp/claude-sonnet-4.5}
- [x] Add operator UI for adding, bulk-entering, deleting, and reviewing fitment entries from an inventory item. {Amp/claude-sonnet-4.5}
- [ ] Add in-place editing for existing fitment entries once the first operator flow proves the field shape.
- [ ] Treat existing flat Ham fitment attributes as import/bootstrap inputs and provide a path to convert them into canonical fitment entries.
- [ ] Add early fitment-set reuse or batch-apply tools for repeated vehicle/application families, because used auto-parts sellers often list several parts from the same donor vehicle.
- [ ] Seed Ham auto-parts attributes for identifiers and confidence details: brand, manufacturer part number, OEM number, interchange number, placement/side, tested status, condition grade, and defect notes.
- [ ] Show fitment coverage on inventory item detail and list surfaces so Ham can tell which parts are ready for Motors work.

### Phase 2 — eBay Motors metadata sync

Goal: make Belimbing aware of what eBay expects for the selected Motors category.

- [ ] Sync or cache eBay Motors category data for the US marketplace, starting from the relevant Motors category tree.
- [ ] Fetch required and recommended item specifics for selected categories through eBay Taxonomy APIs.
- [ ] Fetch automotive parts compatibility policies and item condition policies through eBay Metadata APIs, including compatibility limits and policy flags where available.
- [ ] Store enough metadata to compute readiness offline for normal UI use, while keeping refresh/retry behavior explicit.
- [ ] Add a mapping surface between Belimbing catalog categories/templates and eBay Motors categories.
- [ ] Add policy and location selection defaults for Ham once eBay account data has been imported.
- [ ] Import catalog/ePID-backed product specifics where eBay exposes them for a listing or comparable product, then map those specifics into the same aspect-readiness pipeline as seller-entered attributes.

### Phase 3 — Listing readiness and confidence checklist

Goal: let Ham know what is missing before any publish/revise call.

- [ ] Add an eBay Motors readiness panel backed by the durable listing draft, not by transient form state.
- [ ] Check for eBay category, required aspects, recommended identifiers, photos, fitment or universal-fit status, condition mapping, seller policies, price, quantity, and description readiness.
- [ ] Check publish blockers: merchant location, policy suitability, public image URL availability, package/shipping facts when required, account connection state, and stale metadata.
- [ ] Map Belimbing attributes to eBay aspects with value normalization and enum validation before marking a draft ready.
- [ ] Show aspect source and confidence: seller-entered, imported from Ham's existing listing, eBay catalog/ePID suggestion, AI suggestion, or default.
- [ ] Explain each gap in operator language and link directly to the field or setup page that fixes it.
- [ ] Add title guidance that emphasizes part type, brand, part numbers, placement, and meaningful variants without using title text as the only compatibility record.
- [ ] Add photo/confidence guidance for used parts: multiple angles, part number close-up when available, defects, connectors/mount points, and tested/untested evidence.

### Phase 4 — eBay publish/revise with structured compatibility

Goal: publish and revise eBay Motors listings using official eBay APIs and the data Belimbing has validated.

- [ ] Extend eBay listing draft payloads to include selected Motors category, required aspects, item condition mapping, publish-safe photos, policies, merchant location, SKU, quantity, price, and structured fitment.
- [ ] Implement publish as explicit eBay operations: inventory item upsert, product compatibility upsert when applicable, offer create/update, and offer publish/withdraw.
- [ ] Publish a new eBay Motors listing from a ready Belimbing item through official eBay APIs.
- [ ] Revise an existing eBay listing when fitment, item specifics, photos, price, quantity, title, or description changes.
- [ ] Mark Belimbing-created eBay listings as Belimbing-managed and surface drift if the eBay listing later changes outside Belimbing.
- [ ] Preserve eBay operation results and failures in the integration exchange so support can diagnose API issues without exposing raw errors as the primary operator experience.
- [ ] Keep imported/synced existing eBay listings reconcilable with Belimbing items and fitment records.

### Phase 5 — Import, cleanup, and scale Ham’s existing store

Goal: move Ham’s existing `rpm*parts` catalog toward full eBay Motors alignment without requiring one-by-one manual rewrite.

- [ ] Import current eBay listings and detect which ones already have structured compatibility, useful identifiers, complete item specifics, policy/location coverage, and Inventory-API management compatibility.
- [ ] Detect legacy listing patterns that may need migrate/relist handling before Belimbing can safely revise them through the Inventory API path.
- [ ] Create a cleanup queue ranked by likely impact: missing fitment, missing part numbers, weak titles, weak photos, stale/aging listings, and high-value items.
- [ ] Suggest fitment and item-specific drafts from existing title/description text, photos, and imported eBay data, but require Ham approval before publishing compatibility claims.
- [ ] Compare Ham's imported item specifics against eBay catalog/ePID specifics and other eBay metadata, highlighting missing or conflicting facts such as brand, part numbers, type, finish, placement, piston count, and performance/universal-fit claims.
- [ ] Add batch tools for repeated fitment patterns when multiple items share the same vehicle/application family.
- [ ] Track before/after listing quality status so Ham can see progress across his store.

### Phase 6 — Trust, performance, and feedback loops

Goal: use sales, returns, and buyer questions to improve listing quality over time.

- [ ] Track eBay Motors readiness status alongside listing performance, sale timing, and aging inventory.
- [ ] Surface listings with buyer questions or returns that suggest fitment ambiguity or weak condition disclosure.
- [ ] Add reminders for warranty/return clarity and defect disclosure on used parts.
- [ ] Use sold and unsold history to recommend better title phrasing, pricing review, photo improvements, or fitment cleanup.
- [ ] Revisit whether identifiers need a deeper model than catalog attributes after real matching and cleanup workflows exist.

## Risks and Caveats

- **Wrong fitment is worse than no fitment.** Incorrect compatibility can increase returns and damage buyer trust. Belimbing should make source and confidence visible and require review before publishing claims.
- **Marketplace support differs.** eBay automotive compatibility support varies by marketplace. The first target is US eBay Motors for Ham; other marketplaces need explicit policy checks before reuse.
- **Some categories are not vehicle-fitment categories.** Tires, wheels, universal accessories, tools, and fluids may need different compatibility or specification treatment.
- **eBay metadata can change.** Cached category/aspect/policy metadata needs refresh behavior and stale-data visibility.
- **Ham’s existing listings may be inconsistent.** Imported titles/descriptions can help draft cleanup work, but they should not be treated as authoritative compatibility evidence without review.
