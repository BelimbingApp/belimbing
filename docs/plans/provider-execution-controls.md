# Provider Execution Controls

**Status:** Complete
**Last Updated:** 2026-04-22
**Sources:** `AGENTS.md`, `docs/plans/AGENTS.md`, `app/Base/AI/DTO/ChatRequest.php`, `app/Base/AI/DTO/ExecutionControls.php`, `app/Base/AI/DTO/ProviderRequestMapping.php`, `app/Base/AI/Enums/AiApiType.php`, `app/Base/AI/Services/LlmClient.php`, `app/Base/AI/Services/LlmClientSupport.php`, `app/Base/AI/Services/Protocols/LlmProtocolClientRegistry.php`, `app/Base/AI/Services/Protocols/ChatCompletionsProtocolClient.php`, `app/Base/AI/Services/Protocols/ResponsesProtocolClient.php`, `app/Base/AI/Services/Protocols/AnthropicMessagesProtocolClient.php`, `app/Base/AI/Services/ProviderMapping/ProviderCapabilityRegistry.php`, `app/Base/AI/Services/ProviderMapping/ProviderRequestMapperRegistry.php`, `app/Base/AI/Services/ProviderMapping/OpenAiChatCompletionsRequestMapper.php`, `app/Base/AI/Services/ProviderMapping/OpenAiResponsesRequestMapper.php`, `app/Base/AI/Services/ProviderMapping/AnthropicMessagesRequestMapper.php`, `app/Modules/Core/AI/Services/ConfigResolver.php`, `app/Modules/Core/AI/Services/AgentRuntime.php`, `app/Modules/Core/AI/Services/AgenticRuntime.php`, `app/Modules/Core/AI/Services/AgenticToolLoopStreamReader.php`, `docs/plans/thinking-content-streaming.md`, `https://platform.kimi.ai/docs/guide/kimi-k2-5-quickstart`, `https://platform.kimi.ai/docs/guide/use-kimi-api-to-complete-tool-calls`, `https://platform.openai.com/docs/guides/reasoning-best-practices`, `https://platform.openai.com/docs/guides/responses-vs-chat-completions`, `https://platform.claude.com/docs/en/build-with-claude/extended-thinking`, `https://platform.claude.com/docs/en/build-with-claude/adaptive-thinking`, `https://platform.claude.com/docs/en/api/streaming`, `https://platform.claude.com/docs/en/api/complete`

## Problem Essence

BLB started with ad hoc top-level request fields such as `temperature` and `reasoningSummary`, while provider-specific behavior was patched later in transport and normalizer code. That shape did not scale to providers whose reasoning, sampling, and tool-loop controls differ by protocol and by model family. The current implementation now routes all supported providers through one canonical execution-controls contract, provider capabilities, and request mappers.

## Desired Outcome

BLB should expose a single framework-level execution-controls contract that callers use regardless of provider. Providers and model families should describe their supported controls, defaults, fixed values, and translation rules behind that contract, so Lara and future runtimes can offer reasoning controls without baking Moonshot- or OpenAI-specific fields into the public API.

## Public Contract

- `ChatRequest` should carry one canonical execution-controls object instead of accumulating provider-specific scalar fields over time.
- Core AI config resolution should persist execution intent in a provider-agnostic shape and hand the same shape to every runtime path.
- Base AI should be able to answer, for a given provider and model, which controls are supported, which are editable, which are fixed, and how they map onto wire payloads.
- The contract should be protocol-aware: provider capabilities are resolved against provider, model, and native API family rather than assuming every advanced feature can be expressed through one OpenAI-compatible transport.
- The canonical reasoning contract should include `mode`, `visibility`, `effort`, and `budget` from the first version, with unsupported fields hidden or rejected through capability metadata rather than omitted from the framework model.
- Provider-specific transport quirks such as Moonshot K2.5 thinking mode, fixed sampling values, or OpenAI Responses reasoning summaries should be implemented as provider mappings behind the canonical contract, not as UI- or runtime-specific conditionals.
- There is no backward-compatibility requirement for the internal request shape in this initialization phase. BLB may replace legacy scalar request fields directly once the new canonical contract is ready.

## Top-Level Components

### Canonical execution-controls model

Base AI should own a small canonical control model that represents execution intent rather than provider wire fields. The initial contract should cover the control families already visible in the codebase and the Moonshot/OpenAI docs:

