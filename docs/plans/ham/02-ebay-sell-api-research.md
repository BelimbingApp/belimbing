# ham/02-ebay-sell-api-research

**Agents:** claude-code/opus-4.7, Amp/claude-sonnet-4.5
**Status:** Research spike complete; informs Phase 6 of `ham/01-ebay-car-parts-operations`. Corrected for current Motors compatibility direction; no code yet.
**Last Updated:** 2026-05-20
**Sources:**
- Parent plan: `docs/plans/ham/01-ebay-car-parts-operations.md` (Phase 6).
- Companion plan: `docs/plans/ham/04-ebay-motors-alignment.md`.
- In-repo adapter (read-only path already wired): `app/Modules/Commerce/Marketplace/Ebay/EbayMarketplaceChannel.php`, `app/Modules/Commerce/Marketplace/Ebay/EbayConfiguration.php`, `app/Modules/Commerce/Marketplace/Ebay/EbayOAuthService.php`.
- eBay Sell API reference: `https://developer.ebay.com/api-docs/sell/inventory/overview.html` (Inventory API), `https://developer.ebay.com/api-docs/sell/account/overview.html` (Account API for policies), `https://developer.ebay.com/api-docs/commerce/taxonomy/overview.html` (Taxonomy API for category aspects), `https://developer.ebay.com/api-docs/sell/fulfillment/overview.html` (already in use for orders). Direct fetches from `developer.ebay.com` were blocked from this environment; primary sourcing here is the existing adapter code plus the assistant's training-data knowledge of the Sell API. Anything *labelled* below as "verify at implementation" must be re-checked against live docs before code lands.

## Problem Essence

Phase 6 ships a write path against eBay so Ham can publish, revise, and end listings from the Workbench (and from a Lara confirmation card) without leaving BLB. Before we extend the `MarketplaceChannel` contract or write an adapter, we need a grounded view of which Sell APIs we'll actually call, in what order, with what dependencies (policies, locations, aspects), and what onboarding gates the seller account has to clear.

## Desired Outcome

A clear-enough picture of the eBay write model that the Phase 6 implementation slices can be sequenced confidently: which API calls are required, which are optional, which are bulk vs single, and which are gated by account state we can't fix from inside BLB. The note also names the *concrete* unknowns that have to be resolved at implementation time, so we don't pretend the spike was more conclusive than it actually was.

## Top-Level Components

The Sell write path uses **four Sell APIs** plus **one Commerce API** (Taxonomy). The existing adapter already touches two of these for the read path; Phase 6 adds the other three.

1. **Inventory API** (`/sell/inventory/v1/*`) — owns the seller-side state of every listing.
   - `InventoryItem` — SKU-keyed product description (title, description, condition, condition descriptors, aspects, package weight + size, availability quantity). One per SKU per seller.
   - `Offer` — SKU + marketplace-keyed publishable record (categoryId, pricing, policies, format=FIXED_PRICE/AUCTION, listing duration, marketplace). Offers are *draft* until published, then they carry a `listing.listingId` that ties back to the legacy listing the buyer sees.
   - `InventoryItemGroup` — multi-variation parent (size/color matrix). Not relevant for Ham's used-parts world; each part is its own SKU. **Verify at implementation:** confirm we never accidentally publish single SKUs as group members.
   - `Location` — inventory location (warehouse) referenced by `merchantLocationKey` on each InventoryItem's availability. Required before any offer can publish. Created/managed via the **Account API** (see below), not Inventory API.
   - The read path already pages this via `/sell/inventory/v1/inventory_item?limit=100&offset=N`, so authn and pagination are solved.

2. **Account API** (`/sell/account/v1/*`) — owns the seller's reusable settings, all referenced by ID from each Offer.
   - `payment_policy`, `fulfillment_policy`, `return_policy` — three independent policy types, one or many of each per marketplace. Each has a stable `policyId`.
   - `location` (inventory location) — the warehouse Ham ships from. `merchantLocationKey` is the seller's stable identifier.
   - `program/opt_in` — opt the seller's account into selling programs (Out of Stock Control, eBay Plus, etc.). Most are not needed for Ham. **Verify at implementation:** do we need to opt the seller in to anything specific for Motors parts before publishing?
   - This API is *new for Phase 6*; the read path doesn't touch it.

