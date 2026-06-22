# payroll-pluggable-modules

**Status:** Decision / options doc — awaiting a chosen direction. No build sheet until a model is approved.
**Last Updated:** 2026-06-22
**Sources:**
- `docs/architecture/module-system.md` — "Module Variation: Adapters and Slots" (the two mechanisms) and the "Distribution Bundle Model"; Payroll is its worked example.
- `app/Modules/People/Payroll/` — today's "Malaysia reference" implementation: `composer.json` (`name: blb/payroll-my`, `extra.blb.module: people/payroll`), `ServiceProvider.php`, the `PayrollCountryPack` contract + `PayrollCountryPackRegistry`, and `CountryPacks/Malaysia/`.
- `app/Modules/Commerce/Plugins/` — `CommercePluginRegistry`, `CommerceReadinessContributor`, `CommercePluginDiscoveryService`: BLB's live extension-seam precedent (glob discovery from `Config/commerce.php`).
- `app/Base/Foundation/ModuleManifest/BelimbingAppCatalogService.php` + `tests/Feature/Base/Foundation/BundleCatalogTest.php` — the catalog already contemplates `blb-payroll-my` / `blb-payroll-sg` bundles.
- `docs/plans/software-modules-screen.md` — the in-flight merge of Bundles + Business Domains into one domain-grouped **Modules** screen, where country packs would surface as installed / installable.

**Agents:** claud/opus-4.8

---

## Problem Essence

BLB needs a sanctioned way to make modules country-/licensee-specific, using People/Payroll as the driving example. The architecture spec names two mechanisms — **adapters** and **slots** — but is internally inconsistent about which applies to Payroll, and today's Payroll module is shipped as a single "Malaysia reference" bundle with the country-agnostic engine and the Malaysia statutory rules fused in one delivery unit. We need to decide the model before extracting anything.

## Desired Outcome

A clear, recommended model for module variation that Payroll can adopt first and that other country/licensee-specific modules can follow: which mechanism fits, why, what the seam looks like, how a deployment serving multiple countries at once behaves, how country packs are delivered and surfaced to operators, and what migrating away from "Payroll = MY reference" entails. This document stops at the recommendation; the build sheet is deliberately omitted until a direction is approved.

## The Spec Tension To Resolve

`module-system.md` uses `People/Payroll` as the canonical **slot** example in "Mechanism 2 — Slot replacement" (it literally names `app/Modules/People/Payroll/` as the slot path), yet in "Mechanism 1 — Contract + adapters" it states that "Payroll variation is *usually* this shape too" — a shared engine with statutory rules as policy adapters selected by the company's country. Both cannot be the headline model for the same module.

The contradiction is resolvable, and the code has already voted. Payroll today carries a `PayrollCountryPack` contract (`manifest / profileSchemas / payItemClassifier / calculator / exports`), a `PayrollCountryPackRegistry` keyed by ISO country, and a `CountryPacks/Malaysia/` implementation. That is the *adapter* shape, not the slot shape — the engine is one module and the country is a contribution into a registry. The slot framing in the spec reads as the older, pre-`CountryPack` mental model. This document treats the adapter framing as primary for Payroll and recommends the spec's slot example be re-pointed at a module that genuinely has no shared engine (or marked hypothetical), so the doc stops teaching two contradictory things with the same name.

## Option A — Adapters: one shared engine + country statutory packs

One Payroll engine owns the country-agnostic machinery — pay periods/calendars, the pay-run lifecycle and locking, pay-item ledger, payslip and PDF artifacts, the event intake from Attendance/Leave/Claim, exports plumbing. Each country ships a **statutory pack** that registers, against a published contract, the things that actually vary: statutory contributions (EPF/SOCSO/PCB for MY), tax tables/formulas, statutory pay items, profile schemas (employer/employee statutory fields), and filing/export definitions. A company resolves its pack by its country at run time.

This is the same seam BLB already runs in Commerce: `CommercePluginRegistry` collects channel providers, readiness contributors, and data contributions discovered from `Config/commerce.php`, and nobody outside the seam reaches into another module's tables. Payroll's `PayrollCountryPackRegistry` is the Payroll-shaped analogue; the missing piece is *discovery* (today Malaysia is hand-registered in the ServiceProvider rather than discovered).

Trade-offs:
- **For:** one engine maintained instead of N; the costly, regulation-heavy parts (pay-run correctness, locking, ledger integrity) are written and tested once; adding a country is additive and small; it matches the existing `PayrollCountryPack` code and the Commerce precedent; it is the only option that serves multiple countries in one deployment (see the decisive consideration).
- **Against:** the engine/country contract is real API surface that must be designed well and versioned (the registry already carries `CORE_CONTRACT_VERSION = payroll-country-pack-v0`); a country whose payroll genuinely cannot fit the shared lifecycle would strain the contract; "engine present but no pack for this company's country" is a new state the product must handle gracefully rather than crash.

## Option B — Slots: one whole-Payroll variant bundle per deployment

