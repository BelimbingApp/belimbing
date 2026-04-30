# AI Token Usage Telemetry

**Agents:** Amp/sonnet-4-5
**Status:** In Progress (Phase 1 first slice landed)
**Last Updated:** 2026-04-30
**Sources:** `app/Modules/Core/AI/Services/ControlPlane/WireLogReadableFormatter.php`, `app/Modules/Core/AI/Services/AgenticFinalResponseStreamer.php`, `app/Modules/Core/AI/Services/AgenticToolLoopStreamReader.php`, `app/Modules/Core/AI/Services/MessageManager.php`, `app/Modules/Core/AI/Services/ControlPlane/RunRecorder.php`, `app/Base/AI/Services/Protocols/ChatCompletionsProtocolClient.php`, `app/Base/AI/Services/Protocols/AnthropicMessagesProtocolClient.php`, `app/Base/AI/Services/Protocols/AbstractResponsesProtocolClient.php`, `app/Base/AI/Services/Protocols/OpenAiCodexResponsesProtocolClient.php`, `app/Modules/Core/AI/Database/Migrations/0200_02_01_000013_create_ai_runs_table.php`, `docs/plans/ai-control-plane-debuggability.md`, `docs/plans/ai-operations-dashboard.md`

## Problem Essence

Provider streams emit a final `usage` chunk carrying prompt, completion, and total token counts (and on richer models, cached-prompt and reasoning sub-totals). BLB's wire-log formatter logs that key as an "Unknown delta keys" anomaly. Worse, our normalized capture in `AgenticToolLoopStreamReader` overwrites a single `streamState['usage']` slot per stream iteration and persists only the *last* iteration's totals onto `ai_runs.prompt_tokens` / `completion_tokens` — so a run that does five LLM calls in a tool-call loop reports the cost of one. We also lack a usable pricing source, lack any visibility into per-call provider rate-limit headroom, and have no UI surfaces to consume any of this even when captured.

## Desired Outcome

Every LLM call records a per-call usage row (prompt incl. cached split, completion incl. reasoning split, total) with a costed snapshot whose pricing source is auditable. Run-level aggregates (`ai_runs.prompt_tokens`, `cost_total_cents`, etc.) are derived from per-call rows and are correct across multi-call tool loops. Provider rate-limit headers from each call are captured. Operators can see usage, cost, and capacity breakdowns per call, per run, per session, per agent, per model, and per tool through visible UI surfaces — Run Detail, Control Plane Run Inspector, the chat console badge, and the AI Operations dashboard. Budgets can cap any combination of cost, tokens, or request count and surface as in-app warnings and admin badges.

## Top-Level Components

1. **Per-call capture (`ai_run_calls` table)** — one row per LLM call, parented by `ai_runs`. Carries usage, pricing snapshot, latency, finish reason, captured rate-limit headers, and a pointer to the wire-log entry range. Run-level aggregates on `ai_runs` become *derived*.

2. **Protocol-aware terminal detection** — every protocol client reports a `call_terminal` signal (with `usage`, `finish_reason`, and rate-limit headers) at the right SSE event for that protocol. The recorder appends one `ai_run_calls` row per terminal.

3. **Pricing source registry** — `PricingSourceRegistry` resolves a model rate via three resolvers in priority order: `Override` → `ProviderApi` (OpenRouter `/api/v1/models`, etc.) → `LiteLLMSnapshot` (community-maintained `model_prices_and_context_window.json`, refreshed nightly into a local `ai_pricing_snapshots` table). Pricing source and version are persisted on each call so historic dashboards stay correct.

4. **Provider Capacity Telemetry** — capture rate-limit headers (`x-ratelimit-remaining-tokens`, `anthropic-ratelimit-tokens-remaining`, OpenRouter `X-RateLimit-Remaining`) from each response; for OpenRouter, optionally probe `/api/v1/auth/key` for credit balance. Subscription products (Codex/Claude Code/Copilot) get a flag-gated, best-effort probe with documented "may break" semantics.

