# Base AI — Agent Guidelines

## Scope

AI infrastructure: model catalog, LLM client, provider discovery, auth helpers. Owns AI protocol and catalog semantics — not company/provider governance, sessions, or business ownership.

## Layout

```
app/Base/AI/
├── ServiceProvider.php
├── Config/ai.php                       # workspace_path, llm defaults, provider_overlay
├── Console/Commands/AiCatalogSyncCommand.php  # blb:ai:catalog:sync
├── Services/
│   ├── ModelCatalogService.php         # models.dev fetch + file cache
│   ├── LlmClient.php                   # stateless OpenAI-compatible chat
│   ├── ProviderDiscoveryService.php    # GET /models
│   └── GithubCopilotAuthService.php    # device flow
└── DTO/CatalogSyncResult.php
```

## Rules

1. Stateless services; no `company_id`/`employee_id` or business refs.
2. Non-LLM external calls go through `app/Base/Integration` for exchange observability. LLM runtime stays on AI WireLogger for now.
3. Catalog cache: `storage/download/ai/models-dev/catalog.json` (Geonames pattern), with ETag conditional requests.
4. Base AI owns the `ai` config key; Core reads `config('ai.*')` from here.
5. Provider extensions follow the canonical `execution_controls` contract (see `docs/architecture/ai/agent-model.md`).
6. Exception boundaries: catalog sync, provider discovery, LLM transport. Rethrow unexpected errors — don't swallow in broad catches.

## Data Sources

- `https://models.dev/api.json` — community catalog (MIT).
- `config('ai.provider_overlay')` — BLB fields (`base_url`, `auth_type`, `api_key_url`); also hosts overlay-only local/self-hosted providers absent from models.dev.
