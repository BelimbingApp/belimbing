# OpenAI Codex OAuth Provider

**Agent:** Codex
**Status:** Identified
**Last Updated:** 2026-04-21
**Sources:** `AGENTS.md`, `docs/plans/AGENTS.md`, `app/Base/AI/Config/ai.php`, `app/Modules/Core/AI/Contracts/ProviderDefinition.php`, `app/Modules/Core/AI/Definitions/GenericApiKeyDefinition.php`, `app/Modules/Core/AI/Livewire/Providers/ProviderSetup.php`, `app/Modules/Core/AI/Models/AiProvider.php`, `app/Modules/Core/AI/Services/ProviderAuthFlowService.php`, `app/Modules/Core/AI/Services/ProviderDefinitionRegistry.php`, `app/Modules/Core/AI/Values/ResolvedProviderConfig.php`, `resources/core/views/livewire/admin/ai/providers/provider-setup.blade.php`, `/home/kiat/repo/openclaw/src/plugins/provider-openai-codex-oauth.ts`, `/home/kiat/repo/openclaw/src/agents/cli-credentials.ts`, `/home/kiat/repo/openclaw/node_modules/@mariozechner/pi-ai/dist/utils/oauth/openai-codex.js`, `/home/kiat/repo/openclaw/node_modules/@mariozechner/pi-ai/dist/providers/openai-codex-responses.js`, `https://github.com/openai/codex/blob/1dcea729d33ac936b8207ffccae7a0c4cb6b4ff4/codex-rs/app-server/README.md`

## Problem Essence

BLB can list providers with `auth_type: oauth`, but that label is currently UI-deep rather than a complete runtime contract. Supporting OpenAI Codex requires a real browser OAuth flow, refreshable credential storage, and a provider-specific ChatGPT backend transport that is materially different from BLB's current OpenAI API-key path.

## Desired Outcome

BLB should be able to provision an `openai-codex` provider that signs the user in through browser OAuth, stores renewable subscription credentials securely, refreshes them at runtime, and routes Codex-backed requests through a dedicated transport without pretending this is the same thing as standard OpenAI API access. The integration should be explicit about its support boundary: it is based on observed behavior, not public OpenAI documentation.

## Top-Level Components

### Dedicated provider definition

BLB should add a first-class provider definition for `openai-codex` rather than relying on the generic `oauth` overlay path. The definition owns the editor fields, validation, credential shape, runtime resolution, and any provider-specific model defaults. This keeps the unsupported parts localized instead of leaking special cases into Livewire pages and runtime services.

### Browser OAuth flow service

The current auth flow service only owns GitHub Copilot device flow. OpenAI Codex needs a separate browser callback flow with PKCE, localhost or BLB-hosted callback handling, explicit timeout behavior, manual fallback, and server-side state validation. OpenAI's Codex app-server README now confirms an official product-level auth lifecycle with browser flow, device code flow, completion notifications, logout, and rate-limit inspection. BLB should mirror that lifecycle where practical even if it does not embed the Codex app-server itself.

### OAuth credential persistence and refresh

`AiProvider.credentials` already supports encrypted arrays, which is sufficient for a richer contract. The provider should persist an access token, refresh token, expiry timestamp, and the ChatGPT account identifier required by the downstream transport. Runtime resolution should refresh expired credentials and write the updated credential bag back to the provider record or a dedicated refresh path before requests are sent.

### Dedicated Codex transport

OpenClaw does not route subscription-backed Codex traffic to `https://api.openai.com/v1`. It uses `https://chatgpt.com/backend-api` plus provider-specific headers, including the account identifier extracted from the OAuth token. BLB should therefore treat OpenAI Codex as a distinct API family with its own request mapper and protocol client behavior instead of reusing the standard OpenAI Responses assumptions.

### Honest admin UX

The admin setup flow should present `OpenAI Codex` as a subscription-backed provider with browser sign-in, not as an API-key variant of OpenAI. Copy should explain that the provider depends on an undocumented external contract and may break without notice. If the transport becomes unavailable, BLB should surface a clear provider-specific failure instead of generic "invalid API key" language.

## Design Decisions

### Use a dedicated provider key instead of overloading `openai`

`openai` in BLB already means the public OpenAI API platform. Reusing that provider for ChatGPT-backed subscription traffic would blur billing, auth, runtime, and support expectations. `openai-codex` should be a distinct provider key and a distinct runtime path.

### Do not rely on the generic `oauth` provider path

The current generic path is incomplete: the UI treats `oauth` as API-key-optional, while the registry still resolves most such providers to `GenericApiKeyDefinition`. We should not compound that mismatch by shoving OpenAI Codex into it. The right fix is to add a dedicated provider definition now and only generalize shared OAuth primitives after the first real OAuth provider beyond GitHub Copilot exists in BLB.

### Treat this as an unsupported compatibility integration

The observed OpenClaw flow shows a workable browser OAuth implementation, and the Codex app-server README confirms that OpenAI itself supports ChatGPT browser and device-code login modes in Codex-owned clients. Even so, BLB still lacks a published third-party OAuth contract from OpenAI. BLB should ship this only if the product is willing to own that support risk. Naming, docs, and failure handling should state that reality plainly instead of implying official OpenAI support.