5. **Run-level UI** — Usage panel on **Run Detail** and inside the Control Plane Run Inspector showing per-call rows (calls table) and totals; per-attempt usage chip in the wire-log readable view tied to the matching `ai_run_calls` row.

6. **Session-level UI** — Live token + cost meter on the **chat console** header; expand-on-click reveals the per-run / per-call breakdown for the session.

7. **Macro UI (Operations Dashboard)** — Token/cost time-series, per-model and per-tool spend charts, top-N costliest runs/users, cache-hit ratio per agent, **provider capacity panel** (remaining requests/tokens per provider/model with reset countdown). Each chart deep-links into the Control Plane via the existing `from=operations&returnTo=…` contract.

8. **Multi-metric budgets & alerts** — `ai_budgets` rows can cap `cost_cents`, `total_tokens`, `prompt_tokens`, `completion_tokens`, or `request_count`, over `rolling_minute|hour|day|calendar_month|custom_window` periods. Pre-call check inside `AgenticRuntime`; soft-warn banner in the chat console at 80%, hard block at 100%; status badge on Operations.

9. **Optimization signals** — Prompt-template prefix hash per call; aggregated cache-hit ratio and prompt-size trend per template, surfaced as a "Prompts" tab on the operations dashboard with drill-down.

## Design Decisions

### Per-call rows in `ai_run_calls`, run-level fields become derived

A run today wraps a tool-call loop with N LLM calls. Storing one `prompt_tokens` slot per run (and overwriting it per iteration as `AgenticToolLoopStreamReader` does today) is structurally wrong — multi-call loops persist only the last iteration's tokens. Fix: introduce `ai_run_calls` (one row per LLM call) carrying full usage, latency, cost, finish reason, rate-limit headers, pricing source/version, and an `attempt_index` so the wire-log formatter's "attempts" view maps 1:1 onto rows. `ai_runs` keeps fields like `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost_total_cents`, `call_count`, but they are recomputed from the child rows by `RunRecorder` (and via a backfill action for legacy rows where `call_count = 1` is assumed). This pattern matches how BLB already treats `ai_chat_turn_events` as the source of truth for turn timelines.

### Terminal detection is per-protocol; the recorder consumes a normalized signal

Every protocol has a different terminal marker. The wire pipeline already knows them:

- **OpenAI Chat Completions** — final chunk has `choices[0].finish_reason` populated *and* a top-level `usage` object on the same chunk; the stream then emits `data: [DONE]` (handled in `ChatCompletionsProtocolClient`).
- **OpenAI Responses / Codex Responses** — `response.completed` event carries `usage` with `input_tokens`, `output_tokens`, and `*_tokens_details` (handled in `AbstractResponsesProtocolClient` / `OpenAiCodexResponsesProtocolClient`).
- **Anthropic Messages** — `message_stop` is the boundary; `usage.input_tokens` arrives on `message_start`, `usage.output_tokens` accumulates across `message_delta` events (handled in `AnthropicMessagesProtocolClient`).

`AbstractLlmProtocolClient::done()` already emits a normalized `done` event carrying `finish_reason` and `usage`. We extend it to also carry `cached_input_tokens`, `reasoning_tokens`, `total_tokens`, and the captured rate-limit header bundle. The wire-log formatter uses the same protocol-aware boundary to close one "attempt" group, and `RunRecorder` writes one `ai_run_calls` row per `done` event. This keeps protocol differences inside the protocol clients and gives downstream code a single shape to consume.

### Pricing via a resolver chain, never config

Hardcoded prices in `config/*.php` go stale silently and cannot survive multi-tenant or self-hosted deployments. Use a `PricingSourceRegistry` resolved in this order:

1. **Override resolver** — small `ai_pricing_overrides` table for self-hosted models, custom enterprise rates, or to correct an upstream entry.
2. **Provider API resolver** — currently `OpenRouter` (`GET /api/v1/models` returns per-model `pricing.prompt`, `pricing.completion`, `pricing.input_cache_read`, etc.); future providers if/when they ship an API.
3. **LiteLLM snapshot resolver** — nightly fetch of BerriAI's MIT-licensed `model_prices_and_context_window.json` into `ai_pricing_snapshots(model, input_per_token, cached_input_per_token, output_per_token, snapshot_date)`. This is the *de facto* shared OSS pricing source and covers OpenAI, Anthropic, Google, Mistral, Bedrock, Azure, Vertex, Groq, etc.

Each call records `pricing_source` (`override` | `openrouter_api` | `litellm:2026-04-29`) and the resolved per-token rates so re-deriving cost is auditable. Unknown models record `null` cost rather than throwing; the UI labels them honestly. A `RefreshPricingSnapshot` lifecycle action handles the nightly pull; failures fall back to the previous snapshot.

### Multi-metric budgets

Pricing accuracy is best-effort, but token counts are exact. `ai_budgets` rows carry a `metric_kind` enum (`cost_cents` | `total_tokens` | `prompt_tokens` | `completion_tokens` | `request_count`) and a `period_kind` enum (`rolling_minute` | `rolling_hour` | `rolling_day` | `calendar_month` | `custom_window`). Multiple budgets per subject AND together — any single one exceeded triggers the action. This lets an operator set "5 M tokens/month *and* $50 USD/month *and* 50 K tokens/min" simultaneously, and token-based caps stay enforceable when pricing data is missing or stale. Budgets are keyed by subject (`subject_type`, `subject_id`) where subject is `user` or `employee`. `BudgetEvaluator` is called pre-call by `AgenticRuntime`; over-soft-threshold returns a flag (warn the chat UI), over-hard-threshold raises `BudgetExceededException`.

### Provider Capacity Telemetry separates per-call headers from subscription quotas

Two distinct data sources, treated separately:

**Per-call rate-limit headers** — universal, stable, free to capture. Persist on each `ai_run_calls` row: `rate_limit_remaining_requests`, `rate_limit_remaining_tokens`, `rate_limit_reset_at`. Applies to OpenAI (`x-ratelimit-*`), Anthropic (`anthropic-ratelimit-*`), OpenRouter (`X-RateLimit-*`), and most others. The Operations Dashboard renders a Capacity panel from the most-recent value per provider/model, with a small sparkline of recent headroom usage.

**Subscription quotas** — flag-gated, best-effort, may break. OpenRouter is the cleanest case: `GET /api/v1/auth/key` returns `data.usage`, `data.limit`, `data.limit_remaining` for the API key — credit-balance style. For subscription products (OpenAI Codex, Anthropic Claude Code, GitHub Copilot), no documented public quota endpoint exists; the desktop apps query private internal endpoints that have changed without notice. We capture them behind a per-provider toggle (`ai.providers.{name}.subscription_probe.enabled`, default off), persist as a separate table (`ai_provider_quota_snapshots`), and label the UI clearly: "Best-effort, may stop working without notice". OpenRouter probes ship enabled by default; subscription-product probes ship disabled with a config note explaining the risk.

### Honest empty-states

Older runs and runs from providers that do not emit `usage` keep `null` for missing fields. The UI labels this honestly ("not reported by provider") rather than zero-filling, so cost averages are not skewed by unreported runs. Same rule for missing rate-limit headers and missing pricing.

### Drill-down contract reuses existing operations return path

Operations charts deep-link into the Control Plane via the established `from=operations&returnTo=...` query contract introduced in `ai-control-plane-debuggability.md`. New filters extend that contract: `model=`, `tool=`, `prompt_hash=`, `provider=`, `time_range=`. No new navigation system.

## Public Contract