The whole Payroll module path becomes a slot: `blb-payroll-my`, `blb-payroll-sg`, etc. are *complete, independent* Payroll implementations, and a deployment installs exactly one to fill `app/Modules/People/Payroll/`. Each variant owns its own migrations, tables, lifecycle, and UI; dependents see the same path, namespace, and `extra.blb.module: people/payroll`, so the swap is invisible to the rest of the system.

Trade-offs:
- **For:** maximal freedom per country — a variant can diverge in lifecycle, schema, and UI without negotiating a shared contract; appropriate when two countries' payroll truly share nothing worth sharing; the slot rules (fixed identity, contract-only dependencies, deploy-time choice) are already specified.
- **Against:** N near-identical engines to maintain, with the regulation-critical core duplicated across every variant and drifting; switching variants on a live database is a data-migration project, not a toggle; and — decisively — **one variant per deployment** is incompatible with BLB's multi-company, multi-country reality.

## The Decisive Consideration: Multi-Company, Country-Scoped Settings

BLB is multi-company, and statutory settings are country-scoped per company. A single BLB deployment can legitimately host a Malaysian company and a Singaporean company side by side. This is the consideration that settles the core decision rather than merely informing it:

- A **slot** is *one variant per deployment*. Choosing `blb-payroll-my` fills the single Payroll path for the whole instance; the SG company on the same instance has no compliant payroll. Supporting both would mean two slot variants at the same module path simultaneously — which the slot model explicitly forbids (fixed identity, one selected variant). Slots cannot express "different rules for different companies in the same instance."
- **Adapters** resolve *per company by country*. The MY company's pay run uses the MY pack; the SG company's uses the SG pack; both run on the same engine in the same deployment. Per-company resolution is exactly what country-scoped settings need.

A slot would only be coherent if BLB committed to single-tenant-per-country deployments. It does not. This alone makes adapters the load-bearing model for Payroll; the remaining sections assume adapters and treat slots as the narrow exception.

## Adapter-Approach Considerations

If adapters are chosen, these are the things to get right (stated as considerations, not steps):

- **Extracting the country-agnostic engine.** Today's bundle is named and described as the "Malaysia reference plugin," yet the code already separates concerns: the engine services live at the module root and MY lives under `CountryPacks/Malaysia/`. The real work is finishing the separation — ensuring no engine service hard-codes EPF/SOCSO/PCB assumptions, that MY-specific pay items and profile fields arrive only through the pack contract, and that the bundle's identity stops claiming to *be* Malaysia.
- **A country-provider contract + registry, modelled on Commerce.** The `PayrollCountryPack` contract already names the variation surface: profile schemas, pay-item classification, the pay-run calculator, and exports — to which statutory contributions, tax tables, statutory pay items, and filing definitions map. The registry already enforces a core-contract version and rejects duplicate countries. The gap versus Commerce is **discovery**: `CommercePluginDiscoveryService` globs `Config/commerce.php` across modules and extensions and registers contributions with no central wiring, whereas Payroll currently hand-registers Malaysia in its ServiceProvider. A discovered seam (a Payroll-config contract analogous to `commerce.php`, scanned across `app/Modules/*` and `extensions/*`) lets a country pack register itself by being installed — the discovery-over-registration principle the rest of BLB follows.
- **Per-company resolution by country.** Resolution is a function of the company's country (the registry is already keyed by normalized ISO). The contract must keep statutory state genuinely per-company (employer/employee statutory profiles, contribution rows) so two companies on the same engine never cross-contaminate.
- **"Missing pack = company not payroll-ready," engine stays valid.** The important invariant: a deployment with the engine but no pack for a given company's country is *not* broken — that company is simply not payroll-ready, surfaced as a readiness gap (the Commerce readiness-contributor pattern is the precedent), while the engine and any other company's payroll keep working. This mirrors the spec's "no listener means no failure" stance: absence of a contribution is a missing capability, not an invalid module.
- **Country packs as installable Distribution Bundles.** Each pack is its own versioned bundle (`blb-payroll-my`, `blb-payroll-sg`) — which is precisely what the catalog test already fakes and what `BelimbingAppCatalogService` is built to discover (the `blb-bundle` topic, `extra.blb.module`, version). Packs install/enable/disable like any bundle, and they surface in the in-flight **Modules** screen (`docs/plans/software-modules-screen.md`) as installed vs. installable rows. Note the bundle granularity that follows from adapters: the engine is one installable thing and each country is another — the catalog and Modules screen should present the engine and its packs as related rows, not as competing whole-Payroll variants. (Detailed screen behaviour belongs to that plan, not this one.)

## Slot-Approach Considerations (the narrow exception)