3. **Taxonomy API** (`/commerce/taxonomy/v1/*`) — Commerce-namespaced (not Sell-namespaced) but relevant.
   - `getDefaultCategoryTreeId(marketplaceId)` → returns `categoryTreeId` (e.g. `0` for `EBAY_US`).
   - `getCategoryTree(categoryTreeId)` → full category hierarchy.
   - `getCategorySuggestions(categoryTreeId, q)` → suggested categories from a free-text query (useful for Lara to draft the right category from photos + title).
   - `getItemAspectsForCategory(categoryTreeId, categoryId)` → which aspects are required, recommended, or optional for a given leaf category, plus value enums where applicable.
   - `getCompatibilityProperties(categoryTreeId, categoryId)` → for parts categories: which fitment fields apply (year/make/model/trim/engine).
   - `getCompatibilityPropertyValues(...)` → enumerate valid year/make/model values to drop into Inventory Item compatibility.
   - This API is *new for Phase 6*. Lara's `describe-item` task should consult `getItemAspectsForCategory` so its drafts don't omit required aspects.

4. **Fulfillment API** (`/sell/fulfillment/v1/*`) — already wired for the order-pull side. Not part of the write path; mentioned for completeness.

5. **Browse / Trading APIs** — *not* used by the write path. eBay's older Trading API can still create listings (`AddItem`/`ReviseItem`/`EndItem`), but it's frozen for new development. Inventory API is the canonical path for Phase 6. Trading API is mentioned only because Ham's existing listings *may* live in legacy form — see the **Legacy listing migration** open question below.

## Design Decisions

### Publish flow (single SKU)

The minimum sequence to take an `Item` from BLB through to a live eBay listing:

1. **Resolve the seller's policy + location IDs once.** First publish on a fresh BLB install: pull `payment_policy`, `fulfillment_policy`, `return_policy`, and inventory `location` from the seller's account, present them to the operator for selection (Ham picks his defaults), and persist the chosen IDs as `base_settings` on the company. The Ham extension's existing `Config/auto-parts.php` already has placeholder fields for these — see the Phase 6 onboarding bullet.
2. **PUT `/sell/inventory/v1/inventory_item/{sku}`** — create or replace the InventoryItem. Body includes `availability.shipToLocationAvailability.quantity = 1` (used parts are one-off), `condition` + `conditionDescriptors`, `product.title`/`description`/`aspects`/`imageUrls`/`mpn`/`brand`. **Verify at implementation:** the `imageUrls` need to be publicly reachable HTTPS URLs; Phase 0's signed-URL stream is short-TTL and per-user, so eBay will reject it. We need either eBay's own image hosting (PUT to `/ws/api.dll` with EPS — legacy, not preferred) or upload a derivative to a public CDN. **Open question:** which image host is the right framework primitive — eBay EPS, S3-equivalent, or a new BLB primitive?
3. **POST `/sell/inventory/v1/offer`** — create the draft Offer with `sku`, `marketplaceId` (e.g., `EBAY_US`), `format = FIXED_PRICE`, `categoryId`, `merchantLocationKey`, `listingDescription`, `listingPolicies = { paymentPolicyId, fulfillmentPolicyId, returnPolicyId }`, `pricingSummary.price`, `availableQuantity = 1`. Returns an `offerId`.
4. **POST `/sell/inventory/v1/offer/{offerId}/publish`** — moves the offer from draft to live; returns `listingId`. The listing is now visible on ebay.com.
5. **Persist** — write the new `Listing` row in BLB with `external_listing_id`, `external_offer_id`, `external_sku`, `marketplace_id`, `status = ACTIVE`, `listed_at = now()`, plus the raw payload. Mark the source `Item` as `listed`.

