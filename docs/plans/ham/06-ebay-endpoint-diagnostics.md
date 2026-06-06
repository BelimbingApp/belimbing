# ham/06-ebay-endpoint-diagnostics

**Agents:** GitHub Copilot/GPT-5.3-Codex; Claude Opus 4.8 (implementation 2026-06-05)
**Status:** Implemented
**Last Updated:** 2026-06-05
**Sources:**
- User discussion on 2026-06-03: current eBay "connection test" is effectively endpoint testing and should expose endpoint choice plus request/response details.
- Existing eBay settings probe flow: app/Modules/Commerce/Marketplace/Livewire/Ebay/Settings.php, app/Modules/Commerce/Marketplace/Ebay/EbayConnectionTester.php, app/Modules/Commerce/Marketplace/Views/livewire/commerce/marketplace/ebay/settings.blade.php.
- Existing integration exchange details UI and routes: app/Base/Integration/Routes/web.php, resources/core/views/livewire/admin/integration/outbound-exchanges/show.blade.php.
- Observed sandbox responses in outbound exchanges: API_INVENTORY 25001 (HTTP 500) and API_ACCOUNT 20403 (HTTP 400, business-policy ineligibility).
- Settings page reorg on 2026-06-05: page is now a 3-tab surface (Connection / Seller defaults / Categories). The Connection tab holds two cards — "Credentials & OAuth" (the form) and "Connection test" (status + Connect/Test). Diagnostics replaces the second card in place; it is not a new surface.
- Capability split (verified in code): running diagnostics requires `commerce.marketplace.manage`; viewing an outbound exchange requires `admin.system.outbound-exchange.list` (app/Base/Integration/Routes/web.php). These are independent grants.

## Problem Essence

The current eBay settings "connection test" label is misleading. The flow already proves basic connectivity and OAuth transport, but operationally it is validating one specific eBay endpoint contract at a time. When an endpoint fails for business or account eligibility reasons, operators see a generic failed state without enough on-page context to understand what was tested and why it failed.

## Desired Outcome

Operators use an "Endpoint diagnostics" panel where they can choose a safe probe, run it, and immediately see the tested request shape and response summary on the same screen, with a direct link to full integration exchange details. Results are classified as healthy, attention, or failed so account-readiness issues are distinguishable from transport/auth/system failures.

## Top-Level Components

1. Diagnostics probe catalog
- Curated, read-only eBay probe presets with clear intent, required scopes, and query defaults.

2. Diagnostics execution service
- A reusable service that executes the selected probe through IntegrationGateway and emits a structured diagnostics result.

3. Settings UI diagnostics surface
- Probe selector, run action, status badge, request summary, response summary, and "Open exchange" action.

4. Exchange integration bridge
- Reuse existing outbound exchange pages for full payload inspection instead of creating a second payload viewer.

## Design Decisions

- Reframe the feature from "connection test" to "endpoint diagnostics" in operator-facing copy.
- Ship curated probe presets first; do not allow freeform custom endpoints in the first slice.
- Keep probes read-only and GET-only for safety and predictability. Enforce GET as a type invariant on the probe definition, not a runtime check, so a mutating probe cannot be added by accident.
- Classify known account-precondition responses as attention (for example, API_ACCOUNT 20403) rather than hard failure. Classification keys on the `(httpStatus, eBay errorId)` tuple read from the response body, not HTTP status alone — `EbayConnectionTester::failureMessage()` currently switches on status only and would mislabel 20403 (HTTP 400) as a generic failure.
- Show a concise request/response summary directly on settings, then deep-link to the existing exchange details page for full payloads.
- Preserve existing exchange recording behavior; diagnostics should add context, not fork logging.
- Keep scope expectations explicit per probe so operators can fix OAuth grants without guessing. Pre-flight the probe's required scope against the granted token and short-circuit with a clear message before calling eBay, mirroring the existing global scope pre-check but at probe granularity.
- **Merge, do not duplicate.** The existing connection test becomes the default probe (account payment policies) inside one "Connection test"/"Connection & diagnostics" card — not a second parallel panel. The current one-click button stays as "run default probe".
- **Name honestly across the stack.** If operator copy moves to "diagnostics", decide in Phase 1 whether `EbayConnectionTester`, `EbayConnectionTestResult`, and the `marketplace.ebay.connection_test_*` settings keys are renamed to match. Renaming the settings keys orphans persisted rows, so either (a) keep the existing keys and only rename code-internal types, or (b) do a one-time value port. Do not leave UI saying "diagnostics" while storage says "connection_test" with no decision recorded.
- **Consolidate persisted result into one structured value.** Today five flat keys hold the last result (`connection_test_status|message|http_status|exchange_id`, `connection_tested_at`). Adding probe key, method, endpoint, query, and response excerpt as five more flat keys is sprawl — persist one JSON value instead. Decide whether last-result is global (last run of any probe) or keyed per probe; per-probe avoids showing a stale result when the operator switches the selector. First slice may stay global if simpler, but state the choice.
- **Gate the exchange link by capability.** Render "Open integration exchange" only when the viewer holds `admin.system.outbound-exchange.list`; otherwise show the on-page summary alone. A diagnostics-runner without that grant should never be handed a link that 403s.

## Public Contract

- Diagnostics probe key: stable identifier selected by the operator (for example, account policies, inventory items, inventory locations).
- Diagnostics run result includes:
  - status: healthy, attention, failed
  - operator message
  - tested endpoint and method
  - query parameters sent
  - HTTP status
  - exchange id
  - compact response excerpt (error domain/id/message when present)
- Default probe remains one-click so existing workflow is preserved for users who do not need probe selection.

