# integration-external-system-observability

**Status:** In Progress — Phases 1–5 complete; Phase 6 remains deferred.
**Last Updated:** 2026-05-05
**Sources:** `app/Base/Integration`, `app/Base/AI/Services/ProviderDiscoveryService.php`, `app/Modules/Core/AI/Services/ModelDiscoveryService.php`, `app/Modules/Core/AI/Services/ControlPlane/WireLogger.php`, `docs/plans/ai-control-plane-debuggability.md`, `docs/plans/ham/01-ebay-car-parts-operations.md`
**Agents:** Codex/GPT-5

## Problem Essence

BLB has external integrations, but the observability boundary is inconsistent. `Base/Integration` already exists because eBay needed shared HTTP/OAuth primitives, but external-system traffic still does not share one complete request/response ledger across eBay, AI provider discovery, OAuth token exchange, catalog sync, messaging, webhooks, file/SFTP imports, and future connectors.

## Desired Outcome

`Base/Integration` becomes the canonical gate for BLB communication with every external system. Every exchange is traceable by provider/system, operation, owner scope, request, response, timing, retry behavior, redaction policy, authz boundary, and outcome; operators can inspect those exchanges when diagnosing marketplace/API behavior, OAuth failures, provider drift, catalog sync issues, or any other connector failure.

## Top-Level Components

### Outbound exchange ledger

A durable `Base/Integration` ledger records outbound external exchanges for every connector class, not only AI. It should capture enough data to answer: what did BLB send, to whom, under which owner/scope, with which operation label, what came back, how long it took, whether retries happened, and which application action caused it.

The first table is intentionally outbound-only: `base_integration_outbound_exchanges`. Inbound webhooks, file drops, and message-bus deliveries have different evidence and lifecycle needs, so they should get a concrete design when the first inbound channel needs them.

### Protocol-neutral Integration gateway

A gateway service replaces ad hoc `Http::...` calls for non-LLM external traffic. It accepts an operation descriptor, applies shared timeout/retry/redaction policy, executes the request, records the exchange, and returns a response object that callers can still parse in their own domain layer.

The ledger should not be HTTP-only. Each row records `transport` (`http`, `stdio`, `websocket`, `sftp`, `file`, `message_bus`, etc.), `protocol` (`rest`, `json_rpc`, `graphql`, `soap`, `oauth2`, `file_transfer`, `custom`), a normalized `endpoint`, and a generic `protocol_operation`. Protocol-specific facts start in `metadata`; they are promoted to columns only after real query needs emerge.

### Redaction and retention policy

The ledger must be safe by construction. Authorization headers, API keys, refresh tokens, cookies, and provider-specific secrets are always redacted. Body capture is operation-controlled because model discovery and catalog sync are safe to retain in full, while OAuth token responses and customer marketplace data require stricter masking and shorter retention.

### Operator inspection surface

Operators need a first admin surface for recent outbound exchanges, filtered by system/provider, operation, transport, protocol, status, owner scope, and correlation ID. It should support raw drill-down for retained payloads without turning every module into its own debugging UI. Access is capability-gated; authz controls who may list exchanges and who may inspect retained payloads, but authz does not replace redaction.

## Design Decisions

### Make Integration the transport gate, not the domain owner

`Base/Integration` should not interpret eBay listings, AI model catalogs, OAuth account state, or provider capabilities. It records and governs external exchanges. Domain modules still own request meaning, response parsing, retries that are business decisions, and user-facing recovery messages.

### Keep LLM calls out of the first scope

AI LLM calls already have a dedicated wire logger that captures mapped request payloads, raw response bodies, SSE lines, timing, and raw drill-down. Replacing that in the first Integration observability pass would add risk without improving the immediate blind spot. The near-term bridge is to leave LLM wire logging as-is and design the exchange ledger so future LLM transport can reference or share the same primitives later.

### WireLogger stays a sibling diagnostic surface for now

