# commerce-multi-channel-marketplace

**Status:** Phases A–E built and tested, with one carve-out: Phase C's order ingestion is functional via the incremental, idempotent **cron poll** (the inbound loop works end to end today); the **modern Notification API webhook** (real-time push) is the remaining live-eBay integration — signature crypto, subscription registration, CSRF-exempt public endpoint, and multi-tenant account→company mapping all need live eBay to build and verify safely. Phase D (conformance) and Phase E (per-listing modal) done; the optional cross-channel overview is deferred.
**Last Updated:** 2026-06-08
**Sources:** `extensions/ham/docs/plans/ham-ebay-sandbox-live-validation.md` (single-channel eBay publish proven end-to-end); the existing Marketplace channel abstraction (`MarketplaceChannel`, `MarketplaceChannelRegistry`, `MarketplaceChannelProvider`); the inventory item page (`commerce/inventory/items/{item}`); design discussion 2026-06-08.
**Agents:** claude/claude-opus-4-8; amp/gpt-5.1-codex

## Problem Essence

Belimbing must sell one inventory item across several marketplaces (eBay today; Shopee, Lazada, … next) without overselling, with **inventory as the single source of truth**. Today the marketplace and item UI are eBay-specific, there is no cross-channel availability sync, and there is no UI to publish/revise at all.

## Desired Outcome