- `App\Modules\Core\AI\Values\CallUsage` — value object: `promptTokens`, `cachedInputTokens`, `completionTokens`, `reasoningTokens`, `totalTokens`, plus rate-limit fields and pricing snapshot fields. Nullable where the provider did not report them.
- `App\Modules\Core\AI\Values\ProviderRateLimit` — `remainingRequests`, `remainingTokens`, `resetAt`, `limitRequests`, `limitTokens`.
- `App\Modules\Core\AI\Services\Pricing\PricingSourceRegistry::resolve(provider, model): ?ResolvedRate` — returns the rate plus `source` and `version` strings, or `null` for unknown models.
- `App\Modules\Core\AI\Services\Pricing\TokenCostCalculator::costFor(CallUsage, ResolvedRate): CallUsage` — fills cost fields.
- `App\Base\AI\Services\Protocols\AbstractLlmProtocolClient::done()` — extended event payload includes `cached_input_tokens`, `reasoning_tokens`, `total_tokens`, `rate_limits`.
- `AiRunCall` Eloquent model with `run()`, `usage()`, `cost()`, `rateLimits()` accessors.
- `AiRun::aggregates(): RunAggregates` — derived totals across calls.
- `MessageManager::sessionUsage(int $employeeId, string $sessionId): SessionUsage` — extended to include `costTotalCents`, `cachedRatio`, `callCount`.
- `WireLogReadableFormatter`: per-attempt overview gains a `usage` block; the `unknown_keys` anomaly no longer fires for `usage`.
- `BudgetEvaluator::evaluate(subject, plannedRequest): BudgetVerdict` with `BudgetVerdict::ok()`, `::softWarn(metric, percent)`, `::hardBlock(metric)`.

## Phases

### Phase 1 — Per-call capture & persistence (UI: run header & calls table)

Goal: `usage` is recognized, parsed, attached to a per-call row, costed, and shown.

- [x] Add `usage` to the recognized OpenAI-style stream keys in `WireLog\StreamAssembler`; lift it onto each stream block as a structured `usage` field
- [x] Migration: create `ai_run_calls` (run_id, attempt_index, provider, model, started_at, finished_at, latency_ms, prompt_tokens, cached_input_tokens, completion_tokens, reasoning_tokens, total_tokens, finish_reason, native_finish_reason, raw_usage jsonb, pricing_source, pricing_version, cost_input_cents, cost_cached_input_cents, cost_output_cents, cost_total_cents, rate_limit_remaining_requests, rate_limit_remaining_tokens, rate_limit_reset_at)
- [x] Migration: extend `ai_runs` with `cached_input_tokens`, `reasoning_tokens`, `total_tokens`, `cost_*_cents`, `pricing_version`, `call_count` (derived from calls)
- [x] Extend `AbstractLlmProtocolClient::done()` docblock to allow the extended usage shape; populate full shape (`prompt`, `cached_input`, `completion`, `reasoning`, `total`) from `ChatCompletionsProtocolClient` for both top-level (OpenAI) and choice-level (Moonshot/Kimi) `usage`
- [x] Populate the same extended usage shape from Anthropic Messages, OpenAI Responses, and Codex Responses clients
- [ ] Capture rate-limit headers in each protocol client and pass through `done` event (deferred to Phase 5 — columns exist on `ai_run_calls`, transport-tap header capture lands there)
- [x] Introduce `CallUsage` value object; `ProviderRateLimit` deferred to Phase 5 alongside header capture
- [x] `RunRecorder::recordCall()` — upsert an `ai_run_calls` row per terminal `done` event keyed by (run_id, attempt_index); recompute `ai_runs` aggregates after each call
- [x] Replace `streamState['usage']` accumulator behavior in `AgenticToolLoopStreamReader` with per-iteration recording (no overwrite); each iteration writes a row, run aggregates sum across rows
- [x] **UI: Run Detail** — header shows totals (`prompt / completion / total`, cache and reasoning splits); a "Calls" table lists per-call rows with their own usage, latency, finish reason. Mirror on Control Plane > Run Inspector.
- [x] **UI: cost in Run Detail header** — shows aggregate cost once pricing has been resolved
- [x] Pest tests: streamed `usage` no longer raises `unknown_keys`; multi-call tool loop persists N rows; aggregates equal SUM of children; recordCall is idempotent on duplicate (run_id, attempt_index)