- sampling controls such as temperature, top-p, and candidate count
- reasoning controls such as mode, visibility, effort, and budget
- tool orchestration controls such as tool choice and reasoning-context preservation
- output limits such as max output tokens

This model should be deep enough to represent provider intent but shallow enough that Lara and task config surfaces can present it without provider-specific branching.

### Provider capability registry

Base AI should add a registry that describes provider and model-family capabilities in framework terms. This is separate from transport normalization. The registry answers questions like:

- which canonical controls exist for this provider and model family
- which controls are unsupported, optional, editable, or fixed
- which defaults the framework should show when the user has not set a value
- which controls are hidden because the provider requires a fixed value anyway

This registry becomes the source of truth for both runtime validation and future UI generation.

The initial capability matrix should explicitly cover three provider families:

- **OpenAI Responses** for reasoning summaries, effort controls, and reasoning-item continuity across tool calls
- **Moonshot OpenAI-compatible Chat Completions** for K2.5 thinking and fixed-value sampling constraints
- **Anthropic Messages API** for extended thinking, thinking-block preservation, and interleaved-thinking behaviour

### Provider payload mapper

The current normalizer seam should evolve from tool-schema-only handling into full request mapping. It should accept canonical execution controls plus the rest of the request, then:

- validate or coerce control values
- translate them into provider/protocol payload fields
- omit meaningless fields for a given provider
- record fixed-value enforcement when the provider or model family requires it

This keeps `LlmClient` shallow and prevents provider logic from leaking into Lara, AgenticRuntime, or Livewire pages.

For Anthropic, this component must be allowed to map into a non-OpenAI-native wire shape. Anthropic's OpenAI compatibility layer is useful for experimentation, but Anthropic's own docs position the native API as the path for full extended thinking, prompt caching, and detailed reasoning behaviour. BLB should not freeze its framework contract around the compatibility layer if that would block first-class thinking support.

The shipped mapper seam now returns a structured provider request mapping rather than a bare payload array. That mapping carries the final payload, any provider-specific headers, and control-adjustment metadata so callers can inspect forced or ignored controls when needed without teaching `LlmClient` or UI surfaces about individual provider quirks. Static transport requirements such as GitHub Copilot's IDE-identification headers now follow this same path instead of living as transport branches in `LlmClient`.

### Core AI config persistence

Core AI should persist execution controls alongside model selection in Lara workspace config. The config shape should express what BLB wants the model to do, not how a provider spells the option. Lara chat slots should store canonical controls at `llm.models[*].execution_controls`, and task-specific overrides should live at `llm.tasks.<task>.execution_controls`. Task resolution should overlay task controls onto the resolved model config, including `mode: primary`, so execution intent remains independent from model routing and survives fallback to Lara's primary model.

### Capability-driven UI

The long-term UI should ask Base AI which controls are relevant for the currently selected provider and model. The page should render only applicable controls, show fixed values as read-only facts when useful, and avoid provider-specific Blade branching. This is not required in the first migration step, but the data shape must make it possible.

The shipped first slice now exposes capability-driven execution controls on Lara's primary and backup model selectors plus the Task Models page. Those surfaces consume one server-side control schema keyed by provider, model, and API family instead of branching in Blade by vendor name.

## Provider Notes

### OpenAI

OpenAI's current direction is to put advanced reasoning behaviour in the Responses API rather than Chat Completions. Reasoning summaries are opt-in, reasoning effort is model-dependent, and OpenAI's own guidance for complex tool loops is to preserve reasoning items across requests when using Responses. Chat Completions remains supported, but it is the weaker transport for advanced reasoning continuity.

### Anthropic

Anthropic's current thinking model is richer than what its OpenAI compatibility layer exposes. In the native Messages API, extended thinking can emit thinking blocks, requires those blocks to be preserved across tool turns, and restricts tool choice while thinking is enabled. The compatibility layer can enable thinking for quick evaluation, but Anthropic explicitly recommends the native API for full feature access. This means BLB should plan for Anthropic-native request mapping rather than treating OpenAI-compatibility as the long-term integration surface.

The current implementation now routes the `anthropic` provider through `AiApiType::AnthropicMessages`, maps canonical controls onto native `thinking`, `tool_choice`, and header fields, and round-trips `thinking` plus `redacted_thinking` blocks through tool loops so reasoning continuity survives interleaved tool use.

### Moonshot

