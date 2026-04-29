# ham/01-ebay-car-parts-operations

**Agent:** Amp, Codex
**Status:** In Progress — Phase 1 browser workbench
**Last Updated:** 2026-04-29
**Sources:**
- User context: Ham, BLB early adopter, LA-based one-person eBay car-parts seller (~2,000 active listings), undergoing cancer treatment, on a tight budget. Today uses PhotoRoom (paid SaaS) for background removal and a Windows desktop for writing/photo editing/eBay work. BLB itself is AGPLv3 and the project sponsor (Kiat) is supporting Ham pro bono.
- `docs/brief.md` — BLB vision: "serve the unserved," AI-native, DIY-enabling
- `extensions/README.md`, `docs/guides/licensee-development-guide.md` — framework vs licensee split
- `docs/plans/lara-task-models.md` — Lara's per-task model selection (`primary`/`recommended`/`manual`); new Lara tasks plug in here
- `docs/architecture/money.md` — integer minor-unit money convention and shared `Money` value object
- Existing modules: `app/Modules/Core/{AI,Company,Employee,User,Address,Geonames,Quality}`, `app/Modules/Operation/IT`

## Problem Essence

Ham can run his shop today — what he can't do is *see* it. He has no record of which parts actually sold, no time-saving help when writing descriptions, and no way to organize the business beyond what fits in his head and the eBay seller UI. He is fighting cancer; every hour BLB can give him back is hours he can spend on his health, not retyping a fitment table or scrolling sold listings to remember what moved last month.

He also pays for tools he shouldn't have to. PhotoRoom (~$10–14/month) is a recurring bill for one feature — background removal — that a modern open-weights model now does at the same or better quality for fractions of a cent per image. Subscription fatigue across PhotoRoom + an LLM API + eBay's own fees adds up quickly for a one-person shop on a tight budget.