AI run inspection and Integration outbound-exchange inspection are sibling surfaces in the first implementation. The AI Control Plane remains the run-level diagnostic view for LLM calls, tool loops, and SSE chunks; the Integration Exchanges surface becomes the general outbound ledger for non-LLM external systems. A future merge or shared storage migration needs its own plan after the Integration ledger proves useful, because replacing the existing WireLogger early would couple two different diagnostic workflows and slow down the general Integration work.

### Provider discovery is the first proving slice

Provider model sync is the first proving slice because it is small, isolated, high-value, and currently opaque when providers change behavior. `ProviderDiscoveryService::discoverModels()` sends `GET /models`; the ledger should show base URL, provider name, status code, response payload, latency, `fallback_used`, and `fallback_reason` when Core AI falls back to the catalog path. eBay remains the next adoption slice because it already uses `Base/Integration` HTTP/OAuth primitives and has real operator value when API calls fail.

### Initial audit findings

The first audit found several outbound call families: eBay marketplace reads and policy/location pulls through the old `IntegrationHttpClientFactory`; OAuth token exchange through `OAuth2Client`; AI provider discovery, model catalog sync, Copilot device flow/token exchange, Codex OAuth, web fetch/search, pricing snapshot refresh, and Geonames downloads through direct `Http` calls or service-local wrappers. This validates the gateway direction and keeps adoption incremental: provider discovery first, eBay reads second, then the remaining direct `Http` call families.

### Exchange records need both correlation and ownership

External calls happen because of a user action, job, schedule, webhook, or lifecycle action. Records should carry correlation IDs that tie an outbound exchange to an operation run, plus owner scope where available: company, employee, provider, channel account, or module-specific entity. Scope must be nullable so framework-level calls like `models.dev` catalog refresh still fit honestly.

The first schema should use a polymorphic owner scope (`owner_type`, `owner_id`) plus optional module context fields rather than a wide table of nullable foreign keys. This keeps the gateway general across eBay accounts, AI providers, catalog jobs, and future connectors without making the ledger schema depend on every module that adopts it.

Correlation fields should include a BLB-generated exchange ID and operation correlation ID, and reserve room for W3C tracing by storing `traceparent`/`tracestate` when present. BLB does not need to adopt distributed tracing in the first slice, but the field names should not block it later.

### Protocol-specific fields start in metadata

The schema should carry one generic protocol search field, `protocol_operation`, and put protocol-specific detail in `metadata` until query patterns prove otherwise. Examples:

- REST over HTTP: `transport=http`, `protocol=rest`, `endpoint=https://...`, `protocol_operation=GET /sell/inventory/v1/location`, `metadata.http_method=GET`
- JSON-RPC over HTTP or stdio: `protocol=json_rpc`, `protocol_operation=tools/list`, `metadata.rpc_id=42`, `metadata.rpc_error_code=-32601`
- GraphQL: `protocol=graphql`, `protocol_operation=GetOrders`, `metadata.operation_type=query`
- SFTP/file transfer: `transport=sftp`, `protocol=file_transfer`, `protocol_operation=put`, `endpoint=sftp://host/path`, `metadata.remote_path=/orders/file.csv`

Do not add child tables until a protocol has rich, high-cardinality, query-heavy fields. The first implementation stays one outbound table plus metadata.

### Retry behavior must be explicit per operation

The current `IntegrationHttpClientFactory` retries every JSON request three times. That is too blunt for a canonical gateway. Retry policy should be an explicit operation setting: safe idempotent reads can retry; OAuth token exchange, writes, and provider operations with side effects should default to no retry unless the caller opts in with clear semantics.

`IntegrationHttpClientFactory` should be replaced, not wrapped. Keeping both APIs would preserve the blanket-retry footgun and make it unclear whether a call is observable. Existing callers should move to the operation-aware gateway as they are adopted.

### Raw payload storage should start in the database with size guards

Most non-LLM integration payloads are small enough for database storage, and database-backed filtering is the fastest route to a useful operator surface. Store normalized metadata in columns and retained request/response payload previews in JSON/text fields with explicit size caps and truncation markers.

