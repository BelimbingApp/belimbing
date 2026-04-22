# OpenAI Codex OAuth Provider

**Agent:** Codex
**Status:** Phase 5 complete; manual localhost fallback implemented; optional external-bearer escape hatch pending
**Last Updated:** 2026-04-22
**Sources:** `AGENTS.md`, `docs/plans/AGENTS.md`, `app/Base/AI/Config/ai.php`, `app/Modules/Core/AI/Contracts/ProviderDefinition.php`, `app/Modules/Core/AI/Definitions/GenericApiKeyDefinition.php`, `app/Modules/Core/AI/Livewire/Providers/ProviderSetup.php`, `app/Modules/Core/AI/Models/AiProvider.php`, `app/Modules/Core/AI/Services/ProviderAuthFlowService.php`, `app/Modules/Core/AI/Services/ProviderDefinitionRegistry.php`, `app/Modules/Core/AI/Values/ResolvedProviderConfig.php`, `resources/core/views/livewire/admin/ai/providers/provider-setup.blade.php`, `/home/kiat/repo/openclaw/docs/.local/openai-codex-oauth-flow-pseudocode.md`, `/home/kiat/repo/openclaw/src/plugins/provider-openai-codex-oauth.ts`, `/home/kiat/repo/openclaw/src/agents/cli-credentials.ts`, `/home/kiat/repo/openclaw/node_modules/@mariozechner/pi-ai/dist/utils/oauth/openai-codex.js`, `/home/kiat/repo/openclaw/node_modules/@mariozechner/pi-ai/dist/providers/openai-codex-responses.js`, `https://github.com/openai/codex/blob/1dcea729d33ac936b8207ffccae7a0c4cb6b4ff4/codex-rs/app-server/README.md`, `https://github.com/openai/codex/tree/1dcea729d33ac936b8207ffccae7a0c4cb6b4ff4/codex-rs/login/src/auth`

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

The current generic path is incomplete: the UI treated `oauth` as API-key-optional, while the registry resolved most such providers to `GenericApiKeyDefinition`. We should not compound that mismatch by shoving OpenAI Codex into it. The right fix is to add a dedicated provider definition first, then generalize only the proven shared pieces. Phase 5 therefore extracts durable OAuth auth-state primitives and introduces a truthful `GenericOAuthDefinition`, rather than broadening the generic API-key path.

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

Implementation note: provider-specific discovery policy should live on the provider definition boundary rather than in `ModelDiscoveryService` branching on provider names. That keeps curated or non-HTTP discovery rules localized to the provider that owns them.

## Public Contract

- BLB should expose `openai-codex` as a provider distinct from `openai`.
- Provider setup should support browser OAuth connect, reconnect, and token refresh state rather than asking the user to paste an API key.
- Persisted credentials should include `access_token`, `refresh_token`, `expires_at`, and `account_id`; any optional token metadata should live in the same encrypted credential bag.
- Runtime resolution should yield the ChatGPT backend base URL plus any required headers and refreshed bearer token material through `ResolvedProviderConfig`.
- The runtime should treat Codex subscription traffic as a dedicated API family with its own mapper and protocol behavior.
- Model selection should start from a curated Codex model list rather than generic live discovery.
- Provider state should be able to surface ChatGPT auth mode, plan type, and login-in-progress status in a way that matches the official Codex app-server lifecycle.
- UI copy and logs should say "OpenAI Codex OAuth" or "Codex subscription" rather than "OpenAI API key" when this provider fails.

## Concrete State & Data Contract (Codex-aligned)

Codex's login/auth module is structured around a manager + storage + revoke split. To avoid “oauth is just a label”, BLB should make the provider auth lifecycle explicit and persistable.

### Persisted credentials (encrypted `AiProvider.credentials`)

- **Required**
  - **`access_token`**: string
  - **`refresh_token`**: string
  - **`expires_at`**: RFC3339 string (UTC) or unix seconds (pick one and standardize)
  - **`account_id`**: string (required by downstream ChatGPT backend transport)