## Phases

### Phase 1 — Probe Model And Result Contract

Goal: establish a stable backend contract for selectable probes and richer diagnostics output.

- [x] Define a probe catalog as a typed value-object list (probe key, GET-only method, endpoint template, default query, required scope, intent label) — not a loose config array, so the GET-only and required-scope invariants are carried by the type.
- [x] Extend the diagnostics result DTO with probe key, method, endpoint, query, and response excerpt fields.
- [x] Add response classification rules keyed on `(httpStatus, errorId)` mapping known eBay account-eligibility outcomes (e.g. API_ACCOUNT 20403) to attention; default unknown 4xx/5xx to failed.
- [x] Persist the last result as one structured (JSON) settings value rather than adding more flat keys; record whether it is global or per-probe.
- [x] Decide and record the naming question (rename to diagnostics vs keep connection_test keys) before writing persistence, so storage and UI copy agree.
- [x] Keep compatibility with existing one-click invocation path (default probe = account payment policies).

Assumptions: all initial probes can be represented as idempotent GET calls.

Risks: overfitting classifications to a narrow subset of eBay errors; mitigate by preserving raw exchange link and defaulting unknown 4xx/5xx to failed. Renaming persisted settings keys orphans stored results; mitigate by choosing keep-keys or an explicit value port.

### Phase 2 — Settings UI Diagnostics Panel

Goal: make endpoint-level diagnostics understandable without leaving the eBay settings page.

- [x] Replace the second card on the Connection tab ("Connection test") in place; do not add a third card or tab. The card already carries the environment (Sandbox/Live) and status badges and the Connect/Test buttons.
- [x] Rename section copy from connection language to endpoint diagnostics language.
- [x] Add a probe selector with operator-friendly labels and short intent descriptions; default it to the account payment-policies probe so the prior one-click flow is unchanged.
- [x] Render request summary (method, endpoint, query) and response summary (status and normalized error excerpt) after each run.
- [x] Add a direct "Open integration exchange" link when exchange id is present AND the viewer holds `admin.system.outbound-exchange.list`; otherwise render the summary only.
- [x] Keep current button behavior fast for default probe while supporting explicit probe selection.

Assumptions: operators with `commerce.marketplace.manage` may run diagnostics; viewing full payloads additionally requires `admin.system.outbound-exchange.list`.

Risks: payload visibility capability is more restrictive than diagnostics-run capability; mitigate by always rendering safe summaries and linking to exchange for authorized viewers.

### Phase 3 — Tests, Rollout, And Operator Guidance

Goal: deliver safely with clear behavior coverage and low operator confusion.

- [x] Update feature tests for probe selection, endpoint/query assertions, and status classification.
- [x] Add tests proving API_ACCOUNT eligibility responses become attention, not ambiguous failure.
- [x] Verify integration exchange link rendering behavior for both success and error runs, including that the link is hidden when the viewer lacks `admin.system.outbound-exchange.list`.
- [x] Assert the probe selector defaults to the account payment-policies probe so the one-click path is preserved.
- [x] Update operator-facing helper text to explain what diagnostics validates and what it does not.
- [ ] Validate end-to-end in sandbox with at least one healthy probe and one attention probe. *(Pending: needs a live sandbox account; covered by faked-HTTP feature tests in the meantime.)*

Assumptions: sandbox accounts may not be business-policy eligible, so attention-path coverage is expected during validation.

Risks: endpoint behavior drift in sandbox; mitigate by keeping message text tied to exchange evidence and probe intent rather than brittle exact-body assumptions.

## Implementation Notes (2026-06-05)

Decisions taken during the build, recorded for honesty:

- **Naming:** renamed `EbayConnectionTester` → `EbayDiagnosticsService` and `EbayConnectionTestResult` → `EbayDiagnosticsResult`; the Livewire action is `runDiagnostics()` and the state property is `diagnostics`. UI copy is now "Connection & diagnostics" / "Run diagnostics".
- **Persistence:** the five flat `marketplace.ebay.connection_test_*` / `connection_tested_at` keys were **dropped** in favour of one JSON value, `marketplace.ebay.diagnostics` (`EbayDiagnosticsService::SETTINGS_KEY`). A diagnostics result is transient (re-derivable by clicking Run), so orphaning the old keys is safe in this initialization phase — no value port was needed.
- **Last-result scope:** **global** (last run of any probe). To avoid stale-result confusion, the result panel labels which probe was run ("Probe: …"), so a result for a different probe than the current selector is never misread.
- **Probe catalog:** `EbayDiagnosticProbe` (GET-only via a `method()` accessor, not a field) + `EbayDiagnosticProbes` registry. Initial probes: account payment / fulfillment / return policies (scope `sell.account`, marketplace-scoped) and inventory locations / items (scope `sell.inventory`, not marketplace-scoped, `limit=1`).
- **Scope pre-flight:** per-probe — each probe declares a scope group; the service checks the configured scopes and the granted token for that group before calling eBay, replacing the old blanket "account + inventory" gate.
- **Classification:** `(httpStatus, errorId)` from the body; `errorId 20403` → attention, everything else non-2xx → failed (401/403/network keep their specific messages). A compact `errorId · domain · message` excerpt is shown on-page.
- **Exchange link:** gated by `admin.system.outbound-exchange.list`; viewers without it see the exchange id as plain text.
- **Entropy/honesty fixes folded in alongside:** marketplace dropdown `EBAY_MOTORS` → `EBAY_MOTORS_US` (matched the rest of the codebase), broadened the settings group description, corrected the category table caption, and updated the OAuth help step that referenced the old "Test connection" button.
