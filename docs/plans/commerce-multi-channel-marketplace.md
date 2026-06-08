# commerce-multi-channel-marketplace

**Status:** Phase A built; Phase B next.
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
- **Overselling guard is the keystone.** Any inventory quantity change (a pulled sale on any channel, or a manual adjustment) reconciles available quantity across the item's active listings; quantity 0 → end the listings everywhere.
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

### Phase B — Availability sync (the overselling keystone)

Affected pages: `/commerce/inventory/items/{item}` (available-qty reflects inventory); channel listings on eBay.
Goal: changing an item's quantity (or a pulled sale) updates available quantity on every active channel listing; a one-off that sells anywhere is ended everywhere.

- [ ] Define the projection: channel available quantity = inventory `quantity_on_hand` minus reserved/in-flight (one-offs → {0,1}).
- [ ] On inventory quantity change, reconcile available quantity across the item's active listings (push availability per channel); quantity 0 → end listings on all channels.
- [ ] Make it idempotent and drift-aware (do not clobber a listing the operator intentionally diverged; surface a conflict instead).
- [ ] Tests: sale on channel A ends/zeros the item on channels B/C; manual restock re-enables.

### Phase C — Order pull updates inventory

Affected pages: per-channel marketplace pages (pull); `/commerce/inventory/items/{item}`.
Goal: pulling orders from a channel decrements inventory, which then triggers Phase B sync.

- [ ] Wire channel order pull → inventory `quantity_on_hand` decrement for sold line items (currently the order mapper reads quantity but does not adjust inventory).
- [ ] Decrement triggers availability sync (Phase B) so other channels reflect the sale.
- [ ] Tests: a pulled eBay sale reduces inventory and propagates.

### Phase D — Prove genericity with a second channel

Affected pages: a new per-channel marketplace page; the item Channels panel (gains a row).
Goal: adding Shopee (or Lazada) requires only implementing `MarketplaceChannel` + registering a provider; no changes to inventory, availability sync, push orchestrator, or the Channels panel.

- [ ] Write a channel-conformance checklist/contract test every channel must pass.
- [ ] Implement a second channel adapter (Shopee) — or at minimum a stub that exercises the registry/UI generically — to confirm nothing is eBay-coupled.

### Phase E — Channel-agnostic marketplace surface

Affected pages: `/commerce/marketplace/*`.
Goal: the marketplace area scales to N channels (per-channel pages share one component pattern; an optional cross-channel overview), instead of bespoke eBay screens.

- [ ] Generalise the eBay marketplace page pattern so each channel reuses it (Pull, Listings, Ready to list) via the registry.
- [ ] Optional cross-channel overview (one item's presence across all channels; channel health at a glance).

## Out of Scope / Later

- Per-channel category/aspect mapping UIs beyond what each channel needs (eBay's exists).
- Pricing strategy per channel (channel-specific pricing rules) — note as a future component; the model allows it (price can be per-listing) but no UI now.
- Backgrounding/queueing large pulls and availability fan-out at scale (low-thousands is fine synchronously short-term; revisit for performance).