- **Optional**
  - **`id_token`**: string|null (if returned)
  - **`scope`**: string|null
  - **`token_type`**: string|null
  - **`raw`**: array<string,mixed>|null (last-resort compatibility bucket; keep provider-local)

### Provider auth state (persisted; choose location in Phase 1)

Add an explicit lifecycle state so UI/ops are deterministic.

- **`auth.status`**: `disconnected | pending | connected | expired | revoked | error`
- **`auth.mode`**: `browser_pkce | device_code | external_bearer`
- **`auth.started_at`**: datetime|null
- **`auth.completed_at`**: datetime|null
- **`auth.last_refresh_at`**: datetime|null
- **`auth.last_error_code`**: string|null
- **`auth.last_error_message`**: string|null
- **`auth.plan_type`**: string|null (optional; surfaced when known)

Implementation note: this section covers durable provider state only. Pending OAuth handshake secrets such as PKCE verifier, `state`, and short-lived callback expiry should stay ephemeral in server-side cache or session storage, following BLB's existing auth-flow pattern, rather than being persisted as provider state. Phase 1 must decide where the durable state lives: `AiProvider.connection_config` is the simplest v1 option unless a stronger reason appears for a dedicated column or table.

## Phases

### Phase 1 — Establish the provider boundary

Goal: add a truthful provider contract before building the OAuth flow.

- [x] Add an `openai-codex` provider overlay entry with subscription-oriented display metadata instead of treating it as plain `openai`
- [x] Introduce a dedicated `OpenAiCodexDefinition` and register it in `ProviderDefinitionRegistry`
- [x] Define the persisted credential shape and runtime metadata needed for token refresh and request headers
- [x] Decide and implement the durable auth state location (`auth.status`, `auth.mode`, timestamps, last error) without persisting pending OAuth handshake secrets
- [x] Remove any admin copy that would misdescribe the provider as an API-key-based OpenAI connection

### Phase 2 — Build browser OAuth support

Goal: make BLB able to sign a user into OpenAI Codex without depending on Codex CLI state.

#### Phase 2.1 — Minimal route surface

- [x] Add the minimum HTTP route surface required for browser OAuth:
  - [x] **`GET /admin/ai/providers/openai-codex/auth/callback`** → validate `state`, exchange `code`, persist credentials, mark connected
- [x] Keep start, cancel, logout, and manual refresh as provider-setup Livewire actions unless implementation proves they need dedicated controllers or public POST endpoints.

#### Phase 2.2 — Service boundary (Codex-aligned “manager/storage/revoke”)

- [x] Introduce `OpenAiCodexAuthManager` (single public entrypoint used by controllers and runtime):
  - [x] `startLogin(...)` (build authorize URL, create pending session)
  - [x] `completeCallback(...)` (state validation + code exchange)
  - [x] `logout(...)` (local disconnect semantics; remote revoke not assumed)
- [x] Introduce `OpenAiCodexAuthStorage` responsible for persisting:
  - [x] credential bag updates (`AiProvider.credentials`) and auth status transitions
- [x] Keep pending session material (pkce verifier, `state`, started_at, expiry) in ephemeral cache storage owned by the auth manager rather than in durable provider state.
- [x] Add remote revoke support only if BLB verifies a stable upstream contract; otherwise logout is local disconnect semantics.

#### Phase 2.3 — State transitions & operator behaviors

- [x] On start: `disconnected/error/expired` → `pending` with `auth.mode=browser_pkce`, set `started_at`, set TTL.
- [x] On callback success: `pending` → `connected`, set `completed_at`, clear pending session.
- [x] On callback failure: `pending` → `error`, set `last_error_*`.
- [ ] On timeout/cancel: `pending` → `disconnected`. (not needed in v1; callback state expires by TTL)
- [x] On logout: `connected/expired/error` → `disconnected` and clear `credentials`.

#### Phase 2.4 — Manual fallback & “external bearer” escape hatch

