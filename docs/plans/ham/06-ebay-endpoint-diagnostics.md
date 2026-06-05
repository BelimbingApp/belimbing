# ham/06-ebay-endpoint-diagnostics

**Agents:** GitHub Copilot/GPT-5.3-Codex
**Status:** Identified
**Last Updated:** 2026-06-03
**Sources:**
- User discussion on 2026-06-03: current eBay "connection test" is effectively endpoint testing and should expose endpoint choice plus request/response details.
- Existing eBay settings probe flow: app/Modules/Commerce/Marketplace/Livewire/Ebay/Settings.php, app/Modules/Commerce/Marketplace/Ebay/EbayConnectionTester.php, app/Modules/Commerce/Marketplace/Views/livewire/commerce/marketplace/ebay/settings.blade.php.
- Existing integration exchange details UI and routes: app/Base/Integration/Routes/web.php, resources/core/views/livewire/admin/integration/outbound-exchanges/show.blade.php.
- Observed sandbox responses in outbound exchanges: API_INVENTORY 25001 (HTTP 500) and API_ACCOUNT 20403 (HTTP 400, business-policy ineligibility).

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
- Keep probes read-only and GET-only for safety and predictability.
- Classify known account-precondition responses as attention (for example, API_ACCOUNT 20403) rather than hard failure.
- Show a concise request/response summary directly on settings, then deep-link to the existing exchange details page for full payloads.
- Preserve existing exchange recording behavior; diagnostics should add context, not fork logging.
- Keep scope expectations explicit per probe so operators can fix OAuth grants without guessing.

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

- [ ] Define a probe catalog inside the eBay diagnostics service with curated read-only probes and required scopes.
- [ ] Extend the diagnostics result DTO with probe key, method, endpoint, query, and response excerpt fields.
- [ ] Add response classification rules mapping known eBay account eligibility outcomes to attention.
- [ ] Persist additional diagnostics context alongside existing status/message/tested_at/http_status/exchange_id settings.
- [ ] Keep compatibility with existing one-click invocation path.

Assumptions: all initial probes can be represented as idempotent GET calls.

Risks: overfitting classifications to a narrow subset of eBay errors; mitigate by preserving raw exchange link and defaulting unknown 4xx/5xx to failed.

### Phase 2 — Settings UI Diagnostics Panel

Goal: make endpoint-level diagnostics understandable without leaving the eBay settings page.

- [ ] Rename section copy from connection language to endpoint diagnostics language.
- [ ] Add a probe selector with operator-friendly labels and short intent descriptions.
- [ ] Render request summary (method, endpoint, query) and response summary (status and normalized error excerpt) after each run.
- [ ] Add a direct "Open integration exchange" link when exchange id is present.
- [ ] Keep current button behavior fast for default probe while supporting explicit probe selection.

Assumptions: operators with marketplace-manage capability should be allowed to run diagnostics.

Risks: payload visibility capability is more restrictive than diagnostics-run capability; mitigate by always rendering safe summaries and linking to exchange for authorized viewers.

### Phase 3 — Tests, Rollout, And Operator Guidance

Goal: deliver safely with clear behavior coverage and low operator confusion.

- [ ] Update feature tests for probe selection, endpoint/query assertions, and status classification.
- [ ] Add tests proving API_ACCOUNT eligibility responses become attention, not ambiguous failure.
- [ ] Verify integration exchange link rendering behavior for both success and error runs.
- [ ] Update operator-facing helper text to explain what diagnostics validates and what it does not.
- [ ] Validate end-to-end in sandbox with at least one healthy probe and one attention probe.

Assumptions: sandbox accounts may not be business-policy eligible, so attention-path coverage is expected during validation.

Risks: endpoint behavior drift in sandbox; mitigate by keeping message text tied to exchange evidence and probe intent rather than brittle exact-body assumptions.