Moonshot currently fits the OpenAI-compatible transport path, but K2.5 shows why model-family capability rules matter. Thinking and tool-use constraints live below the provider level, and some controls are fixed rather than merely defaulted.

## Design Decisions

### Use canonical controls, not provider-branded settings

The framework should not introduce public fields such as `moonshotThinkingEnabled` or `openaiReasoningSummary`. Those names permanently encode vendor details into BLB's own API. Instead, BLB should express intent with canonical controls such as reasoning mode, reasoning visibility, and tool choice, then map them per provider.

### Separate capability description from payload mapping

One component should answer "what controls exist and what do they mean for this provider/model," while another component should answer "how do I build the final request payload." These responsibilities are related but not identical. Splitting them keeps the runtime and UI simpler and makes provider onboarding easier to reason about.

### Keep `LlmClient` transport-oriented

`LlmClient` should not become a policy engine. Its job is to route by protocol, hand the request to the provider mapper, send the HTTP request, and decode responses. Capability rules and fixed-value decisions belong in provider metadata and mappers, not in the transport class.

The shipped structure now keeps `LlmClient` as a thin facade over a protocol-client registry keyed by `AiApiType`. Chat Completions, OpenAI Responses, and Anthropic Messages each own their own endpoint handling and response decoding so protocol-specific SSE state machines do not accumulate in one class.

### Prefer direct replacement over compatibility shims

BLB is still in an initialization phase and this request concerns internal framework contracts, not an adopted public API. That means we should not carry compatibility shims just to preserve a transitional shape inside the codebase. Once the canonical execution-controls contract is defined, runtime call sites should be updated to use it directly and the legacy scalar fields should be removed in the same body of work.

### Treat protocol family as a first-class capability dimension

OpenAI, Moonshot, and Anthropic do not just differ by field names; they differ by protocol families and context-continuity rules. The provider capability registry and payload mapper should therefore resolve against provider plus protocol family, not just provider slug. Otherwise BLB will keep forcing native-provider behaviour through the wrong transport abstraction.

### Model-family overrides are first-class

Moonshot K2.5 has already shown that provider-level behavior is not specific enough. Capability and mapping resolution should support model-family rules such as "K2.5 thinking mode" without forcing one-off conditionals throughout the codebase. The provider capability registry should therefore resolve against both provider name and model identifier.

### Distinguish portable controls from advanced capability-gated controls

The framework should have a portable core control set, but it should not collapse everything to the lowest common denominator. Some controls are conceptually shared but not universally supported, such as reasoning visibility, reasoning effort, and reasoning budget. These should live in the canonical control model as capability-gated fields rather than being discarded or turned into provider-branded extensions. Unsupported controls simply do not appear for a given provider/model capability profile.

### Include reasoning effort and budget in v1 of the canonical contract

Reasoning effort and budget should not be deferred to a later contract revision. OpenAI and Anthropic already expose reasoning-quality controls that are meaningfully stronger than a simple enabled/disabled flag, and Moonshot-style provider growth makes it likely other vendors will do the same. Adding these fields now avoids designing a too-small contract that will need structural revision as soon as the second provider is implemented.

### Do not design around Anthropic's OpenAI compatibility layer

Anthropic's compatibility API is useful for testing but is not the right architectural anchor for advanced reasoning support. If BLB wants to expose Anthropic thinking controls cleanly, it should plan for a native Anthropic adapter and treat OpenAI-compatibility as a limited transport mode.

### Keep provider-specific beta headers and internal toggles out of the public contract

Some providers expose feature gates through headers or model-version-specific toggles, such as Anthropic interleaved thinking. Those knobs belong inside provider capability metadata and request mapping. The public BLB contract should express the desired behaviour, not the transport switch used to unlock it.

### Persist execution intent, not normalized wire values

Workspace config should store the user's intended controls, not the post-normalization payload. If Moonshot requires `temperature = 1` for a particular mode, that fixed value belongs in the provider mapping layer. Config persistence should still represent the user's chosen reasoning mode and any editable controls, otherwise the config becomes provider leakage instead of a framework contract.

### Capability-driven UI is the target, not the first milestone

The architecture should be designed for capability-driven rendering from the start, but the first implementation phase does not need to ship a full dynamic controls UI. The initial milestone is getting the canonical contract and provider mapping layer in place so runtime behavior is clean and future UI work has a stable backend.

## Phases