- [x] Add a manual localhost-callback fallback so BLB can emulate OpenClaw's working OAuth contract even without a local listener:
  - [x] Start the browser flow with OpenClaw-compatible authorize parameters (`redirect_uri=http://localhost:1455/auth/callback`, `originator=openclaw`).
  - [x] Keep the setup page open and let the operator paste the full localhost redirect URL back into BLB for final code exchange.
- [ ] Add an **operator-only** fallback mode to set `auth.mode=external_bearer` for diagnostics:
  - [ ] Accept a bearer token/session token and store it encrypted with a short expiry.
  - [ ] Clearly label as non-primary and not “supported”; do not auto-refresh it.

### Phase 3 — Add the Codex runtime transport

Goal: make runtime requests use the ChatGPT backend contract instead of the public OpenAI API path.

Implementation note: the Codex transport now shares the common Responses parsing/streaming base with standard OpenAI Responses, but owns its own endpoint suffix (`/codex/responses`). The curated fallback model seed also now tracks the broader OpenClaw-compatible baseline (`gpt-5.4`, `gpt-5.4-mini`, `gpt-5.2`, `gpt-5.1-codex-mini`), and the Codex setup page resyncs that curated set for already-connected providers so older one-model connections self-heal.

#### Phase 3.1 — Resolve config (refresh-before-use)

- [x] Add a provider-specific resolver for `openai-codex`:
  - [x] If `credentials.expires_at` is within a skew window (e.g. 60s), call `OpenAiCodexAuthManager::refresh()` and persist updated credentials before building runtime config.
  - [x] If refresh fails, mark provider `auth.status=expired` (or `error`) and return a provider-specific failure (not “invalid API key”).

#### Phase 3.2 — Transport contract (dedicated API family)

- [x] Add a dedicated API family / protocol client for Codex:
  - [x] Base URL: **`https://chatgpt.com/backend-api`**
  - [x] Required request material in `ResolvedProviderConfig`:
    - [x] `base_url`
    - [x] `authorization: Bearer <access_token>`
    - [x] `account_id` (derived from JWT for `chatgpt-account-id` header)
  - [x] Ensure the transport does not reuse “OpenAI Responses” assumptions (no `/v1/models` discovery by default).

#### Phase 3.3 — Model catalog (curated v1)

- [x] Seed a curated Codex model list in the provider definition (or `ModelCatalogService` overlay) and disable generic `/models` discovery for v1.
- [x] Add a “contract changed” error bucket in logs/UI when transport rejects the session or headers.

### Phase 4 — Harden admin UX and verification

Goal: make the feature operable and supportable despite the external risk.

- [x] Update the provider setup and help surfaces to explain the browser OAuth flow and the unsupported-contract risk
- [x] Surface plan type and login status in the admin UI when available, following the Codex app-server account model
- [x] Add a **“Verify connection”** admin action:
  - [x] Calls a confirmed, low-impact ChatGPT backend request that BLB already understands how to interpret safely using the resolved config
  - [x] Records a structured diagnostic result and updates `auth.last_error_*` on failure
- [x] Add focused tests for provider definition validation, OAuth callback state handling, logout/revoke, credential refresh, and runtime resolution
  - [x] Cover provider definition validation, OAuth callback state handling, credential refresh, runtime resolution, and setup-page diagnostics
  - [x] Add a dedicated logout/revoke-state regression test
- [x] Add runtime/diagnostic coverage for:
  - [x] expired refresh tokens → status becomes `expired` and UI prompts reconnect
  - [x] missing `account_id` → provider-specific validation error
  - [x] transport-level rejections → provider-specific error copy + structured logs
- [x] Document operator guidance for reconnecting or disabling the provider if OpenAI changes the external contract

### Phase 5 — Revisit generic OAuth support after the first provider lands

Goal: generalize only after BLB has one full OAuth provider path working end to end.

- [x] Audit the current `auth_type: oauth` path and decide which pieces should become reusable OAuth primitives
- [x] Move any now-proven shared logic out of the OpenAI Codex implementation without weakening the provider boundary
- [x] Keep unsupported-provider-specific assumptions out of the generic provider definition path
