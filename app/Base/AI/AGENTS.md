# Base AI Module — Agent Guidelines

## Purpose

Stateless AI infrastructure. Provides model catalog, LLM client, provider discovery, and auth helpers. No database tables, no ownership concepts, no sessions.

## Architecture

```
app/Base/AI/
├── ServiceProvider.php          # Registers services + merges config
├── Config/ai.php                # workspace_path, llm defaults, provider_overlay
├── Console/Commands/
│   └── AiCatalogSyncCommand.php # blb:ai:catalog:sync
├── Services/
│   ├── ModelCatalogService.php  # Fetch, cache, serve models.dev catalog
│   ├── LlmClient.php           # Stateless OpenAI-compatible chat
│   ├── ProviderDiscoveryService.php  # GET /models discovery
│   └── GithubCopilotAuthService.php  # GitHub device flow
└── DTO/
    └── CatalogSyncResult.php
```

## Key Principles

1. **Stateless** — all services take explicit parameters, return results. No database, no scoping.
2. **No ownership** — no `company_id`, `employee_id`, or any business entity references.
3. **File-based cache** — catalog stored in `storage/download/ai/models-dev/catalog.json`, following the Geonames pattern.
4. **ETag invalidation** — conditional HTTP requests avoid unnecessary re-downloads.

## Sonar / Maintainability Guardrails

- Keep orchestration methods shallow. When stream parsing, payload normalization, or retry/response branching starts to dominate a method, extract a private helper with one named responsibility.
- AI-specific exception boundaries (per root AGENTS.md §Sonar Prevention Guard): catalog sync, provider discovery, LLM transport.
- For HTTP and SSE parsing code, separate these concerns:
  - transport execution
  - payload decoding
  - response mapping
- Provider extensions must follow the canonical `execution_controls` contract; see `docs/architecture/ai/agent-model.md`.
- In Node-facing or OpenAI-compatible code, rethrow unexpected errors instead of swallowing them in broad catches.

## Data Sources

- **models.dev** (`https://models.dev/api.json`) — community-maintained provider and model catalog (MIT licensed)
- **Provider overlay** (`config('ai.provider_overlay')`) — BLB-specific fields: `base_url`, `auth_type`, `api_key_url`
- Overlay-only providers (local/self-hosted) are not in models.dev

## Config Ownership

Base AI owns the `ai` config key. Core AI's `Config/ai.php` has been removed; Core reads `config('ai.*')` from Base.