Slots are still the right tool somewhere; just not for multi-country Payroll. They are justified when:
- **Single-country deployments** where multi-company-across-countries is genuinely out of scope, *and*
- **Engines that cannot be shared** — variants that diverge so far in lifecycle, consumers, or regulatory burden that a shared engine harms both (the spec's Principle 1a test). A payroll regime built on a fundamentally different pay-run lifecycle, not just different tables, is the candidate.

Even then, prefer adapters first and convert to a slot only when a real whole-module variant arrives and the shared contract is demonstrably doing more harm than good. The slot rules (fixed path/namespace/manifest identity, contract-only dependencies, variant-owned migrations, deploy-time choice, documented surface) remain the governing contract if that day comes. Because the slot example in `module-system.md` currently points at `People/Payroll`, that example should be re-pointed at a module that actually has no shared engine, or labelled hypothetical, so the spec stops contradicting the adapter direction.

## Migration Considerations: from "Payroll = MY reference" to adapters

Moving from today's fused bundle to the adapter model is mostly a *delivery and identity* change layered on a code separation that is already largely done. Considerations:

- **Identity vs. content.** The module identity `extra.blb.module: people/payroll` is the engine and should stay; the composer `name`/`description` (`blb/payroll-my`, "Malaysia reference plugin") describe MY and should move to the MY pack. The risk is conflating the two: the engine must not keep advertising itself as Malaysia.
- **Bundle split, not a rewrite.** The migration extracts `CountryPacks/Malaysia/` into its own `blb-payroll-my` bundle that contributes through the discovered seam, leaving the engine as the `people/payroll` bundle. The catalog already expects exactly this shape.
- **Data continuity.** Existing MY deployments have live payroll data tied to the current single bundle. The migration must keep that data owned by the engine where it is country-agnostic (runs, periods, pay-item ledger) and by the MY pack where it is statutory, without a destructive reshuffle — the multi-company invariant means existing rows are already company-scoped, which helps.
- **Discovery cutover.** Replacing the hand-registration in the ServiceProvider with discovery is the behavioural change that makes packs pluggable; it should be done such that an installed-but-not-yet-discovered state degrades to "not payroll-ready," never to a hard error.
- **No flag day for operators.** Because identity is preserved, dependents and operators should see continuity: same module path, same routes, with Malaysia now an explicitly installed pack rather than an implicit part of the engine.

## Cross-Cutting Considerations

- **Testing variation.** The engine is tested once against the contract with a fake/reference pack; each country pack is tested for its own statutory math; a cross-cutting test proves multi-company resolution (two companies, two countries, one engine, no bleed) and the "missing pack ⇒ not payroll-ready, engine still valid" state. Module-owned tests travel with their bundle (engine tests with the engine, MY tests with the MY pack), per the testing-structure contract.
- **Discovery.** Adopt the Commerce discovery shape (glob a Payroll contribution config across `app/Modules/*` and `extensions/*`) rather than inventing a new mechanism, so packs install with no central registration and the same roots licensees already use work for licensee-specific packs.
- **Authz for country packs.** Managing packs is bundle lifecycle, already gated by the Software/Modules management capability; the consideration is whether *operating* a pack (editing statutory tables, running country filings) needs pack- or country-scoped capabilities beyond generic payroll authz, especially where one operator administers multiple companies in different countries. Note the spec's rule that extensions merge their own authz rather than relying on the central `Config/authz.php` discovery root — licensee packs follow that path.
- **Generalization beyond Payroll.** The same decision procedure applies to any country/licensee-specific module: if variants share an engine and differ in rules/integrations, use a discovered contract + registry (Commerce, Payroll); reserve slots for whole-module variants with no shared engine. The multi-company/country-scoped reality means *most* country variation in BLB is adapter-shaped, because a single deployment routinely spans countries. "One variant per deployment" is the rare case, not the default.

## Recommendation

**Adopt the adapter model for Payroll, and treat it as BLB's default for country-/licensee-specific variation.** Finish extracting the country-agnostic Payroll engine from the MY-fused bundle; keep `people/payroll` as the engine's identity; ship Malaysia as a `blb-payroll-my` statutory pack that registers through a *discovered* `PayrollCountryPack` seam modelled on Commerce; resolve packs per company by country; treat a missing pack as "that company is not payroll-ready" while the engine and other companies stay valid; and surface packs as installable bundles in the Modules screen.

Rationale, in order of weight:
1. **Multi-company, country-scoped settings make slots structurally unworkable** for Payroll — one deployment must serve multiple countries at once, and a slot is one variant per deployment. This is decisive on its own.
2. **The code already chose adapters** — `PayrollCountryPack`, `PayrollCountryPackRegistry`, and `CountryPacks/Malaysia/` are the adapter shape; the only missing piece is discovery and a clean bundle split.
3. **It matches a proven BLB seam** — Commerce's discovered contribution registry is live and is the right template, avoiding a bespoke mechanism.
4. **It minimizes duplicated, regulation-critical code** — one pay-run engine, tested once; each country adds only its rules.

Reserve slots for the genuine exception — single-country deployments with engines that cannot be shared — and, to remove the documented contradiction, re-point the `module-system.md` slot example away from `People/Payroll` (or mark it hypothetical), since Payroll is now the canonical *adapter* example.