For Ham's used-parts shape, the existing `commerce_inventory_items.sku` is per-company unique — that maps cleanly to eBay SKU which is per-seller unique.

### Revise flow

A revision can be either an InventoryItem update (title/description/aspects/condition/photos) or an Offer update (price, quantity, policies, format) or both. The Inventory API treats them independently:

- `PUT /sell/inventory/v1/inventory_item/{sku}` re-replaces the InventoryItem; the change propagates to all live offers for that SKU automatically. *No re-publish required.* **Verify at implementation:** confirm this for *category-affecting* changes — eBay may force end-and-relist if the category changes.
- `PUT /sell/inventory/v1/offer/{offerId}` re-replaces the Offer; price/quantity changes are live immediately.

### End flow

Two mechanisms, with different consequences:

- `POST /sell/inventory/v1/offer/{offerId}/withdraw` — ends the published listing on eBay but keeps the draft Offer + InventoryItem in the seller's inventory. Reusable for relist.
- `DELETE /sell/inventory/v1/offer/{offerId}` — deletes the offer entirely (only allowed if not currently published or already withdrawn).
- `DELETE /sell/inventory/v1/inventory_item/{sku}` — deletes the InventoryItem (only allowed if no offers reference it).

For Ham's "I don't want to sell this anymore" workflow, `withdrawOffer` is the right verb — the Item moves back to BLB's `ready` status (lifecycle transition spelled out in Phase 6 of the parent plan).

### Bulk operations

eBay exposes batch versions for the high-cost endpoints, all bounded at 25 SKUs per call:

- `POST /sell/inventory/v1/bulk_create_or_replace_inventory_item`
- `POST /sell/inventory/v1/bulk_create_offer`
- `POST /sell/inventory/v1/bulk_publish_offer`
- `POST /sell/inventory/v1/bulk_migrate_listing` (see Legacy migration below)

These are necessary for Phase 6's "bulk relist + bulk price change" bullet. Ham's ~2,000-listing volume means single-call publish would burn rate-limit budget unnecessarily; the adapter should default to bulk for any operation over ~5 SKUs.

### Categories and aspects (the parts-specific bit)

eBay Motors car-parts categories sit under the US category tree (`categoryTreeId = 0`, `marketplaceId = EBAY_US`) at e.g. `Motors > Parts & Accessories > Car & Truck Parts & Accessories > {sub-leaves}`. Each leaf has its own required aspect set returned by `getItemAspectsForCategory`. For typical Ham SKUs the required set looks like:

- `Brand` (e.g. "Genuine Honda" / "Aftermarket")
- `Manufacturer Part Number` (often "Does Not Apply")
- `Type` (Headlight Assembly, Tail Light, Bumper Cover, …)
- `Placement on Vehicle` (Left / Right / Front / Rear / N/A)
- Plus condition-related descriptors specific to parts (`Surface Finish`, `Color`, etc.)

**Compatibility (fitment)** is a separate concern from aspects. For categories that support compatibility, Belimbing should not treat legacy flat `fitment_year` / `fitment_make` / `fitment_model` attributes as the long-term publish shape. Current Motors alignment should model category-scoped compatibility property name/value sets and publish them through eBay's product compatibility surface using `compatibilityProperties[]` where applicable. The valid values for each property come from Taxonomy compatibility-property calls. Ham's flat fitment attributes can seed or bootstrap the canonical fitment rows, but the publish path needs a fitment model and transform that can represent multiple compatible vehicles/applications per item.

**Verify at implementation:** the precise *category leaf* IDs Ham will use. Phase 3's `Config/auto-parts.php` has placeholder `ebay_category_key` slots on each ProductTemplate; those need real category IDs filled in (or pulled live via Taxonomy API by leaf name).

### Conditions for used parts

eBay's condition IDs vary by category. For Motors > Parts & Accessories, the typical set (verify at implementation):

- `1000` — New
- `1500` — New other (see details)
- `2000` — Manufacturer refurbished
- `2500` — Seller refurbished
- `3000` — Used
- `7000` — For parts or not working