Evidence: 23 tests pass across `CallUsageTest`, `RunRecorderTest`, and `WireLogReadableFormatterTest`; full AI suite still green (1204 tests, 4246 assertions). Open `run_6g2xDDseIA71` in the inspector and the "Unknown delta keys" anomaly is gone; Run Detail renders totals + Calls table.

### Phase 2 — Pricing source registry (UI: pricing source label, refresh action)

Goal: prices are resolved from authoritative sources, refreshed on a schedule, and auditable.

- [x] Migration: `ai_pricing_snapshots` (model, provider, input_per_token, cached_input_per_token, output_per_token, snapshot_date, source) and `ai_pricing_overrides` (model, provider, rates, reason, created_by)
- [ ] Implement `PricingSourceRegistry` with `OverrideResolver`, `OpenRouterApiResolver`, `LiteLLMSnapshotResolver` (override + LiteLLM snapshot resolver landed; OpenRouter live resolver seam exists, network-backed implementation deferred to refresh action)
- [x] `RefreshPricingSnapshot` lifecycle action (nightly schedule, also runnable on demand from Lifecycle tab); idempotent; failure falls back to previous snapshot
- [x] Wire `TokenCostCalculator` to consume `PricingSourceRegistry` output; persist `pricing_source` and `pricing_version` on each call
- [x] **UI: pricing source pill** on each call row in the Run Detail / Run Inspector calls table (e.g., `litellm:2026-04-29`)
- [x] **UI: Lifecycle tab** gets a "Refresh pricing snapshot" action with last-refreshed timestamp and current snapshot stats (model count, age)
- [x] **UI: Admin > AI > Pricing Overrides** CRUD page
- [x] Pest tests: registry resolves in priority order; missing model returns null cost without throwing; nightly action updates snapshot table

Evidence: trigger refresh action; pricing source pill flips to today's date; override entry takes precedence on a known model.

### Phase 3 — Wire-log readable surface (UI: per-attempt usage chip)

Goal: an operator inspecting raw provider traffic sees per-call usage inline plus a "what made this prompt big" diff.

- [x] Render a per-attempt "Usage" chip in the readable view: prompt (incl. cached split), completion (incl. reasoning split), total, cost, pricing source, finish reason
- [ ] Compute and show "expected vs actual" prompt-token diff using the local prompt + tool-schema estimate
- [ ] Each attempt chip links to its `ai_run_calls` row id (deep-link to Run Inspector "Calls" table)
- [ ] Pest test: formatter output exposes the usage block per attempt; expected/actual diff is non-zero on a fixture with hidden context

Evidence: load `run_6g2xDDseIA71`; usage chip per attempt; click chip to highlight the matching `ai_run_calls` row.

### Phase 4 — Session meter (UI: chat console badge)

Goal: while chatting, the user sees the running token / cost / call-count of the conversation.

- [ ] Extend `MessageManager::sessionUsage()` with cumulative cost + last-turn delta + cache ratio + call count
- [ ] **UI: chat overlay header** — compact badge `1,754 in / 128 out · $0.0034 · 8 calls`; click expands a per-run / per-call table for the session
- [ ] Tooltip explains cache savings when cached tokens > 0

Evidence: trigger several turns; badge updates after each terminal event; expansion lists call rows.

### Phase 5 — Provider Capacity Telemetry (UI: capacity panel + run-level rate-limit cells)

Goal: rate-limit headroom is captured per call and visualized; OpenRouter credit balance shown; subscription probes available behind a flag.