The first cap is 64 KB per retained request/response body. Rows carry truncation flags and original byte counts where available. This deliberately differs from the AI WireLogger's file-based storage because non-LLM exchange payloads are typically smaller and need cross-system filtering. Large retained bodies can move to storage objects later only after a concrete integration proves the need.

Retention should be operation-class based from the start. OAuth/token exchanges should have the shortest retained-payload TTL and strictest body redaction. Catalog/model discovery can keep longer payload previews because the bodies are low-sensitivity operational evidence. Marketplace/customer data sits between those and should have a shorter default than catalog data.

### Authz gates access, redaction protects the record

Ledger access should require explicit admin capabilities. A broad capability can list exchange metadata, a narrower payload capability can inspect retained request/response bodies, and a separate cleanup/delete capability controls lifecycle pruning. This authz boundary is necessary but not sufficient: secrets still remain redacted in stored and displayed ledger payloads because exchange rows can flow into backups, exports, screenshots, and future reporting paths.

### Inbound integration events belong in the same observability model

This plan starts with outbound HTTP because that is the current gap. The naming and schema should leave room for inbound external-system events, but inbound webhooks, file drops, SFTP/EDI imports, and message-bus deliveries should wait for a concrete channel that needs them.

## Public Contract

- Domain modules call `Base/Integration` for non-LLM outbound external communication instead of using Laravel `Http` directly.
- Each outbound exchange has a stable ID that can be attached to module logs, sync results, jobs, and UI diagnostics.
- Each outbound exchange records provider/system, operation, transport, protocol, protocol operation, endpoint, request headers/body according to redaction policy, response status/body according to retention policy, duration, retry count, outcome, error class/message, and timestamps.
- Secret redaction is centralized and mandatory. Callers may add stricter operation-level redaction, but cannot opt out of core secret redaction.
- Exchange metadata, retained payload inspection, and cleanup/delete actions are capability-gated separately.
- AI LLM call auditing remains on the existing AI wire log until a later migration plan explicitly replaces or merges it.

## Phases

### Phase 1 — Define the Integration observability contract

Goal: agree the external-system observability boundary before moving code. This phase is a hard prerequisite for Phase 2; the gateway schema and service contract should not be built until the call-site audit and concrete storage/authz decisions are recorded.

- [x] Audit current external call sites outside AI LLM runtime: eBay, OAuth, AI provider discovery, model catalog sync, OpenAI Codex OAuth, GitHub Copilot auth, web fetch/search, messaging adapters, and any direct `Http` usage. {Codex/GPT-5}
- [x] Classify each outbound call by transport, protocol, operation type, side-effect risk, payload sensitivity, and expected payload size. {Codex/GPT-5}
- [x] Define the outbound exchange record fields, including `transport`, `protocol`, `protocol_operation`, `endpoint`, `fallback_used`, and `fallback_reason` for operations such as provider discovery where a fallback path may answer the user action. {Codex/GPT-5}
- [x] Define retained-payload size caps, truncation markers, and original-size fields before migration work starts. {Codex/GPT-5}
- [x] Define retention defaults by operation class: OAuth/token, catalog/model discovery, marketplace/customer data, and generic low-sensitivity reads. {Codex/GPT-5}
- [x] Define owner-scope shape, using `owner_type`/`owner_id` unless the audit produces a stronger reason for typed nullable FKs. {Codex/GPT-5}
- [x] Define correlation field names, including BLB operation correlation IDs and optional `traceparent`/`tracestate`. {Codex/GPT-5}
- [x] Define redaction policy and retry-policy vocabulary in prose. {Codex/GPT-5}
- [x] Define the authz capabilities for Integration exchange metadata (`admin.integration_exchange.list`), retained payload inspection (`admin.integration_payload.view`), and cleanup/delete actions (`admin.integration_exchange.delete`). {Codex/GPT-5}
- [x] Update `app/Base/AI/AGENTS.md` and relevant architecture notes so Base AI may use Base Integration for external transport/audit without implying Base AI owns business scope. {Codex/GPT-5}
- [x] Add `app/Base/Integration/AGENTS.md` documenting Integration as the external-system transport/audit gate, including redaction, authz, retry, and ownership rules. {Codex/GPT-5}