Ham's catalog uses a `condition_grade` attribute already (from Phase 3); the publish path needs a mapping table (Ham's grades → eBay's conditionId). Most of his sales are `Used` (`3000`); a smaller fraction are `For parts or not working` (`7000`).

### OAuth scopes

The existing `EbayConfiguration` reads scopes from a comma-separated `marketplace.ebay.scopes` setting — currently empty until the operator configures it. The write path requires:

- `https://api.ebay.com/oauth/api_scope/sell.inventory` — read + write inventory items, offers, locations.
- `https://api.ebay.com/oauth/api_scope/sell.account` — read + write business policies and inventory locations.
- `https://api.ebay.com/oauth/api_scope/sell.fulfillment` — already needed for the existing order-pull path.
- `https://api.ebay.com/oauth/api_scope/commerce.taxonomy` — Taxonomy API (read-only is the only mode available).

`sell.inventory.readonly` and `sell.account.readonly` are also exposed; for the write path we need the read-write versions. **Verify at implementation:** confirm these scope strings against the eBay developer console at credential-creation time.

### Sandbox vs production

The adapter's environment switch (`marketplace.ebay.environment` setting → `live` or `sandbox`) already maps to the right base URLs. Beyond URLs:

- **Tokens are separate.** Sandbox tokens are issued through `auth.sandbox.ebay.com`; they don't authenticate against `api.ebay.com`.
- **Sandbox accounts are seeded with sample test users.** eBay provides a small pool of sandbox seller accounts plus a "create test user" tool. Phase 6 dev/QA should use those, not Ham's real account.
- **Sandbox category tree may lag production.** Category IDs differ. Treat sandbox category mappings as separate from live; don't hardcode either.
- **Sandbox doesn't process payments.** Order events in sandbox are simulated.
- **Sandbox throughput limits are looser** but the response shapes are otherwise identical. **Verify at implementation:** check whether the `bulk_publish_offer` quota differs.

### Account eligibility and approval gates

Things that have to be true before any write call succeeds — none of which we can fix from inside BLB:

1. **eBay developer account approved for production keysets.** Sandbox keysets are self-serve; production keysets require manual eBay review (typically days). Ham's onboarding should kick this off early.
2. **Application Production Keyset approved for the relevant scopes.** Some scopes (especially `sell.account` write) sometimes require additional review. **Verify at implementation:** check the current state of scope-level approval gates.
3. **Seller account in good standing** — no listing limits suspension, no negative balance.
4. **Linked PayPal/managed-payment account** — eBay Managed Payments is now mandatory; the seller's payout settings must be complete.
5. **eBay Store subscription level** affects monthly listing allowances and per-listing fees. Ham at 2,000 listings is almost certainly already on a paid Store; confirm at onboarding.
6. **Above-Standard / Top-Rated seller status** affects both fees and visibility but doesn't gate API access. Mention in Ham's onboarding doc, don't block on it.
7. **Motors-specific eBay vehicle compatibility opt-in** may be required for some sub-categories. **Verify at implementation:** does Ham's account need a separate Motors program opt-in?

### Legacy listing migration

The biggest unknown for Ham specifically. He has ~2,000 listings on his account today. Two scenarios:

- **Listings already managed via Inventory API** — the read path's `/sell/inventory/v1/inventory_item` returned them, which means they're in the new model. We can revise/end them via Inventory API directly. *Most likely* if his current tooling is recent.
- **Listings created via the legacy Trading API or via eBay's seller UI before Inventory API was the default** — they don't appear in `/sell/inventory/v1/inventory_item` and have to be migrated via `POST /sell/inventory/v1/bulk_migrate_listing`. The migrate endpoint takes a list of legacy listing IDs and creates the corresponding InventoryItem + Offer rows in the new model.

**Verify at implementation:** run a sandbox or production read to see *how many* listings the existing pull surfaces vs. the seller's known total. If counts diverge, the missing listings need migration before the write path can revise/end them. This is a one-time migration — could be a Workbench action ("Migrate legacy listings") or part of the onboarding flow.

## Open Questions