This is exactly the population BLB exists for: a one-person SMB that off-the-shelf ERPs price out and ignore. The job is to give Ham an organizing layer, AI assist on the slow parts of his day, and one consolidated AI bill — *without* replacing tools that already work for him at no marginal cost (Windows desktop, eBay's own listing/label flow).

## Desired Outcome

A small, focused BLB install that lets Ham:

- Keep one record per part — photos, description, what it cost him, what he listed it for, and (eventually) what it sold for and when.
- Upload raw phone/camera photos and have BLB return cleaned, background-removed images automatically — letting him cancel his PhotoRoom subscription.
- Get an AI-drafted title and description from those cleaned images plus a few attribute fields, so writing a listing goes from ~10 minutes to ~1 minute of review.
- See, in one place, what he has listed, what sold this week / month / quarter, and a rough margin per part — without opening five eBay reports.
- Prefer **one** primary AI provider for everything (description + image cleanup), configured through BLB's settings UI with provider-side plan/cap controls where available, plus a pay-as-you-use backup provider for continuity if the primary is down or unsuitable for a task.
- Publish a finished part — cleaned photos, AI-drafted title and description, price, attributes, shipping policy — to eBay with one click from BLB, instead of re-entering it in eBay's seller UI. Revising and ending listings work the same way.
- Talk to Lara from his phone the way OpenClaw users talk to their assistant — Telegram first, with WhatsApp personal-account automation left as a later research path — and get real BLB work done without opening a browser. Snap a photo of a part on the bench, send it with a few words ("'08 Civic, driver's headlight, light scuff lower-left, $40 cost"), and get back a draft listing he can approve from the same chat. Ask "what sold yesterday?" or "list everything I've got over $100 still unsold past 90 days" and get a useful answer in the same channel.
- Keep using his Windows desktop, but expect most of the day's work to be automated or assisted by Lara — the desktop is a comfortable surface for review and approval, not a data-entry surface. The slow, repetitive parts (background removal, drafting copy, publishing to eBay, status checks) are Lara's job.

On the eBay integration itself, the write path is in scope and remains one of the highest-value pieces of this plan, because retyping listings into eBay's web form is a major time sink for Ham. It is also the least-known piece: the official Sell APIs are durable and sanctioned, but gated by a developer account, app registration, category/aspect compliance, Inventory/Offer modeling, and per-store policy IDs. That needs a research/onboarding spike before implementation. RPA against eBay's seller UI stays rejected — it skirts eBay's ToS, and an account suspension would be existential for Ham. The eBay adapter uses official APIs end to end: read first (so the ledger is populated before anyone publishes anything), then write — `createListing`, `reviseListing`, `endListing`, plus bulk relist and bulk price change for the long tail of unsold inventory. Phone-channel work stays earlier because Ham can realize capture/draft/status benefits sooner, while eBay write is deliberately researched before being built.

The same install — minus Ham's specific car-parts attribute set, his operator-entered account settings, and his report layouts — must be usable by the next Ham-shaped adopter.

## Framework vs Licensee Split

This is the architectural backbone of the plan. Everything below is built so the *general* capability lives in `app/Modules/...` and is reusable, while *Ham-specific* choices live in `extensions/ham/...` and never leak into core.

**General (lands in `app/Modules/Commerce/...` and `app/Modules/Core/...`):**

- `Inventory` primitives: `Item`, `ItemPhoto`, lifecycle states, required operator-controlled SKU unique per operating company. Sales-by-construction — this module's `commerce_inventory_items` table only ever holds sellable items; non-sales domains (maintenance MRO, production raw materials) get their own modules with their own schemas, not rows in this table. Commerce records carry `company_id` as the operating/legal-entity owner for reporting, financial attribution, settings, and authorization context inside a one-licensee BLB instance; it is not tenant isolation. *No* bin/location tracking — Ham doesn't need it and the next adopter can extend if they do.
- `Catalog` primitives: `ProductTemplate`, `Category`, generic `Attribute`/`AttributeValue` (the *mechanism*, not the car-parts attribute set).
- `Sales` primitives: channel-agnostic `Order`, `OrderLine`, `Sale` ledger row.
- `Marketplace` host: the channel-agnostic contract, channel registry, normalized listing/order handoff, reconciliation surfaces, and shared capability/menu primitives. Channel adapters register into this host instead of being hardwired into it, so a licensee can enable eBay only, Shopee only, Lazada only, or several channels side by side.
- `Marketplace/Ebay` channel provider (general): OAuth, listing + order sync (Phase 2), then researched publish/revise/end via the Sell APIs (Phase 6). Shopee, Lazada, and other marketplace integrations use the same provider contract and may ship as first-party modules or third-party extensions.
- `Core/AI` extensions: two new **Lara tasks** (per `lara-task-models.md`):
  - `photo-cleanup` (simple task) — input image → background-removed image; model selectable via Lara's `primary`/`recommended`/`manual` mode.
  - `describe-item` (simple task) — photos + attributes → title/description/category drafts.
  Both use provider/account-level spend controls where available, while BLB tracks usage and surfaces warnings before Ham runs over budget.
- `Core/AI` Lara messaging channel: a generic `LaraChannel` contract plus pluggable adapters so Lara can converse with an authenticated operator over chat — initial adapter is **Telegram Bot**; WhatsApp personal-account automation is a later research path, not part of the first build. Inbound messages and image attachments map to Lara conversations bound to a verified operator identity; outbound replies, action confirmations ("Publish? yes/no"), and structured results (a sold-yesterday list, a draft listing card) flow back through the same channel.
- Cross-cutting BLB primitives: `Money` value object, file/media subsystem with mobile-friendly upload, `base_settings` storage/resolution plus module-owned editable settings declarations, integration HTTP/OAuth/webhook primitive — these are framework gaps regardless of Ham.

**Ham-specific (lands in `extensions/ham/auto-parts/...`):**

- The car-parts attribute schema (year/make/model/engine/trim, OEM #, interchange #, condition grade) — seeded as `Attribute` rows, not hardcoded columns.
- A car-parts category seed mapped to Ham's eBay categories.
- Ham's eBay store defaults, shipping policy IDs, return policy IDs, and category mappings as non-secret extension defaults; actual credentials/tokens/API keys live in `base_settings` through a BLB UX, not in `.env` and not committed extension config.
- Ham's chat-channel bindings: which Telegram bot/chat is authorized to act as him in Lara conversations. The adapter is framework; the credentials and the operator-identity binding are operator-editable `base_settings` records. WhatsApp personal-account binding can be explored later if it proves safe and maintainable.
- Ham's preferred description template / tone / boilerplate footers, and his car-parts-tuned `photo-cleanup` / `describe-item` prompt overrides.
- Ham's report layouts (sold-this-month, top earners, aging unsold) as Livewire pages in his extension, built on the general Sales/Inventory query surface.

(No Windows desktop bridge is needed: BLB exposes a direct browser upload, Ham drops raw photos in, the `photo-cleanup` task returns the cleaned image. PhotoRoom and any folder-watch glue go away.)

The decision rubric: if a second adopter selling, say, used cameras would also want it, it goes in `app/Modules/...`. If it only makes sense for Ham, it goes in `extensions/ham/...`.

## Top-Level Components

1. **Inventory** (`app/Modules/Commerce/Inventory`) — `Item`, `ItemPhoto`, lifecycle (`draft → ready → listed → sold → archived`), required operator-controlled SKU. Sales-by-construction: this module's tables only hold sellable items. `commerce_inventory_items.id` is the system identity, while `sku` is the seller-facing business identifier, unique per operating company. Commerce inventory carries `company_id` as the operating/legal-entity owner so downstream listings, orders, sales, fees, and reports can be attributed correctly for adopters such as SBG; in Ham's install this will normally be `Company::LICENSEE_ID`. A future maintenance or raw-materials domain is a separate module, not a flag in this one. Deliberately thin: no bins, no movements ledger, no warehouse model. `Item` carries a free-form `notes` text field as the operator's private working surface (jot-it-down on the bench); buyer-facing listing copy lives in versioned `Description` rows in `Catalog`, never on `Item`. The first slices have landed with `Item`, `commerce_inventory_items`, required manual SKU entry with live create-form uniqueness feedback, a browser-visible Inventory Workbench, detail editing, local item-photo upload/delete, and item-level catalog category/template assignment that filters the attribute picker.
2. **Catalog** (`app/Modules/Commerce/Catalog`) — `ProductTemplate`, `Category`, `Attribute`, `AttributeValue`, `Description` (versioned). The mechanism for "what is this sellable item"; the car-parts vocabulary is seeded by Ham's extension. Maintenance categories or BOMs belong in their own module's catalog, not here. The data skeleton, browser Catalog Workbench, generic dev seeder, scalable search/paginated inline-edit catalog tables, and Inventory-side category/template assignment have landed.
3. **Marketplace** (`app/Modules/Commerce/Marketplace` + channel providers) — channel host + adapter registry. Read-first: pulls Ham's existing eBay listings and orders so BLB has a populated ledger from day one without him doing data entry. The first framework slice has landed with a registry-driven host, eBay as the first registered channel provider, eBay OAuth connection surface backed by `base_settings` and encrypted OAuth token storage, and Inventory API listing/offer sync that materializes `Listing` rows and links by SKU when possible. Shopee/Lazada can be added through the same channel-provider registration path without changing the host module. Sales/order persistence remains tied to the Sales schema slice.
4. **Sales** (`app/Modules/Commerce/Sales`) — `Order`, `OrderLine`, `Sale` (the durable "this item was sold for this much on this date through this channel" record), `Fee`. Driven by Marketplace adapters.
5. **AI Assist** — extends `app/Modules/Core/AI` with two new Lara tasks: `photo-cleanup` (raw image → background-removed image) and `describe-item` (photos + attributes → title/description/category drafts). Both flow through the existing Lara per-task model selection, provider-side plan/cap controls where available, and BLB usage visibility.
6. **Lara Phone Channel** (`app/Modules/Core/AI/Channels/...`) — generic `LaraChannel` contract plus a Telegram Bot adapter first. Inbound text + image attachments enter Lara as conversations bound to a verified operator identity; outbound replies, action confirmations, and structured result cards return through the same channel. Same authorization scope as Lara on the desktop — the channel is a transport, not a privilege boundary. WhatsApp personal-account automation is research-only for now.
7. **Insights** — generic, queryable views over Inventory + Sales (sold in period, gross margin, aging unsold). The *layout* Ham sees is in his extension; the queries are general.
8. **Ham extension** (`extensions/ham/auto-parts`) — car-parts attribute seed, non-secret eBay defaults/policy mappings, description boilerplate, prompt overrides, report pages, and Ham-specific settings screens/bindings. Secrets and tokens are entered through `base_settings` UX.

Cross-cutting framework prerequisites — these are the "beef up BLB" items, justified independently of Ham:

- **File/media subsystem**: storage abstraction, image processing, browser/desktop upload endpoint, derived-asset pipeline (so a "cleaned" image is a derived asset of an original, not a destructive overwrite).
- **`Money` value object** + currency-aware columns; no floats for cash, ever. Column convention: integer minor-unit fields use an `_amount` suffix and pair with a currency code (`unit_cost_amount`, `target_price_amount`, `currency_code` in the first Inventory slice).
- **`base_settings` storage + module-owned settings UX**: operator-editable provider API keys, OAuth credentials/tokens, channel bindings, provider plan/cap metadata, company default currency, and extension defaults. Ham must not need to edit `.env`. `Base/Settings` owns storage, scope resolution, encryption, discovery, and generic field rendering. Owning modules declare editable keys in their own `Config/settings.php`; the first module-owned settings route/menu has landed for eBay under Commerce Marketplace, while the admin aggregate remains a discovery surface.
- **Integration HTTP / OAuth primitive**: typed clients, token storage, retry + rate-limit policy, webhook receiver. Needed by the eBay adapter and any future channel that talks to outside systems. The first slice has landed under `app/Base/Integration` so the module can later cover API integrations, webhooks, file drops, SFTP/EDI, message buses, and connector health without renaming again.
- **AI cost visibility and provider failover** in `Core/AI`: show provider plan/cap status where available, task-level usage estimates, current-month spend, and backup-provider routing. For Ham, expect a primary subscription/base plan plus a pay-as-you-use backup such as Moonshot AI.

## Design Decisions

- **Augment what's free; replace what's a recurring bill.** Ham keeps his Windows desktop and keeps uploading to eBay through eBay's UI in early phases — those cost nothing extra and work fine. PhotoRoom, by contrast, is ~$10–14/month for one feature (background removal) that a modern open-weights segmentation model now does at equal or better quality for fractions of a cent per image. BLB takes that over via Lara's `photo-cleanup` task, and Ham cancels the subscription.
- **One primary AI provider, plus a cheap backup.** To minimize cost and operational overhead for a one-person shop, BLB recommends Ham configure a primary provider that serves both Lara tasks (`photo-cleanup` and `describe-item`) plus general Lara chat. The likely Ham shape is a subscription/base plan or provider-account cap, not BLB pretending it can be the only hard spending boundary. A pay-as-you-use backup such as Moonshot AI stays configured for continuity and overflow. Lara's existing `manual` mode lets either be selected per task; the framework does not hard-code the choice. BLB records usage, estimates cost, warns early, and routes to backup only when explicitly configured.
- **Settings belong in UX, not `.env`, and the owning module owns the setting.** Ham should never be told to SSH into a box or edit environment files. Provider API keys, eBay OAuth/app details, channel bindings, and plan/cap metadata are entered through BLB settings screens backed by `base_settings`. The central Settings module supplies storage and generic rendering, but declarations and menu placement stay with the module that owns the behavior. Extension code may ship non-secret defaults and Ham-specific labels; secrets and operator choices stay in settings.
- **Marketplace is a host plus pluggable channels.** The Commerce Marketplace module should not become an eBay module with extra adapters bolted on. It owns the normalized contract, registry, shared rows, reconciliation, and authorization. eBay, Shopee, Lazada, and future channels register descriptors, settings schemas, capabilities, routes/components, jobs/commands, and operation support into that host. First-party channels can live in the main repo while the API settles; third-party channels should be able to ship as extensions using the same registration path.
- **Default currency is company settings, not code.** The framework fallback is **MYR**, because most expected licensees are Malaysian. Item creation snapshots `currency_code` onto each `Item` so later company-setting changes do not rewrite historical cost/price records. Ham is an outlier and should set his company default currency to **USD** through BLB settings once `base_settings` UX lands; until then, the Create Item form can still be edited manually.
- **Read-first, then write — both via the official eBay APIs, never RPA.** Reading Ham's ~2,000 listings and his sold-order history first populates BLB's ledger so he gets value before any data entry, and so the write path has a real inventory to publish from. Writing follows later as a researched Phase 6: `createListing` / `reviseListing` / `endListing` plus bulk relist and bulk price change, built only after the Sell API model and onboarding requirements are verified. RPA against eBay's seller UI was considered and rejected — it skirts eBay's ToS, and an account suspension would be existential for Ham.
- **Inventory is intentionally shallow.** No bins, no stock movements, no multi-location. Ham said he doesn't lose parts. Adding warehouse mechanics he doesn't need is exactly the over-engineering BLB's principles forbid. A second adopter who needs bins gets it as a future plan, not as speculative scaffolding now.
- **Commerce records carry operating-company context.** Belimbing is one licensee per instance, so `company_id` is not tenant isolation. In Commerce it means the operating/legal entity that owns the item, account, listing, order, sale, fee, or related commercial fact. This matters for adopters such as SBG, where financial reporting and subsidiary attribution need Commerce data to retain company ownership. Ham's install stays simple because his Commerce rows normally point at `Company::LICENSEE_ID`.
- **Sales inventory is its own module; non-sales inventory will be its own module.** An earlier draft of this plan introduced a `StockBook` scope so a single shared item table could host sales, maintenance, and production raw materials behind a `purpose` flag. On reflection that's the wrong shape: those three domains share the word "item" but little else. Commerce inventory items have a `draft → listed → sold` lifecycle, sale-price valuation, marketplace listings, and buyer-facing copy. Maintenance MRO has a `on-hand → issued → consumed` lifecycle, cost valuation, reorder points, suppliers, and work-order links. Production raw materials are lot/batch-tracked, BOM-linked, FIFO/FEFO, with quality holds and traceability. Forcing them into one schema either yields an anemic table that satisfies none of them or a polluted one with fields every query has to step around — and every cross-domain change risks breaking the others. Module boundaries are the right separation: `app/Modules/Commerce/Inventory` is sales by construction (its tables can't accidentally hold a workshop brake pad), and a future `app/Modules/Maintenance/MRO` or `app/Modules/Production/RawMaterials` lands as its own module with its own schema, lifecycle, and consumers when someone genuinely needs it. Cross-module reporting is a thin Insights query joining what it needs, not a fact about a shared table. This avoids speculative scaffolding now and keeps each module deep when its time comes.
- **Car-parts vocabulary lives in the extension, not the framework.** `Attribute`/`AttributeValue` is the general mechanism. Ham's seed (`year`, `make`, `model`, `engine`, `OEM #`, `interchange #`, `condition grade`) is a seeder in `extensions/ham/auto-parts`. The next adopter (camera lenses, vintage clothing) writes their own seed; the framework is unchanged.
- **AI is the headline time-saver — and the headline cost-saver.** Photo cleanup and description drafting are where Ham's hours and dollars go. The `photo-cleanup` Lara task removes the PhotoRoom subscription; the `describe-item` Lara task takes the cleaned photo(s) + a handful of attribute fields and produces a draft title + draft description. Both are always assistive, always reviewed before publish, and always visible in BLB's usage/cost UI.
- **No desktop bridge needed.** Ham uploads originals through the browser on his Windows desktop; BLB queues `photo-cleanup` and shows the cleaned result in-place. There is no folder-watch glue, no PhotoRoom round-trip, no licensee-specific filesystem assumption. This is also a portability win — the same flow works on any OS for the next adopter.
- **The phone is the primary capture surface; the desktop is the review surface.** Ham has a part on his bench far more often than he has a browser open. Telegram ships first because it is free, simple, and bot-friendly; it lets him snap a photo and a one-line note from the bench and get a draft listing back in the same chat — same agent, same authorization scope, same usage tracking as Lara on the desktop. The desktop becomes the place to review, batch-approve, and publish, not the place to do data entry. WhatsApp personal-account automation is not in the first build; it can be researched later if Telegram is not enough.
- **No shipping integration in this plan.** Ham already prints labels through eBay; carrier APIs (EasyPost et al.) cost money and solve a problem he doesn't have. Dropped from the previous draft.
- **No data-migration concerns inside BLB.** Per BLB's destructive-evolution posture, schemas can change freely between phases. eBay is the external source of truth we re-sync from; nothing inside BLB is precious yet.
- **Money as integer minor units + currency code,** wrapped in a `Money` value object. Cheap now, painful later.
- **Cost discipline as a first-class constraint.** Ham has no money. Every external dependency (AI provider, hosting, SaaS) is justified, capped at the provider/account level where possible, visible in BLB, or made optional. BLB self-hosted on a small box must be a viable deployment.

## Public Contract

- An `Item` uses `commerce_inventory_items.id` as its stable BLB system identity and has a required operator-controlled `sku`, unique per `company_id` and independent of any eBay item ID. Each item also carries `company_id` for operating/legal-entity ownership and reporting attribution. Sold items retain their record forever — the **Sale** ledger is append-only and survives listing deletion on eBay.
- The `MarketplaceChannel` contract guarantees `pullOrders()` is idempotent and produces `Sale` rows linked to the originating `Item` whenever a match is possible (by SKU, eBay item ID, or operator-confirmed link).
- The `photo-cleanup` Lara task is a pure function from (input image, model choice, budget) to (cleaned image as a derived asset linked to the original, inference cost). It never deletes or overwrites the original.
- The `describe-item` Lara task is a pure function from (`Item`, `ProductTemplate`, photo set, model choice, budget) to (`title draft`, `description draft`, `category suggestion`, `token cost`). It never auto-publishes.
- Provider credentials, eBay OAuth details/tokens, channel bindings, plan/cap metadata, and the company default currency are stored through `base_settings` and edited through BLB UX owned by the relevant module; Ham-specific extension files may provide defaults but never require `.env` edits or committed secrets.
- The Insights query surface (general) exposes: items sold in `[from, to]` per channel/category, gross margin per item = sale − fees − cost basis, days-listed-without-sale per item. Extensions render these; they don't reimplement them.
- Ham's extension is removable. Uninstalling `extensions/ham/auto-parts` leaves a working, generic BLB Commerce install with no car-parts vocabulary and no Ham defaults, ready for the next adopter.

## Phases

Each phase ends with something Ham can use. Phases 0–2 are framework work that also unlocks the next adopter.

### Phase 0 — BLB capability gaps (framework)

- [ ] Media subsystem: storage abstraction, image processing queue, signed URLs, **HTTP upload endpoint**, **derived-asset model** (cleaned image is a child of the original, never an overwrite).
- [x] `Money` value object + currency-aware column convention; document under `docs/architecture/` (`*_amount` integer minor units + currency code).
- [x] `base_settings` UX/storage for operator-editable API keys, OAuth details/tokens, channel bindings, provider plan/cap metadata, company default currency, and extension defaults; Ham must never need `.env`.
- [x] Refactor editable settings declarations out of `app/Base/Settings/Config/settings.php` into owning modules' `Config/settings.php` files, and move settings UI placement to the owning module menu. Keep Base Settings as the storage/discovery/renderer module, not the owner of Commerce, AI, or channel-specific settings. First module-owned route/menu is eBay Marketplace settings; the aggregate admin settings page remains a discovery/editing surface.
- [x] Integration HTTP / OAuth primitive in `Core` (typed clients, token storage table, retry + rate-limit policy, webhook receiver scaffolding). First slice is implemented under `app/Base/Integration` as framework infrastructure; the module name intentionally leaves room for non-HTTP integration surfaces such as file imports/exports, SFTP/EDI, message buses, and connector health. Webhook scaffolding remains for the first channel that needs inbound callbacks.
- [ ] AI cost visibility and failover in `Core/AI`: provider plan/cap status when available, per-task usage estimates, current-month spend, operator warnings, and explicit backup-provider routing.

### Phase 1 — Inventory + Catalog skeleton (framework)

- [x] `app/Modules/Commerce/Inventory`: first `Item` slice — `commerce_inventory_items`, lifecycle status field, generated SKU behavior, `company_id` operating-company ownership, `unit_cost_amount`, `target_price_amount`, `currency_code` with MYR fallback, generic dev seeder, list/create Workbench, and no location/bin/movement tables.
- [x] `app/Modules/Commerce/Inventory`: replace generated SKU behavior with required manual SKU entry, enforce company-local uniqueness (`unique(company_id, sku)`), add live red/green uniqueness feedback in the create UI, and validate scoped uniqueness when SKU is edited later.
- [x] `app/Modules/Commerce/Inventory`: load Create Item default currency from company `base_settings` (`commerce.default_currency_code`), falling back to MYR.
- [ ] Ham onboarding/settings: set Ham's company default currency to USD through UX/settings once his install exists.
- [x] `app/Modules/Commerce/Inventory`: add `ItemPhoto` and the photo grid/upload surface. Currently uses raw `'local'` storage with no derived-asset linkage — the Phase 0 media subsystem (storage abstraction, signed URLs, derived-asset model) is still the upstream prerequisite before Phase 4's `photo-cleanup` task can attach cleaned children to originals.
- [ ] `app/Modules/Commerce/Inventory`: formalize lifecycle transitions beyond the initial status field when marketplace/photo/AI flows need them.
- [x] `app/Modules/Commerce/Catalog`: `ProductTemplate`, `Category`, generic `Attribute`/`AttributeValue`, versioned `Description`, and generic dev seeder.
- [x] Operator UX first slice (desktop-first): Inventory Workbench lists items, filters by search, and creates the durable item record with unit cost/target price/status/notes.
- [x] Operator UX next slices: show/edit item surfaces for reviewing and correcting the durable item record.
- [x] Operator UX next slices: photo grid.
- [x] Operator UX next slices: attribute editor and description editor.
- [x] Operator UX next slices: assign category/template to an item and filter applicable attributes from that selection.

### Phase 2 — eBay adapter, read-only (framework)

- [x] `app/Modules/Commerce/Marketplace` contract.
- [x] `app/Modules/Commerce/Marketplace/Ebay` adapter: OAuth (sandbox + live), token refresh, manual listing pull through the browser workbench, and a scheduler-ready `commerce:marketplace:ebay:pull` command for listing/order reads.
- [x] Refactor Marketplace into a registry-driven host plus channel providers. eBay registers as the first provider; Shopee, Lazada, and extension-provided channels use the same descriptor/settings/routes/jobs/capabilities registration path.
- [x] Materialize pulled eBay listings as `Listing` rows linked to `Item` (auto-link by SKU when present, operator-confirmed otherwise). Operator-confirmed linking remains a reconciliation-view task.
- [ ] Materialize pulled eBay orders as `Order`/`OrderLine`/`Sale` rows; `Sale` is the durable record of "this sold."
- [ ] Reconciliation view: BLB inventory vs. live eBay listings (what's listed, what isn't, what's drifted).

### Phase 3 — Ham extension v1 (`extensions/ham/auto-parts`)

- [ ] Car-parts `Attribute` seed: year, make, model, engine, trim, OEM #, interchange #, condition grade.
- [ ] Car-parts `Category` seed mapped to Ham's eBay store categories.
- [ ] Non-secret eBay store defaults in his extension (policy IDs, category mappings); actual credentials/tokens entered through `base_settings` UX.
- [ ] Ham's description boilerplate (footer text, return-policy blurb) and car-parts-tuned prompt overrides for `describe-item`.
- [ ] Provider setup walkthrough specific to Ham: pick a primary subscription/base-plan provider for Lara and image cleanup, set provider-side caps/plan controls where available, configure a pay-as-you-use backup such as Moonshot AI, then cancel PhotoRoom once BLB cleanup quality is acceptable.

### Phase 4 — AI assists (photo cleanup + descriptions)

- [ ] Register `photo-cleanup` and `describe-item` as new Lara simple tasks (per `lara-task-models.md`); they appear on the Task Models page alongside `titling`, `research`, `coding`.
- [ ] `photo-cleanup` execution: input image → cleaned (background-removed) image stored as a derived asset of the original; runs in the queue, surfaces in the Workbench when ready.
- [ ] `describe-item` execution: photos + attributes → draft title, draft description, suggested category.
- [ ] Workbench integration: drop raw photos in, see cleaned versions appear; one-click description draft, side-by-side review, accept-into-`Description`-version.
- [ ] Cost UI: Ham sees primary provider plan/cap status where available, current-month AI usage/spend split by task, and backup-provider usage at the top of the Workbench.
- [ ] Ham extension: car-parts-tuned `describe-item` prompt (fitment table, condition disclosure, OEM/interchange callout) and a sensible default `photo-cleanup` model pick.

### Phase 5 — Lara phone channel (framework + Ham bindings)

Brings Lara to Ham's bench before the eBay write path is solved. Telegram first; WhatsApp personal-account automation remains research-only for now.

- [ ] Define a generic `LaraChannel` contract in `Core/AI`: inbound message ingest (text + image attachments), operator-identity verification, outbound replies, action-confirmation prompts, structured result cards.
- [ ] Implement the **Telegram Bot** adapter (free; long-poll or webhook).
- [ ] Bind incoming chat sessions to a verified BLB operator identity so a message from Ham's number/handle runs under his authorization scope — no privilege escalation via channel.
- [ ] Wire the existing Lara conversation runtime to receive image attachments (queue `photo-cleanup`) and produce structured result cards (draft listing, sold-yesterday list, inventory/status answers).
- [ ] Per-channel usage visibility folded into the existing AI cost UI so a chat-driven workload is visible alongside desktop usage.
- [ ] Ham extension/settings: bind Ham's Telegram bot/chat to his operator identity through `base_settings` UX.
- [ ] Research note only: evaluate WhatsApp personal-account automation later for safety, maintainability, and account-risk; no WhatsApp Business adapter in this plan.

### Phase 6 — eBay write path (framework + Ham config)

The headline listing time-saver, but also the least-known integration. Built on the same official-API adapter from Phase 2 after a research/onboarding spike; no RPA.

- [ ] Research the current eBay Sell API write model for Ham's category set: Inventory/Offer flow, category/aspect requirements, policy IDs, revision/end semantics, sandbox/live differences, and any account eligibility blockers.
- [ ] Extend the `MarketplaceChannel` contract with `createListing()`, `reviseListing()`, `endListing()`.
- [ ] eBay adapter implementations of the above against the Sell APIs (Inventory + Offer model), including category-aspect submission and per-store policy ID resolution.
- [ ] One-time eBay onboarding flow in BLB: developer-app credential capture through `base_settings` UX, OAuth grant, store policy import (shipping / return / payment), category mapping import.
- [ ] Workbench "publish to eBay" action with a dry-run/diff preview before commit; surface eBay validation errors inline against the offending attribute.
- [ ] Bulk relist + bulk price change over filtered inventory (e.g. "everything listed > 90 days, no sale, mark down 10%").
- [ ] Phone-channel parity: a "publish?" confirmation card from Lara in Telegram triggers the same publish path, so Ham can ship a listing from the bench once the write path is proven.
- [ ] Ham extension: defaults for shipping/return/payment policy IDs and category mapping for car-parts SKUs, so the publish action is one click for him without leaking that vocabulary into the framework.

### Phase 7 — Insights (framework queries, Ham layouts)

- [ ] General Insights query surface in `app/Modules/Commerce/Sales`: sold-in-period, gross margin per item, days-listed-without-sale.
- [ ] Ham extension Livewire pages: "Sold this month," "Top earners last 90 days," "Listed > 180 days, no sale" — each one a thin view over the general queries.
- [ ] CSV export for Ham's bookkeeper.

### Explicitly out of scope

- Bin/location/warehouse tracking (Ham doesn't need it).
- Carrier shipping APIs and label printing (eBay already handles this for him; pure cost with no benefit).
- Anything beyond background removal in the photo pipeline (no upscaling, no relighting, no auto-cropping) — Phase 4 ships a single-purpose `photo-cleanup` task; richer photo edits are a future plan if Ham asks.
- Multi-channel listing (Reverb/Mercari/Shopify) — covered by the contract, but no second adapter built in this plan.
- Full accounting — CSV export to Ham's bookkeeper is the boundary.
- Anything that requires Ham to learn a new desktop OS or replace tools he already trusts.
- WhatsApp Business integration in the first build; Telegram is the first phone channel, and WhatsApp personal-account automation is research-only until proven safe.
- RPA / browser automation against eBay's seller UI as a write mechanism — rejected for ToS and account-suspension risk; the Sell APIs are the only sanctioned write path.