### Phase 2 — Build the outbound exchange ledger

Goal: non-LLM outbound HTTP calls can be executed and inspected through one gateway.

- [x] Add `Base/Integration` persistence for exchange metadata and retained payload previews. {Codex/GPT-5}
- [x] Replace `IntegrationHttpClientFactory` with an operation-aware gateway that records every exchange. {Codex/GPT-5}
- [x] Add centralized header/body redaction with tests for authorization headers, API keys, cookies, OAuth token fields, and provider-specific secret names. {Codex/GPT-5}
- [x] Register Integration authz capabilities and protect exchange model/query access through those capabilities. {Codex/GPT-5}
- [x] Add explicit retry policy support and remove blanket retries from the default path. {Codex/GPT-5}
- [x] Add focused tests proving success, HTTP failure, connection failure, truncation, redaction, and retry recording. {Codex/GPT-5}

### Phase 3 — Prove the gateway with provider discovery

Goal: one real external-system path uses the gateway end to end before broader adoption starts.

- [x] Move AI provider model discovery (`GET /models`) through the gateway while preserving `Base AI` response parsing and `Core AI` sync behavior. {Codex/GPT-5}
- [x] Record provider-discovery fallback columns (`fallback_used`, `fallback_reason`) when Core AI falls back from provider API discovery to catalog import. {Codex/GPT-5}
- [x] Link provider setup/model-sync diagnostics to the relevant exchange IDs. {Codex/GPT-5}
- [x] Verify with focused tests that successful discovery, provider HTTP failure, provider connection failure, empty discovery, and catalog fallback all produce useful exchange records. {Codex/GPT-5}

### Phase 4 — Adopt the gateway in existing integrations

Goal: existing external integrations gain observability without changing their domain behavior, one connector family at a time after the provider-discovery proving slice lands.

- [x] Move eBay OAuth token exchange and API reads through the gateway. {Codex/GPT-5}
  - [x] eBay listing/order/policy/location API reads now use the gateway. {Codex/GPT-5}
  - [x] eBay OAuth authorization-code and refresh-token exchanges now use `OAuth2Client` backed by the gateway. {Codex/GPT-5}
- [x] Move model catalog sync (`models.dev`) through the gateway so catalog refresh failures include exchange IDs. {Codex/GPT-5}
- [x] Move GitHub Copilot device-flow and token-exchange calls through the gateway with strict token redaction. {Codex/GPT-5}
- [x] Move OpenAI Codex OAuth token exchange/refresh calls through the gateway with strict token and account redaction. {Codex/GPT-5}

### Phase 5 — Add operator inspection

Goal: operators can diagnose external-system failures without reading application logs.

- [x] Add an admin Outbound Exchanges page with filters for system/provider, operation, transport, protocol, status, time range, and owner scope. {Codex/GPT-5}
- [x] Add exchange detail view with redacted request/response, timing, retry attempts, truncation status, and linked domain context. {Codex/GPT-5}
- [x] Enforce separate authz checks for exchange list access and retained payload access. {Codex/GPT-5}
- [x] Link marketplace/eBay sync and OAuth surfaces to the relevant exchange IDs. {Codex/GPT-5}
- [x] Add lifecycle cleanup for retained exchange payloads according to retention policy. {Codex/GPT-5}

### Phase 6 — Extend when a concrete channel needs it

Goal: keep the observability model open for inbound and non-HTTP integrations without promising speculative delivery.

- [ ] Define inbound webhook exchange records only when the first concrete webhook receiver lands.
- [ ] Add gateway/ledger support for file import/export, SFTP/EDI, or message-bus integrations only when the first concrete channel needs it.
- [ ] Revisit whether AI LLM wire logs should remain separate, link to Integration exchange rows, or migrate to shared Integration storage.