These have to be resolved at implementation time, before or alongside the relevant Phase 6 slice:

1. **Image hosting.** eBay needs publicly reachable HTTPS image URLs. Phase 0's `MediaAsset` signed-stream URLs are short-TTL and per-user-authenticated — they will not work for eBay's image fetcher. Options:
   - Upload to eBay Picture Services (EPS) via the legacy `UploadSiteHostedPictures` endpoint and use the returned EPS URL.
   - Upload a public-readable derivative to S3 (or another CDN) and pass that URL.
   - Build a new BLB framework primitive for "publish-safe public image URL" that any extension can use.
   Decision deferred until image-hosting answer is needed for the first publish slice.

2. **Category resolution strategy.** Should category IDs be:
   - Hard-seeded in `extensions/ham/auto-parts/Config/auto-parts.php` (current placeholder approach), or
   - Pulled live via Taxonomy API at first-run and cached, or
   - Suggested by Lara from the `describe-item` task using `getCategorySuggestions`?
   Lean toward the third for new items, the first as a fallback default.

3. **Aspect-required validation.** Should we *pre-validate* aspect coverage (with `getItemAspectsForCategory`) before publish, or *post-validate* by handling eBay's 4xx error and surfacing it inline? The pre-validate path gives faster feedback but requires a Taxonomy round-trip per publish; the post-validate is cheaper but the error message is less polished. Probably hybrid: pre-validate the *required* aspects only; surface eBay's 4xx for everything else.

4. **Fee preview before publish.** eBay's `getListingFees` (legacy Trading API) or `Sell Fees` (newer) returns the projected fees for an offer. Worth surfacing in the Workbench's "publish to eBay" dry-run? Phase 6 plan mentions a "dry-run/diff preview before commit" — fee preview belongs there if cheap.

5. **Legacy listing count.** As above — what fraction of Ham's listings need migration before they can be revised/ended via the new model.

6. **Rate limits.** eBay Sell API rate limits are per-keyset, per-day, and vary by call. Bulk endpoints generally count as one call regardless of payload size. **Verify at implementation:** the current daily quota for `bulk_publish_offer` and whether Ham's expected churn (~50 publishes/day at peak?) fits.

7. **Compatibility data freshness.** Year/make/model values from `getCompatibilityPropertyValues` change as new model years are added. Should the Ham extension cache them per-month? Per-deploy?

## Recommended Path Forward

Phase 6 implementation order, derived from this research:

1. **Onboarding flow first** — without verified policy IDs, location keys, and OAuth scopes, no other slice can run. Build the "first publish" wizard: pull policies via Account API, present for selection, persist, refresh OAuth with write scopes.
2. **Single-SKU `createListing` second** — InventoryItem PUT → Offer POST → publish, end-to-end with the simplest happy path. Hardcode policies/location from the onboarding step. Skip aspect pre-validation for the first cut.
3. **Aspect awareness third** — wire `describe-item` to `getItemAspectsForCategory` so AI drafts include required aspects, then add publish-time pre-validation.
4. **Compatibility fourth** — map Ham's `fitment_*` attributes to eBay compatibility arrays, then wire into the InventoryItem PUT. *This is the biggest car-parts-specific lift.*
5. **`reviseListing` / `endListing` fifth** — straightforward once create works; reuses the same auth + same payload shape.
6. **Bulk relist / bulk price change sixth** — `bulk_publish_offer` over a filtered Item set, surfaced as a Workbench batch action.
7. **Legacy migration seventh** — only if the read-path count diverges from Ham's known total. Can be skipped if his account is already fully on Inventory API.

Each of slices 2–6 ships a usable feature for Ham; slice 7 is a one-time data move. The phone-channel parity bullet ("publish? from a Lara card in Telegram") is a reuse of slice 2, no new write code.

The `MarketplaceChannel` contract extension (`createListing`/`reviseListing`/`endListing`) lands at slice 2 along with the eBay implementation. Both DTO contracts and the channel-level interface should be designed in that slice together — defining one without the other risks a contract that doesn't survive its first real call.