- [ ] Per-call header capture wired through `done` event (delivered in Phase 1) is now consumed: rendered on each call row in the Run Inspector calls table (remaining requests/tokens, reset countdown)
- [ ] Migration: `ai_provider_quota_snapshots` (provider, fetched_at, source, raw_response jsonb, parsed_remaining, parsed_limit) for periodic probes
- [ ] OpenRouter probe: `RefreshOpenRouterCredits` lifecycle action calls `/api/v1/auth/key`, persists snapshot
- [ ] Subscription-product probes (Codex / Claude Code / Copilot) implemented behind `ai.providers.{name}.subscription_probe.enabled` flag, defaulting off; UI clearly labels as "best effort, may break"
- [ ] **UI: AI Operations Dashboard** — Provider Capacity panel: most-recent remaining requests/tokens per provider/model, reset countdown, sparkline of last N calls
- [ ] **UI: chat console** — small "capacity warning" banner when remaining tokens for the current model fall below a threshold
- [ ] Pest tests: header capture flows end-to-end; OpenRouter probe parses live response; flag-gated subscription probe is skipped when disabled

Evidence: dashboard shows non-zero capacity panel; trigger a chat; remaining-tokens cell updates; flip flag to enable Codex probe and observe a snapshot row appear.

### Phase 6 — Operations dashboard charts (UI: AI > Operations)

Goal: macro view of tokens, cost, calls, and capacity across all agents and models.

- [ ] Activity & Performance sections: tokens-over-time, cost-over-time, calls-over-time, per-model spend, per-tool spend
- [ ] Top-N panels: costliest runs (last 24h / 7d), top users by spend, top sessions by spend
- [ ] Cache-hit ratio panel per agent and per model
- [ ] Every chart cell deep-links into the Control Plane with `from=operations&filter=…`
- [ ] Pest tests: aggregate queries match seeded fixtures; deep-links round-trip

Evidence: dashboard renders with non-empty charts on a seeded dataset; clicking a model bar opens Run Inspector filtered to that model; back button returns to dashboard.

### Phase 7 — Multi-metric budgets & alerts (UI: budget admin + chat banner + ops badge)

Goal: configurable spending or token caps with visible UI on three surfaces.

- [ ] Migration: `ai_budgets` (subject_type, subject_id, metric_kind, period_kind, period_start, period_end, limit_amount, soft_threshold_pct, is_active)
- [ ] **UI: AI > Budgets** admin page (list + create/edit; combine multiple budgets per subject)
- [ ] `BudgetEvaluator` service used pre-call by `AgenticRuntime`; raises `BudgetExceededException` on hard hit; returns soft-warn flag at 80%
- [ ] **UI: chat console** banner when active subject is over soft threshold; **UI: red badge** on Operations when any budget exceeded; **UI: budget cell** on user list
- [ ] Pest tests: cost-cap and token-cap and request-count-cap each enforce independently; multi-budget AND combination triggers on the first one exceeded

Evidence: set a 1000-token budget on a test user; first turn warns at 80%; second turn blocked with friendly message; admin badge appears.

### Phase 8 — Optimization signals (UI: prompts tab)

Goal: surface where prompts bloat or lose cache hits.

- [ ] Hash a stable prefix of the prompt template per call; persist hash on `ai_run_calls`
- [ ] **UI: AI > Operations > Prompts tab** — table of template hashes with avg prompt size, cache-hit %, call count, total cost; clickable into Control Plane filtered by `prompt_hash`
- [ ] Anomaly indicator when avg prompt size grows >20% week-over-week for a hash
- [ ] Pest test: hashing is stable across whitespace-equivalent prompts; growth indicator triggers on a seeded dataset

Evidence: Prompts tab lists known templates; clicking a row opens Control Plane filtered by that hash; growth indicator visible on a hash whose average prompt size jumped.

## Out of Scope (track separately if needed)

- Provider-billing reconciliation (requires ingesting provider invoice exports)
- Automated model routing / auto-downshift on cost (needs a quality/SLA framework first)
- End-user invoice generation
- Multi-currency display (cents are stored; rendering stays USD until needed)
- Per-tool cost attribution for tool-internal LLM calls (covered when those tools record their own `ai_runs`)