- **One item, many channels.** From the item you can see and manage where it is listed, and list it on more channels.
- **No overselling.** Inventory owns available quantity; a sale on any channel decrements inventory and propagates availability to every other channel. For one-off items (Ham's used parts, qty 1) a sale ends the listing everywhere.
- **Pull / Push as the mental model.** Pull (per channel) imports listings + orders and updates inventory; Push (per item → all or selected channels) publishes/revises/ends.
- **A legible inventory item page**, reorganised, with a channel-agnostic **Channels** panel where push lives.
- **Adding a channel is cheap:** implement the channel interface + register a provider. eBay is simply N=1.

## Top-Level Components

- **Inventory Item** — the availability source of truth (`quantity_on_hand`), channel-agnostic. (Exists.)
- **Channel + adapter** — `MarketplaceChannel` (pull/push surface) resolved via `MarketplaceChannelRegistry`, contributed by a `MarketplaceChannelProvider`. eBay is the first. (Exists.)
- **Channel Listing** — an item's presence on one channel; `Listing` keys on `item_id` + `channel`, so one item already fans out to many channels. (Exists.)
- **Availability sync service** — projects inventory quantity onto every active channel listing; prevents overselling. (New.)
- **Multi-channel push orchestrator** — `push(item, channels[])` fan-out over the per-channel `createListing`/`reviseListing`/`endListing`. (Exists for Phase A; wraps existing per-channel ops.)
- **Order → inventory ingestion** — channel order pull decrements inventory. (New / extend the order pull.)
- **UI** — item **Channels** panel + redesigned item page (Phase A built); per-channel marketplace pages for pull/health (exist for eBay).

## Design Decisions

- **Inventory is the SSOT for availability.** Each channel listing's available quantity is a *projection* of `quantity_on_hand` (minus reserved/in-flight). One-off items project to {0, 1}. Channels never independently own quantity.
- **Channels are git "remotes."** Pull = `fetch` semantics (import + flag drift, never clobber local edits — already how eBay pull behaves). Push = deliberate, **per-item**, readiness-gated, and on the live store **human-approved**; it fans out to **all or selected** channels. There is no blind "push everything."
- **Overselling guard is the keystone.** Any inventory quantity change (a pulled sale on any channel, or a manual adjustment) reconciles available quantity across the item's active listings; quantity 0 → end the listings everywhere. **Availability sync runs automatically** — it is the safety mechanism, distinct from deliberate content publishing (price/title/new listings) which stays human-gated. It touches **only Belimbing-managed, in-sync listings**; imported or drifted listings are reported, never clobbered (the operator resolves those). (`MarketplaceAvailabilitySyncService`, Phase B.)
- **Sync strategy = event-driven + a cron backstop, via eBay's modern Notification API.** Use the modern Notification API (`commerce/notification`), not legacy Platform Notifications (deprecation risk). An order notification is a *trigger*, not the data: on a signature-verified webhook, fetch the authoritative order and ingest. A configurable Laravel-scheduler poll runs as the backstop (webhooks get missed). Order pull is **incremental** (watermark + `getOrders` date filter, paginated), **idempotent** (upsert by order/line id), and **decrements inventory once on first ingest, never on re-pull**. (Phase C.)
- **Build channel-generic from day one.** Nothing new may hard-code eBay. eBay stays the reference implementation; a second channel (Shopee) is added to prove the abstraction holds.
- **Push lives on the item, not on a channel page.** The current per-item, per-channel readiness (`EbayListingReadinessService` → a `ListingDraft` per `(item, channel)`) becomes one row of an item **Channels** panel. The standalone "eBay readiness" card folds into that panel.
- **Reuse, don't rebuild.** The channel interface, registry/provider, `Listing(item_id, channel)`, and the readiness/draft machinery already exist and are channel-shaped; this plan adds the cross-channel layer and the UI, not a new core.

## Public Contract

- **`MarketplaceChannel`** is the per-channel surface every channel implements: `key`, `pullListings`, `pullOrders`, `createListing(Item)`, `reviseListing(Listing)`, `endListing(Listing)`, and `refreshListingDraft(Item)`. New channels implement this and register a provider — no changes to callers.
- **Multi-channel push:** an item-level operation `push(item, channels[])` returning a per-channel result; each target must pass that channel's readiness; the live environment requires explicit operator confirmation.
- **Availability sync:** on any `quantity_on_hand` change, the item's active channel listings are reconciled to the projected available quantity (one-offs end at 0).
- **Inventory Item `quantity_on_hand`** is the single writable quantity; channel "available quantity" is derived, never authoritative.

## Phases

### Phase A — Redesign the inventory item page + add the Channels panel

The item page (`commerce/inventory/items/{item}`) is an ~880-line single scroll (Item facts → Catalog Fit → Fitment → Media → identifiers → Descriptions → eBay readiness + extension panels). Reorganise it for legibility and replace the eBay-only readiness card with a channel-agnostic **Channels** panel — which also fills the missing push UI.

Affected pages: `/commerce/inventory/items/{item}`; `/commerce/marketplace/ebay` for the pull-only channel surface.
Goal: a reviewer can scan the item in clear sections, see every channel it is (or could be) listed on, and publish/revise it to selected or all channels from one panel. The eBay channel page stays the pull/listing-health surface; push is intentionally item-scoped.

- [x] Reorganise the page into clear sections (grouping or tabs): **Details** (facts, price, status, notes), **Fitment & compatibility**, **Media**, **Identifiers/attributes**, **Listing & Channels**. Keep edit-in-place; raise status/price/channels prominence. {amp/gpt-5.1-codex}
- [x] Build a channel-agnostic **Channels** panel: one row per registered channel showing listed/not-listed, status, price, and per-channel readiness (Ready / Blocked: reason), driven by each channel's readiness service. eBay is the first row; the standalone eBay-readiness card is removed. {amp/gpt-5.1-codex}
- [x] Add **push actions** on the panel: per-row "List / Push", a multi-select "Push to selected", and "Push to all" — gated by readiness, with live-environment confirmation. (This is the per-item push orchestrator's first consumer.) {amp/gpt-5.1-codex}
- [x] Make the channels list render from the channel registry (no hard-coded eBay). {amp/gpt-5.1-codex}
- [x] Align the eBay marketplace page with the Pull/Push model: one **Pull from eBay** action imports listings and orders; the page lists synced and ready-to-list inventory while publishing remains on the item page. {amp/gpt-5.1-codex}

Evidence: implemented in `app/Modules/Commerce/Inventory/Livewire/Items/Show.php`, `app/Modules/Commerce/Inventory/Views/livewire/commerce/inventory/items/show.blade.php`, `app/Modules/Commerce/Marketplace/Services/MarketplaceListingPushService.php`, the marketplace channel contract/provider, and the eBay channel page. Focused tests: `php artisan test tests/Feature/Modules/Commerce/Inventory/ItemWorkbenchTest.php tests/Feature/Modules/Commerce/Marketplace/EbayListingReadinessServiceTest.php tests/Feature/Modules/Commerce/Marketplace/EbayMarketplaceTest.php --stop-on-failure`; browser-reviewed with Playwright on `/commerce/inventory/items/{item}`.

### Phase B — Availability sync (the overselling keystone) ✅ done 2026-06-08 (claude/claude-opus-4-8)

Affected pages: `/commerce/inventory/items/{item}` (editing quantity reconciles channels).
Goal: changing an item's quantity (or a pulled sale) updates available quantity on every active channel listing; a one-off that sells anywhere is ended everywhere.

- [x] Channel-generic `MarketplaceAvailabilitySyncService::syncItem(Item)`: available = `max(0, quantity_on_hand)` (one-offs → {0,1}); for each active listing, qty 0 → `endListing`, qty > 0 → `reviseListing`. {claude/claude-opus-4-8}
- [x] Reconcile across all of the item's active channel listings; quantity 0 ends them everywhere. {claude/claude-opus-4-8}
- [x] Drift-aware: only Belimbing-managed, in-sync listings are written; imported or drifted listings are reported (skipped), never clobbered. {claude/claude-opus-4-8}
- [x] Triggered on the item page when `quantity_on_hand` changes (`Show::saveField`), with a result/skip/failure flash. {claude/claude-opus-4-8}
- [x] Tests (channel-generic, via a fake channel): end-on-zero, revise-on-positive, skip unmanaged, skip drifted, two-channel fan-out on a one-off sale, and the item-page wiring. {claude/claude-opus-4-8}
- [ ] Reserved/in-flight quantity (beyond on-hand) — deferred; today available = on-hand. Order-pull-driven decrement is wired in Phase C.

### Phase C — Order pull updates inventory (cron poll done; webhook is the remaining live integration)

Affected pages: per-channel marketplace pages (pull); `/commerce/inventory/items/{item}`.
Goal: a sale on any channel decrements inventory (which triggers the Phase B sync), delivered fast by webhooks and reliably by a polling backstop.

- [x] **Incremental, idempotent order pull:** `getOrders` filtered by a per-company `lastmodifieddate` watermark (with a 5-min overlap), paginated; the sale ledger upserts by order/line id; the **inventory decrement happens once on first sale-line ingest** (keyed on the new `Sale` row), then `MarketplaceAvailabilitySyncService` reconciles the item's other channels. A sold-out one-off ends its sold listing and gets ended elsewhere; a multi-quantity item stays listed and is revised. {claude/claude-opus-4-8}
- [x] **Cron backstop:** `commerce:marketplace:ebay:pull --orders` scheduled (config-tunable cron, default every 15 min, `withoutOverlapping`). Makes order ingestion functional today. {claude/claude-opus-4-8}
- [x] Tests: a pulled sale decrements inventory once and never again on re-pull; availability fan-out covered by the Phase B suite. {claude/claude-opus-4-8}
- [ ] **Event-driven (modern Notification API)** — remaining live-eBay integration: a CSRF-exempt public webhook endpoint with eBay's challenge-validation handshake, per-message signature verification (fetch public key by `kid`), order-topic → targeted ingest, the mandatory marketplace-account-deletion topic, and account→company mapping, plus subscription registration. Needs live eBay + a public HTTPS endpoint to build and verify safely; the cron poll above covers correctness until then.

### Phase D — Prove genericity with a second channel ✅ done 2026-06-08 (claude/claude-opus-4-8)

Affected pages: the item Channels panel (registry-driven).
Goal: adding Shopee (or Lazada) requires only implementing `MarketplaceChannel` + registering a provider; no changes to inventory, availability sync, push orchestrator, or the Channels panel.

- [x] Channel-conformance contract test (`MarketplaceChannelConformanceTest`): every registered channel resolves to a `MarketplaceChannel`, its key matches, capabilities are booleans; eBay advertises pull/push. {claude/claude-opus-4-8}
- [x] Genericity exercised by a non-eBay adapter: the availability-sync suite drives a `FakeAvailabilityChannel` (create/revise/end) end to end with no eBay coupling. A real Shopee adapter is now a drop-in (implement the interface + register) — no second live integration built here. {claude/claude-opus-4-8}

### Phase E — Channel-agnostic marketplace surface

Affected pages: `/commerce/marketplace/*`.
Goal: the marketplace area scales to N channels (per-channel pages share one component pattern; an optional cross-channel overview), instead of bespoke eBay screens.

- [x] **Per-listing quick edit-and-push modal** on the Listings page: edit the item's title/price and push that listing (revise) without leaving the list; quantity is shown read-only (inventory-derived) so the modal cannot reopen the overselling hole. Reuses `MarketplaceListingPushService`. Tested. {claude/claude-opus-4-8}
- [ ] Single-listing **pull** (refresh one listing) — deferred; today the page-level "Pull from eBay" refreshes all listings. Needs a per-listing channel fetch method.
- [ ] **Per-channel content overrides** (a per-listing price/title that differs from the item) — deferred; the modal edits the item's canonical content, so multi-channel listings share it. (Was already out-of-scope; restated here as the path for true per-channel pricing.)
- [ ] Generalise the marketplace *page* so each channel reuses one registry-driven screen (Pull, Listings, Ready to list) instead of an eBay-named route — deferred; the page is already registry-driven for the Channels data, but the route/screen is still eBay-specific.
- [ ] Optional cross-channel overview (one item's presence across all channels; channel health at a glance) — deferred.

## Out of Scope / Later

- Per-channel category/aspect mapping UIs beyond what each channel needs (eBay's exists).
- Pricing strategy per channel (channel-specific pricing rules) — note as a future component; the model allows it (price can be per-listing) but no UI now.
- Backgrounding/queueing large pulls and availability fan-out at scale (low-thousands is fine synchronously short-term; revisit for performance).
