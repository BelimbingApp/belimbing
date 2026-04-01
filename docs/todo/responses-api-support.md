# Responses API Support

**Problem:** GitHub Copilot requires different wire protocols depending on the model:
- Claude models → `anthropic-messages` protocol
- Gemini models → `google-generative-ai` protocol
- GPT-4.x, gpt-4o → `openai-completions` (`/chat/completions`)
- GPT-5.x → `openai-responses` (`/responses`)

BLB's `LlmClient` only speaks `openai-completions`. GPT-5.4 fails with HTTP 400: *"model 'gpt-5.4' is not accessible via the /chat/completions endpoint"*.

**Constraint:** `LlmClient` must remain model-agnostic — it dispatches on a protocol type, never on model names.

---

## Reference: OpenClaw API Type Landscape

OpenClaw's model catalog assigns a per-model `api` field. The full set:

| OpenClaw `api` | Wire Protocol | Endpoint Path | Used By |
|---|---|---|---|
| `openai-completions` | OpenAI Chat Completions | `/chat/completions` | Most providers, GPT-4.x, Gemini via OpenAI-compat |
| `openai-responses` | OpenAI Responses API | `/responses` | GPT-5.x on OpenAI/Copilot |
| `openai-codex-responses` | Responses + codex extras | `/responses` | Codex models (container/shell) |
| `azure-openai-responses` | Azure Responses API | `/openai/deployments/.../responses` | Azure OpenAI |
| `anthropic-messages` | Anthropic Messages API | `/v1/messages` | Claude models on Anthropic/Bedrock/Copilot |
| `google-generative-ai` | Google Generative AI | `/v1beta/models/.../generateContent` | Gemini via Google AI Studio |
| `google-vertex` | Vertex AI | regional endpoint | Gemini via Google Cloud |
| `google-gemini-cli` | Gemini CLI variant | — | Google internal |
| `bedrock-converse-stream` | AWS Bedrock Converse | AWS SDK | All Bedrock models |
| `mistral-conversations` | Mistral Conversations | `/v1/chat/completions` (variant) | Mistral models |

**Key insight:** Even a single provider like `github-copilot` uses 4 different protocols. The protocol is a property of the **(provider, model) pair**, not the provider alone.

---

## BLB Adaptation: What We Need

### Practical Scope

BLB routes all providers through OpenAI-compatible endpoints. Most providers (OpenAI, Google AI Studio, Mistral, xAI, etc.) expose `/chat/completions` and it works. The immediate gap is:

1. **`openai-responses`** — GPT-5.x models on OpenAI and GitHub Copilot
2. Everything else already works via `openai-completions`