### Phase 0 — Correct the provider boundary before extending it

Goal: replace the misleading current seam so the rest of the work lands on the right abstraction.

- [x] Replace the current `ToolSchemaNormalizer`-centered boundary with a provider request-mapping boundary named for what it actually does
- [x] Redesign the current `ProviderNormalizer` contract so it is not Chat-Completions-specific and can support OpenAI Responses, Moonshot OpenAI-compatible chat, and Anthropic native Messages cleanly
- [x] Move tool-schema adaptation under the new provider boundary instead of using a tool-specific façade as the entry point for general provider behavior
- [x] Ensure the new provider boundary is explicitly keyed by provider, model family, and protocol family
- [x] Remove or rename the old normalizer types once the new boundary exists so the codebase has one obvious extension point
- [x] Carry the existing Moonshot and OpenAI request rules behind the new boundary so behavior stays intact during the refactor

### Phase 1 — Define the canonical control contract

Goal: establish one framework-level execution-controls model and replace the legacy scalar contract.

- [x] Define a Base AI DTO or value object for canonical execution controls
- [x] Decide the initial control families and field names BLB will support
- [x] Define the initial portable core and the advanced capability-gated control fields needed for OpenAI, Moonshot, and Anthropic reasoning flows, including reasoning `mode`, `visibility`, `effort`, and `budget`
- [x] Replace legacy scalar request fields in `ChatRequest` with the canonical execution-controls contract
- [x] Update the Base AI and Core AI call sites that currently pass scalar runtime controls so the new contract is the only path
- [x] Document the intended workspace config shape for future persisted execution controls

### Phase 2 — Add provider capability and mapping seams

Goal: move provider policy into explicit framework components.

- [x] Introduce a provider capability registry keyed by provider name and model family
- [x] Ensure capability resolution also captures protocol family so OpenAI Responses, OpenAI-compatible Chat Completions, and Anthropic Messages can coexist cleanly
- [x] Introduce a provider request mapper that turns canonical controls into final protocol payloads
- [x] Evolve the existing provider normalizer seam so tool-schema normalization and request mapping live under the same provider contract without overloading `LlmClient`
- [x] Move Moonshot K2.5 fixed sampling and thinking-related rules into the new provider request mapper
- [x] Model OpenAI Responses reasoning summaries and reasoning-item continuity through the same mapper seam
- [x] Model Anthropic native thinking, thinking-block preservation, and tool-choice constraints through the same seam
- [x] Define how provider mapping reports forced values or unsupported controls to callers when that signal is useful
- [x] Move GitHub Copilot's required IDE headers out of `LlmClient` transport branching and into provider request mapping
- [x] Split protocol transport and response decoding into `AiApiType`-specific handlers so `LlmClient` remains a thin facade

### Phase 3 — Thread canonical controls through Core AI runtime

Goal: make runtime config and orchestration use the new contract instead of direct scalar fields.

- [x] Extend `ConfigResolver` to read and return canonical execution controls
- [x] Decide where Lara primary model config and task config should persist execution controls in workspace config
- [x] Update `AgentRuntime`, `AgenticRuntime`, `AgenticToolLoopStreamReader`, and any other `ChatRequest` callers to pass canonical controls
- [x] Keep current behavior working for chat, task recommendations, provider tests, task resolution, and agentic tool loops under the new contract
- [x] Add focused tests around control resolution, provider mapping, and protocol payload generation

### Phase 4 — Expose capability-driven controls in admin surfaces

Goal: let Lara and future runtimes present provider/model controls without hardcoded vendor forms.

- [x] Define the server-side shape the UI should consume for supported controls, defaults, and editability
- [x] Decide which surfaces should expose execution controls first: Lara primary model config, task models, or provider diagnostics
- [x] Render only relevant controls for the selected provider/model and show fixed values read-only where appropriate
- [x] Ensure saved values remain canonical in config even when the provider enforces a different wire value
- [x] Add Livewire coverage for control visibility, persistence, and model-family switching

### Phase 5 — Migrate and simplify

Goal: remove the temporary compatibility layer once the new contract is the only one in use.

- [x] Remove any remaining provider-specific scalar growth from `ChatRequest` and adjacent runtime DTOs
- [x] Update docs to describe the canonical contract as the supported extension point for new providers
- [x] Audit provider integrations for any remaining transport-level policy leakage and move it behind the registry/mapper seam
