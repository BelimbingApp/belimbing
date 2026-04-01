<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    /*
    |--------------------------------------------------------------------------
    | Agent Workspace Base Path
    |--------------------------------------------------------------------------
    |
    | Root directory for per-Agent workspaces. Each Agent
    | gets a subdirectory named by employee_id containing sessions, memory
    | files, and vector indexes.
    |
    */
    'workspace_path' => env('AI_WORKSPACE_PATH', storage_path('app/workspace')),

    /*
    |--------------------------------------------------------------------------
    | LLM Runtime Defaults
    |--------------------------------------------------------------------------
    |
    | Default runtime parameters when not specified per-model in the agent
    | workspace config. Credentials come from company-scoped AiProvider
    | records, not from env vars.
    |
    */
    'llm' => [
        'max_tokens' => 2048,
        'temperature' => 0.7,
        'timeout' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Per-tool settings. Tools read from config('ai.tools.*') as defaults;
    | per-company overrides may be stored in `ai_tool_configs` (future).
    |
    */
    'tools' => [
        'web_search' => [
            'provider' => env('AI_WEB_SEARCH_PROVIDER', 'parallel'),
            'parallel' => [
                'api_key' => env('AI_WEB_SEARCH_PARALLEL_API_KEY'),
            ],
            'brave' => [
                'api_key' => env('AI_WEB_SEARCH_BRAVE_API_KEY'),
            ],
            'cache_ttl_minutes' => 15,
        ],
        'web_fetch' => [
            'timeout_seconds' => 30,
            'max_response_bytes' => 5242880, // 5MB
            'ssrf_allow_private' => env('AI_WEB_FETCH_SSRF_ALLOW_PRIVATE', false),
        ],
        'browser' => [
            'enabled' => env('AI_BROWSER_ENABLED', false),
            'executable_path' => env('AI_BROWSER_PATH', null),
            'headless' => env('AI_BROWSER_HEADLESS', env('APP_ENV', 'production') !== 'local'),
            'max_contexts_per_company' => 3,
            'context_idle_timeout_seconds' => 300,
            'evaluate_enabled' => false,
            'ssrf_policy' => [
                'allow_private_network' => false,
                'hostname_allowlist' => [],
            ],
        ],
        'messaging' => [
            'channels' => [
                'whatsapp' => [
                    'enabled' => env('AI_MESSAGING_WHATSAPP_ENABLED', false),
                    'rate_limit_per_minute' => 60,
                ],
                'telegram' => [
                    'enabled' => env('AI_MESSAGING_TELEGRAM_ENABLED', false),
                    'rate_limit_per_minute' => 30,
                ],
                'slack' => [
                    'enabled' => env('AI_MESSAGING_SLACK_ENABLED', false),
                    'rate_limit_per_minute' => 60,
                ],
                'email' => [
                    'enabled' => env('AI_MESSAGING_EMAIL_ENABLED', false),
                    'rate_limit_per_minute' => 30,
                ],
            ],
        ],
        'memory_search' => [
            'sqlite_vec_extension' => env('AI_SQLITE_VEC_EXTENSION', 'vec0'),
            'sqlite_vec_extension_dir' => env('AI_SQLITE_VEC_EXTENSION_DIR', null),
            'embedding_dimensions' => 384,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Subsystem
    |--------------------------------------------------------------------------
    |
    | Per-agent memory index and retrieval settings. Memory sources are
    | discovered from the agent workspace; the index is a rebuildable
    | derivative stored alongside the workspace.
    |
    */
    'memory' => [
        // Maximum chunk size in characters. Chunks exceeding this are split
        // at paragraph boundaries.
        'max_chunk_chars' => 2000,

        // Maximum results returned per retrieval query.
        'default_max_results' => 10,

        // Score threshold below which results are excluded (0.0–1.0).
        'min_score_threshold' => 0.05,

        // Compaction: archive prefix for daily notes that have been compacted.
        'compaction_archive_prefix' => 'archived-',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lara (System Agent)
    |--------------------------------------------------------------------------
    |
    | Lara's core prompt is framework-managed and non-configurable.
    | Licensees may append additive guidance through an extension file.
    | The extension is append-only and must not override core policy.
    |
    */
    'lara' => [
        'prompt' => [
            // Relative path from project root. Leave null to disable extension.
            'extension_path' => env('AI_LARA_PROMPT_EXTENSION_PATH'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Overlay
    |--------------------------------------------------------------------------
    |
    | BLB-specific overrides merged on top of models.dev catalog data.
    | ModelCatalogService maps catalog fields automatically:
    |   catalog `api`  → `base_url`
    |   catalog `name` → `display_name`
    |   catalog `doc`  → `doc_url`
    |   default `auth_type` → `'api_key'`
    |
    | Only set fields here when:
    |   `base_url`    — catalog has no `api` field, or BLB needs a different URL
    |   `auth_type`   — not the default `api_key` (e.g., device_flow, local, oauth, custom)
    |   `api_key_url` — admin convenience link for obtaining API keys
    |
    | Providers not in models.dev (local/self-hosted) are overlay-only — the
    | overlay is their sole data source. They need `display_name`, `description`,
    | `base_url`, and `auth_type`.
    |
    | auth_type controls the connect wizard UI behavior:
    |   'api_key'      — Standard API key (base_url + api_key required)
    |   'local'        — Local/self-hosted server (api_key optional)
    |   'oauth'        — OAuth flow required (api_key optional)
    |   'device_flow'  — GitHub OAuth device flow (token obtained interactively)
    |   'custom'       — Requires additional configuration
    |
    */
    'provider_overlay' => [

        // ─── base_url overrides (catalog has no `api` field) ────────────

        'openai' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'GPT, o-series reasoning, and DALL·E models',
            'base_url' => 'https://api.openai.com/v1',
            'api_key_url' => 'https://platform.openai.com/api-keys',
            'api_type_overrides' => [
                'gpt-5*' => 'openai_responses',
                'codex-*' => 'openai_responses',
            ],
        ],
        'anthropic' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Claude models — advanced reasoning and coding',
            'base_url' => 'https://api.anthropic.com/v1',
            'api_key_url' => 'https://console.anthropic.com/settings/keys',
        ],
        'google' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Google AI Studio — Gemini models',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'api_key_url' => 'https://aistudio.google.com/apikey',
        ],
        'xai' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Grok models — reasoning and real-time knowledge',
            'base_url' => 'https://api.x.ai/v1',
            'api_key_url' => 'https://console.x.ai/team/default/api-keys',
        ],
        'mistral' => [
            'category' => ['leading-lab'],
            'region' => ['global', 'europe'],
            'description' => 'Efficient open and commercial models from Mistral AI',
            'base_url' => 'https://api.mistral.ai/v1',
            'api_key_url' => 'https://console.mistral.ai/api-keys',
        ],
        'togetherai' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Fast open-source model inference and fine-tuning',
            'base_url' => 'https://api.together.xyz/v1',
            'api_key_url' => 'https://api.together.xyz/settings/api-keys',
        ],
        'venice' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Privacy-focused uncensored inference',
            'base_url' => 'https://api.venice.ai/api/v1',
            'api_key_url' => 'https://venice.ai/settings/api',
        ],
        'cloudflare-ai-gateway' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'Proxy and cache for AI APIs via Cloudflare',
            'base_url' => 'https://gateway.ai.cloudflare.com/v1',
            'auth_type' => 'custom',
            'api_key_url' => 'https://dash.cloudflare.com/',
        ],

        // ─── api_key_url only (catalog provides base_url) ──────────────

        'nvidia' => [
            'category' => ['cloud-provider', 'inference-platform'],
            'region' => ['global'],
            'description' => 'NVIDIA NIM — optimized inference microservices',
            'api_key_url' => 'https://build.nvidia.com/explore/discover',
        ],
        'minimax' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'MiniMax AI — multimodal models (international)',
            'api_key_url' => 'https://platform.minimaxi.com/user-center/basic-information/interface-key',
        ],
        'moonshotai' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Kimi models for long-context tasks',
            'api_key_url' => 'https://platform.moonshot.cn/console/api-keys',
        ],
        'kimi-for-coding' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'Moonshot AI coding assistant — optimized for development',
            'api_key_url' => 'https://platform.moonshot.cn/console/api-keys',
        ],
        'xiaomi' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'Xiaomi MiMo — Chinese AI models',
            'api_key_url' => 'https://dev.mi.com/xiaomimimo',
        ],
        'zai' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Zhipu Z.AI — GLM models (international)',
            'api_key_url' => 'https://open.bigmodel.cn/usercenter/apikeys',
        ],
        'openrouter' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'Unified access to 200+ models with automatic fallback',
            'api_key_url' => 'https://openrouter.ai/keys',
        ],
        'huggingface' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Hugging Face Inference API — thousands of open models',
            'api_key_url' => 'https://huggingface.co/settings/tokens',
        ],
        'kilo' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'AI gateway — unified access to 200+ models',
            'api_key_url' => 'https://kilo.ai/settings/api-keys',
        ],
        'opencode' => [
            'category' => ['developer-tool', 'gateway'],
            'region' => ['global'],
            'description' => 'OpenCode Zen — AI coding assistant gateway',
            'api_key_url' => 'https://opencode.ai/auth',
        ],
        'synthetic' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'Model gateway with enhanced features',
            'api_key_url' => 'https://synthetic.new/dashboard',
        ],

        // ─── Non-default auth_type ──────────────────────────────────────

        'github-copilot' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'GitHub device login — subscription includes models from OpenAI, Anthropic, Google, and xAI',
            'base_url' => 'https://api.individual.githubcopilot.com',
            'auth_type' => 'device_flow',
            'api_type_overrides' => [
                'gpt-5*' => 'openai_responses',
                'codex-*' => 'openai_responses',
            ],
        ],
        'chutes' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Decentralized GPU marketplace for AI inference',
            'auth_type' => 'oauth',
        ],
        'qwen-portal' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'display_name' => 'Qwen Portal',
            'description' => 'Alibaba Qwen models — free access via OAuth login',
            'base_url' => 'https://portal.qwen.ai/v1',
            'auth_type' => 'oauth',
        ],

        // ─── Descriptions only (no operational overrides) ───────────────

        '302ai' => [
            'category' => ['gateway'],
            'region' => ['china'],
            'description' => 'Chinese AI gateway — aggregates models from multiple providers',
        ],
        'abacus' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Enterprise AI platform with fine-tuning and deployment',
        ],
        'aihubmix' => [
            'category' => ['gateway'],
            'region' => ['china'],
            'description' => 'Chinese AI model aggregator and routing service',
        ],
        'alibaba' => [
            'category' => ['cloud-provider', 'leading-lab'],
            'region' => ['global'],
            'description' => 'Alibaba Cloud Model Studio — Qwen and open models (international)',
        ],
        'alibaba-cn' => [
            'category' => ['cloud-provider', 'leading-lab'],
            'region' => ['china'],
            'description' => 'Alibaba Cloud Model Studio — Qwen and open models (China)',
        ],
        'amazon-bedrock' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'AWS managed AI service — multi-provider model access',
        ],
        'azure' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'Microsoft Azure OpenAI Service — GPT and open models',
        ],
        'azure-cognitive-services' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'Microsoft Azure AI Services — speech, vision, and language',
        ],
        'bailing' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'Ant Group AI platform — Bailing models',
        ],
        'baseten' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'ML infrastructure for deploying and serving models',
        ],
        'berget' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'European AI inference platform — GDPR compliant',
        ],
        'cerebras' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Ultra-fast inference on Cerebras wafer-scale chips',
        ],
        'cloudferro-sherlock' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'European cloud AI service by CloudFerro',
        ],
        'cloudflare-workers-ai' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Serverless inference at the edge via Cloudflare Workers',
        ],
        'cohere' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Enterprise NLP — Embed, Rerank, Command models',
        ],
        'cortecs' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Decentralized GPU network for AI inference',
        ],
        'deepinfra' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Serverless inference for popular open-source models',
        ],
        'deepseek' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Advanced reasoning and coding models',
        ],
        'evroc' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'European sovereign cloud AI inference',
        ],
        'fastrouter' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'AI model routing and load balancing service',
        ],
        'fireworks-ai' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Fast inference platform optimized for open-source models',
        ],
        'firmware' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'AI model serving infrastructure',
        ],
        'friendli' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'High-performance AI inference engine',
        ],
        'github-models' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'GitHub-hosted models — free tier available with GitHub account',
        ],
        'gitlab' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'GitLab Duo — AI features integrated with GitLab DevSecOps',
        ],
        'google-vertex' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'Google Cloud Vertex AI — enterprise Gemini deployment',
        ],
        'google-vertex-anthropic' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'Anthropic Claude models via Google Cloud Vertex AI',
        ],
        'groq' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Ultra-fast LPU inference — lowest latency available',
        ],
        'helicone' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'AI observability gateway — logging, caching, and rate limiting',
        ],
        'iflowcn' => [
            'category' => ['gateway'],
            'region' => ['china'],
            'description' => 'Chinese AI gateway and model aggregation service',
        ],
        'inception' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Inception AI — Mercury models for fast inference',
        ],
        'inference' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Open-source model inference API platform',
            'base_url' => 'https://api.inference.net/v1',
            'api_key_url' => 'https://docs.inference.net/api/api-quickstart',
        ],
        'io-net' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Decentralized GPU cloud for AI workloads',
        ],
        'jiekou' => [
            'category' => ['gateway'],
            'region' => ['china'],
            'description' => 'Chinese AI model gateway and aggregation service',
        ],
        'kuae-cloud-coding-plan' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'KUAE Cloud coding-specific AI plan',
        ],
        'llama' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Meta Llama API — direct access to Llama models',
        ],
        'lmstudio' => [
            'category' => ['local'],
            'region' => ['global'],
            'description' => 'Local model runner with a desktop GUI',
        ],
        'lucidquery' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'AI-powered data analytics and querying',
        ],
        'meganova' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'AI model hosting and inference platform',
        ],
        'minimax-cn' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'MiniMax AI — multimodal models (China)',
        ],
        'minimax-coding-plan' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'MiniMax coding-specific plan (international)',
        ],
        'minimax-cn-coding-plan' => [
            'category' => ['developer-tool'],
            'region' => ['china'],
            'description' => 'MiniMax coding-specific plan (China)',
        ],
        'moark' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'AI model marketplace and inference service',
        ],
        'modelscope' => [
            'category' => ['inference-platform'],
            'region' => ['china'],
            'description' => 'Alibaba ModelScope — open model community and inference',
        ],
        'moonshotai-cn' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'Moonshot AI — Kimi models (China endpoint)',
        ],
        'morph' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'AI-powered code generation and development tools',
        ],
        'nano-gpt' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Affordable AI inference with pay-per-token pricing',
        ],
        'nebius' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'Nebius AI — European cloud inference at scale',
        ],
        'nova' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Nova AI assistant and model platform',
        ],
        'novita-ai' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'GPU cloud for AI inference — images, LLMs, and video',
        ],
        'ollama-cloud' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'Hosted Ollama model inference in the cloud',
        ],
        'opencode-go' => [
            'category' => ['developer-tool', 'gateway'],
            'region' => ['global'],
            'description' => 'OpenCode Go — AI coding assistant (Go variant)',
        ],
        'ovhcloud' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'OVHcloud AI Endpoints — European sovereign inference',
        ],
        'perplexity' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'AI search with real-time web grounding',
        ],
        'perplexity-agent' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Perplexity agent mode — autonomous multi-step research',
        ],
        'poe' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'Quora Poe — multi-model AI chat platform',
        ],
        'privatemode-ai' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Privacy-first AI inference — no data retention',
        ],
        'qihang-ai' => [
            'category' => ['inference-platform'],
            'region' => ['china'],
            'description' => 'Chinese AI model hosting and inference',
        ],
        'qiniu-ai' => [
            'category' => ['cloud-provider'],
            'region' => ['china'],
            'description' => 'Qiniu Cloud AI — Chinese cloud inference service',
        ],
        'requesty' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'AI gateway with smart routing, caching, and fallback',
        ],
        'sap-ai-core' => [
            'category' => ['cloud-provider'],
            'region' => ['global', 'europe'],
            'description' => 'Enterprise AI within SAP ecosystem',
        ],
        'scaleway' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'Scaleway AI — European cloud GPU inference',
        ],
        'siliconflow' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'High-throughput inference (international)',
        ],
        'siliconflow-cn' => [
            'category' => ['inference-platform'],
            'region' => ['china'],
            'description' => 'High-throughput inference (China)',
        ],
        'stackit' => [
            'category' => ['cloud-provider'],
            'region' => ['europe'],
            'description' => 'STACKIT AI — Schwarz Group European sovereign cloud',
        ],
        'stepfun' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'StepFun — Chinese multimodal AI models',
        ],
        'submodel' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'AI model inference and fine-tuning platform',
        ],
        'upstage' => [
            'category' => ['leading-lab'],
            'region' => ['global'],
            'description' => 'Upstage AI — Solar models for enterprise',
        ],
        'v0' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'Vercel v0 — AI-powered UI generation',
        ],
        'vercel' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'Vercel AI Gateway — edge-optimized model routing',
        ],
        'vivgrid' => [
            'category' => ['inference-platform'],
            'region' => ['global'],
            'description' => 'VivGrid — distributed GPU inference network',
        ],
        'vultr' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'description' => 'Vultr Cloud GPU — serverless AI inference',
        ],
        'wandb' => [
            'category' => ['specialized'],
            'region' => ['global'],
            'description' => 'Weights & Biases — ML experiment tracking with inference',
        ],
        'zai-coding-plan' => [
            'category' => ['developer-tool'],
            'region' => ['global'],
            'description' => 'Zhipu Z.AI coding-specific plan',
        ],
        'zenmux' => [
            'category' => ['gateway'],
            'region' => ['global'],
            'description' => 'ZenMux — AI model multiplexer and gateway',
        ],
        'zhipuai' => [
            'category' => ['leading-lab'],
            'region' => ['china'],
            'description' => 'Zhipu AI — GLM and CogView models (China)',
        ],
        'zhipuai-coding-plan' => [
            'category' => ['developer-tool'],
            'region' => ['china'],
            'description' => 'Zhipu AI coding-specific plan (China)',
        ],

        // ─── Not in catalog (overlay-only) ──────────────────────────────

        'qianfan' => [
            'category' => ['cloud-provider', 'leading-lab'],
            'region' => ['china'],
            'display_name' => 'Baidu Qianfan',
            'description' => 'Baidu AI Cloud — Ernie and open models',
            'base_url' => 'https://qianfan.baidubce.com/v2',
            'api_key_url' => 'https://console.bce.baidu.com/qianfan/ais/console/applicationConsole/application',
        ],
        'volcengine' => [
            'category' => ['cloud-provider'],
            'region' => ['china'],
            'display_name' => 'Volcengine Ark',
            'description' => 'ByteDance cloud (China) — Doubao and open models',
            'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'api_key_url' => 'https://console.volcengine.com/ark/region:ark+cn-beijing/apiKey',
        ],
        'byteplus' => [
            'category' => ['cloud-provider'],
            'region' => ['global'],
            'display_name' => 'BytePlus Ark',
            'description' => 'ByteDance cloud (international) — Doubao and open models',
            'base_url' => 'https://ark.ap-southeast.bytepluses.com/api/v3',
            'api_key_url' => 'https://console.byteplus.com/ark/region:ark+ap-southeast-1/apiKey',
        ],
        'copilot-proxy' => [
            'category' => ['developer-tool', 'local'],
            'region' => ['global'],
            'display_name' => 'Copilot Proxy',
            'description' => 'Local proxy for VS Code Copilot models — requires Copilot Proxy extension',
            'base_url' => 'http://localhost:1337/v1',
            'auth_type' => 'local',
        ],
        'ollama' => [
            'category' => ['local'],
            'region' => ['global'],
            'display_name' => 'Ollama (Local)',
            'description' => 'Self-hosted local inference — free and private',
            'base_url' => 'http://localhost:11434/v1',
            'auth_type' => 'local',
        ],
        'vllm' => [
            'category' => ['local'],
            'region' => ['global'],
            'display_name' => 'vLLM (Local)',
            'description' => 'Self-hosted OpenAI-compatible server — models discovered at runtime',
            'base_url' => 'http://127.0.0.1:8000/v1',
            'auth_type' => 'local',
        ],
        'litellm' => [
            'category' => ['gateway', 'local'],
            'region' => ['global'],
            'display_name' => 'LiteLLM (Local)',
            'description' => 'Unified LLM gateway — proxy for 100+ providers',
            'base_url' => 'http://localhost:4000',
            'auth_type' => 'local',
        ],
    ],
];