### Prefer BLB-owned OAuth infrastructure over a Codex CLI dependency

OpenClaw can optionally import Codex CLI credentials, but that is not its main path and should not be BLB's. BLB should own the login flow directly so web users are not required to install another client or share machine-local auth state with the application.

### Mirror the Codex app-server account lifecycle in BLB

The Codex app-server README is the most authoritative public signal we have for the expected user lifecycle. BLB should model its provider UX and state machine around the same concepts: start login, complete login asynchronously, surface auth mode and plan type, support logout, and leave room for ChatGPT rate-limit inspection. That gives BLB a cleaner contract than copying OpenClaw's implementation details alone.

### Separate token refresh from request transport

Refreshing OAuth credentials and building request headers are related but different responsibilities. The provider definition should resolve fresh runtime config from stored credentials, while the transport layer should consume the resolved config and remain focused on the ChatGPT backend protocol.

### Start with curated models, not blind `/models` discovery

BLB's standard provider discovery assumes a bearer token plus `GET /models`. That assumption is unsafe for this transport. The first version should use a curated set of supported Codex models and only add live discovery if BLB verifies the backend contract and failure modes end to end.

## Public Contract

- BLB should expose `openai-codex` as a provider distinct from `openai`.
- Provider setup should support browser OAuth connect, reconnect, and token refresh state rather than asking the user to paste an API key.
- Persisted credentials should include `access_token`, `refresh_token`, `expires_at`, and `account_id`; any optional token metadata should live in the same encrypted credential bag.
- Runtime resolution should yield the ChatGPT backend base URL plus any required headers and refreshed bearer token material through `ResolvedProviderConfig`.
- The runtime should treat Codex subscription traffic as a dedicated API family with its own mapper and protocol behavior.
- Model selection should start from a curated Codex model list rather than generic live discovery.
- Provider state should be able to surface ChatGPT auth mode, plan type, and login-in-progress status in a way that matches the official Codex app-server lifecycle.
- UI copy and logs should say "OpenAI Codex OAuth" or "Codex subscription" rather than "OpenAI API key" when this provider fails.

## Phases

### Phase 1 — Establish the provider boundary

Goal: add a truthful provider contract before building the OAuth flow.

- [ ] Add an `openai-codex` provider overlay entry with subscription-oriented display metadata instead of treating it as plain `openai`
- [ ] Introduce a dedicated `OpenAiCodexDefinition` and register it in `ProviderDefinitionRegistry`
- [ ] Define the persisted credential shape and runtime metadata needed for token refresh and request headers
- [ ] Remove any admin copy that would misdescribe the provider as an API-key-based OpenAI connection

### Phase 2 — Build browser OAuth support

Goal: make BLB able to sign a user into OpenAI Codex without depending on Codex CLI state.

- [ ] Add a provider-specific auth flow service for OpenAI Codex with PKCE, state validation, timeout handling, and manual fallback
- [ ] Add callback routing and controller/service plumbing for the browser OAuth round-trip
- [ ] Model the login lifecycle after Codex app-server semantics: start, completion notification/state transition, cancel or expiry, logout, and visible plan-type updates
- [ ] Persist refreshed credential material securely into `AiProvider.credentials`
- [ ] Add reconnect and expired-session handling so operators can recover without deleting the provider

### Phase 3 — Add the Codex runtime transport

Goal: make runtime requests use the ChatGPT backend contract instead of the public OpenAI API path.

- [ ] Add a dedicated API family or protocol branch for OpenAI Codex traffic
- [ ] Route Codex requests to `https://chatgpt.com/backend-api` with the required authorization and account headers
- [ ] Refresh expired OAuth credentials during runtime resolution before the request is sent
- [ ] Seed a curated list of supported Codex models and bypass generic `/models` discovery for the first version
- [ ] Surface provider-specific errors when the undocumented backend contract changes or rejects the session

### Phase 4 — Harden admin UX and verification

Goal: make the feature operable and supportable despite the external risk.

- [ ] Update the provider setup and help surfaces to explain the browser OAuth flow and the unsupported-contract risk
- [ ] Surface plan type and login status in the admin UI when available, following the Codex app-server account model
- [ ] Add focused tests for provider definition validation, OAuth callback state handling, credential refresh, and runtime resolution
- [ ] Add runtime or diagnostic coverage for expired refresh tokens, missing account IDs, and transport-level rejections
- [ ] Document operator guidance for reconnecting or disabling the provider if OpenAI changes the external contract

### Phase 5 — Revisit generic OAuth support after the first provider lands

Goal: generalize only after BLB has one full OAuth provider path working end to end.

- [ ] Audit the current `auth_type: oauth` path and decide which pieces should become reusable OAuth primitives
- [ ] Move any now-proven shared logic out of the OpenAI Codex implementation without weakening the provider boundary
- [ ] Keep unsupported-provider-specific assumptions out of the generic provider definition path