Native `anthropic-messages`, `google-generative-ai`, and `bedrock-converse-stream` are not needed now — BLB accesses Claude and Gemini through OpenAI-compatible proxies (GitHub Copilot, Google AI Studio's OpenAI endpoint).

### BLB `AiApiType` Enum

Modeled after OpenClaw's `api` field but using BLB naming conventions:

```php
enum AiApiType: string
{
    case OpenAiChatCompletions = 'openai_chat_completions';   // /chat/completions
    case OpenAiResponses = 'openai_responses';                // /responses

    // Future — only when BLB adds native provider SDKs:
    // case AnthropicMessages = 'anthropic_messages';
    // case GoogleGenerativeAi = 'google_generative_ai';
    // case BedrockConverseStream = 'bedrock_converse_stream';
}
```

Start with two. The enum is the extension point — adding a new protocol means adding a case and a handler in `LlmClient`.

---

## Architecture: Separation of Concerns

```
Model catalog     →  per-model api_type metadata (source of truth)
Config resolver   →  threads api_type into resolved config
ChatRequest DTO   →  carries api_type as protocol hint
LlmClient         →  dispatches to protocol handler based on api_type
AgenticRuntime    →  unchanged — transparent pass-through
```

| Layer | Knows models? | Knows protocols? |
|---|---|---|
| Model catalog / overlay config | ✅ | ✅ sets `api_type` per model |
| `ConfigResolver` | ✅ resolves model | ⚠️ passes through |
| `ChatRequest` DTO | ❌ | ✅ carries `apiType` |
| `LlmClient` | ❌ model-agnostic | ✅ dispatches on `apiType` |
| `AgenticRuntime` | ❌ | ❌ transparent |

---

## Implementation Plan

### Phase 1 — Plumbing (api_type from catalog to ChatRequest)

#### 1.1 `AiApiType` Enum
Create `app/Base/AI/Enums/AiApiType.php` with `OpenAiChatCompletions` and `OpenAiResponses`.

#### 1.2 Model Catalog: per-model `api_type`

Add `api_type_overrides` to `provider_overlay` entries in `ai.php`. This is a map of glob patterns → api type strings:

```php
'openai' => [
    'base_url' => 'https://api.openai.com/v1',
    'api_type_overrides' => [
        'gpt-5*'   => 'openai_responses',
        'codex-*'  => 'openai_responses',
        'o3*'      => 'openai_responses',
        'o4*'      => 'openai_responses',
    ],
],
'github-copilot' => [
    'auth_type' => 'device_flow',
    'api_type_overrides' => [
        'gpt-5*'   => 'openai_responses',
        'codex-*'  => 'openai_responses',
    ],
],
```

Default (when no pattern matches): `openai_chat_completions`.

#### 1.3 `ModelCatalogService::resolveApiType()`

New method:

```php
public function resolveApiType(string $providerName, string $modelId): AiApiType
```

Looks up `api_type_overrides` for the provider, matches model against glob patterns, returns the enum. Falls back to `OpenAiChatCompletions`.

#### 1.4 `ChatRequest` — Add `apiType` field

```php
public readonly AiApiType $apiType = AiApiType::OpenAiChatCompletions,
```

Backward-compatible default — all existing callers continue working.

#### 1.5 `ConfigResolver` — Include `api_type`

Both `resolveModelConfig()` and `resolveDefault()` add `api_type` to the returned config array by calling `ModelCatalogService::resolveApiType()`.

#### 1.6 Callers — Pass `api_type` into `ChatRequest`

Update all `new ChatRequest(...)` call sites:
- `AgenticRuntime::chatWithTools()` / `chatStreamWithTools()`
- `ProviderTestService::executeTestCall()`
- `ManagesChatSessions` (title generation)
- `Show.php` (SQL explain) — uses default, always `OpenAiChatCompletions`

### Phase 2 — Responses API Protocol Handler

#### 2.1 `LlmClient::chat()` — Route on `apiType`

```php
public function chat(ChatRequest $request): array
{
    return match ($request->apiType) {
        AiApiType::OpenAiResponses => $this->chatViaResponses($request),
        default => $this->chatViaChatCompletions($request),
    };
}
```

Extract current `chat()` body → `chatViaChatCompletions()`.

#### 2.2 `chatViaResponses()` — Sync

1. Translate messages → `input` items via `convertToResponsesInput()`
2. Translate tools via `convertToResponsesTools()`
3. Map `max_tokens` → `max_output_tokens`
4. POST to `{base_url}/responses`
5. Parse response via `parseResponsesResponse()` → **same normalized array shape**

#### 2.3 `chatStreamViaResponses()` — Streaming

1. Same request translation
2. POST to `{base_url}/responses` with `stream: true`
3. Parse paired `event:` + `data:` SSE format
4. Normalize events to same `content_delta` / `tool_call_delta` / `done` shape

#### 2.4 Message Format Translation

`convertToResponsesInput(array $messages): array`:

| Chat Completions | Responses API |
|---|---|
| `{role: "system", content}` | `{role: "developer", content}` |
| `{role: "user", content}` | `{role: "user", content: [{type: "input_text", text}]}` |
| `{role: "assistant", content, tool_calls}` | `{type: "message", role: "assistant", content: [{type: "output_text", text}], status: "completed"}` + separate `{type: "function_call", call_id, name, arguments}` |
| `{role: "tool", tool_call_id, content}` | `{type: "function_call_output", call_id, output}` |

#### 2.5 Tool Format Translation

`convertToResponsesTools(array $tools): array`:

```
Chat Completions:  {type: "function", function: {name, description, parameters}}
Responses API:     {type: "function", name, description, parameters}
```

Flatten the nested `function` key.

#### 2.6 Streaming Event Translation

| Responses SSE Event | Normalized BLB Event |
|---|---|
| `response.output_text.delta` | `content_delta` |
| `response.output_item.added` (function_call) | `tool_call_delta` (id + name) |
| `response.function_call_arguments.delta` | `tool_call_delta` (arguments_delta) |
| `response.completed` | `done` (with usage) |
| `response.failed` / `error` | `error` |

### Phase 3 — Provider Test Service

No structural changes. `ProviderTestService` calls `LlmClient::chat()` which auto-routes. The only change is passing `api_type` from resolved config into `ChatRequest`.

---

## Design Decisions

1. **Per-model api_type, not per-provider** — matches reality: a single provider (GitHub Copilot) uses 4 different protocols depending on the model.
2. **Config-driven glob matching** — `api_type_overrides` in `provider_overlay` keeps model→protocol mapping in config, not code. Easy to update.
3. **`LlmClient` model-agnostic** — dispatches purely on `ChatRequest::$apiType`. Never inspects model names.
4. **Same normalized output** — both protocol handlers return identical array shapes. All consumers (`AgenticRuntime`, `ProviderTestService`, `ChatStreamController`) need zero changes.
5. **Start with two protocols** — `OpenAiChatCompletions` + `OpenAiResponses`. The enum is the extension point for `AnthropicMessages`, `GoogleGenerativeAi`, etc. when BLB adds native SDKs.
6. **No new DTOs** — `ChatRequest` gains one field with a backward-compatible default.

## Files to Create

| File | Purpose |
|------|---------|
| `app/Base/AI/Enums/AiApiType.php` | API protocol type enum |

## Files to Modify

| File | Change |
|------|--------|
| `app/Base/AI/Config/ai.php` | Add `api_type_overrides` to provider overlays |
| `app/Base/AI/DTO/ChatRequest.php` | Add `apiType` field |
| `app/Base/AI/Services/LlmClient.php` | Add Responses API handlers, route on `apiType` |
| `app/Base/AI/Services/ModelCatalogService.php` | Add `resolveApiType()` method |
| `app/Modules/Core/AI/Services/ConfigResolver.php` | Include `api_type` in resolved config |
| `app/Modules/Core/AI/Services/AgenticRuntime.php` | Pass `api_type` into `ChatRequest` |
| `app/Modules/Core/AI/Services/ProviderTestService.php` | Pass `api_type` into `ChatRequest` |
| `app/Modules/Core/AI/Livewire/Concerns/ManagesChatSessions.php` | Pass `api_type` into `ChatRequest` |
| `app/Base/Database/Livewire/Queries/Show.php` | Pass `api_type` into `ChatRequest` |

## Implementation Order

1. Create `AiApiType` enum
2. Add `apiType` to `ChatRequest` (backward-compatible default)
3. Add `api_type_overrides` config + `resolveApiType()` in `ModelCatalogService`
4. Thread `api_type` through `ConfigResolver` → callers → `ChatRequest`
5. Extract current `chat()`/`chatStream()` → `chatViaChatCompletions()`/`chatStreamViaChatCompletions()`
6. Implement `chatViaResponses()` + `parseResponsesResponse()`
7. Implement `chatStreamViaResponses()` + Responses SSE parsing
8. Test with `gpt-5.4` on `github-copilot` provider

## Out of Scope (Future)

- Native `AnthropicMessages` protocol (Claude models currently work via OpenAI-compat proxies)
- Native `GoogleGenerativeAi` protocol (Gemini works via Google AI Studio's OpenAI endpoint)
- `BedrockConverseStream` (AWS SDK-based, fundamentally different transport)
- Reasoning effort/summary parameters
- Codex-specific features (container, shell tools)
- `previous_response_id` chaining
