<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Per-tool Workspace — overview, setup configuration, Try It console, and verification.

namespace App\Modules\Core\AI\Livewire\Tools;

use App\Base\AI\Services\WebSearchService;
use App\Base\AI\Tools\ToolResult;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Tools\WebSearchTool;
use Livewire\Component;

class Workspace extends Component
{
    public string $toolName;

    /** @var array<string, mixed> Config field values keyed by setting key */
    public array $configValues = [];

    /** @var string|null Result from the last Try It execution */
    public ?string $tryItResult = null;

    /** @var bool Whether the last Try It result was an error */
    public bool $tryItIsError = false;

    /** @var array{code: string, message: string, hint?: string, action?: array{label: string, suggested_prompt: string}}|null Structured error data from last Try It */
    public ?array $tryItErrorPayload = null;

    /** @var bool Whether the Try It execution is in progress */
    public bool $tryItLoading = false;

    /** @var string|null Flash message for config save (success or error) */
    public ?string $configSaved = null;

    /** @var bool Whether the last config save was an error */
    public bool $configSaveError = false;

    /** @var string|null Error message when saving verification status (e.g. DB schema mismatch) */
    public ?string $verificationError = null;

    // ── Web Search provider management ──────────────────────────────

    /** @var list<array{name: string, api_key: string, has_key: bool, key_preview: string, enabled: bool}> */
    public array $webSearchProviders = [];

    public function mount(): void
    {
        $this->loadConfigValues();
        $this->loadWebSearchProviders();
    }

    /**
     * Save configuration values via the Settings module.
     *
     * For web_search, also saves the providers list and re-registers the tool.
     */
    public function saveConfig(): void
    {
        $settings = app(SettingsService::class);
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata) {
            return;
        }

        try {
            foreach ($metadata->configFields as $field) {
                $value = data_get($this->configValues, $field->key);

                // Secret fields: skip empty values to preserve existing keys
                if ($field->type === 'secret' && ($value === null || $value === '')) {
                    continue;
                }

                if ($value === null || $value === '') {
                    $settings->forget($field->key);

                    continue;
                }

                if ($field->type === 'boolean') {
                    $value = (bool) $value;
                }

                $settings->set($field->key, $value, encrypted: $field->encrypted);
            }

            $this->saveWebSearchProviders($settings);
        } catch (\Throwable $e) {
            report($e);
            $this->configSaveError = true;
            $this->configSaved = __('Could not save configuration: :message. If the settings table is out of date, run: php artisan migrate', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $this->refreshConditionalTool();

        $this->configSaveError = false;
        $this->configSaved = __('Configuration saved.');
        $this->clearTryItResult();
        $this->loadConfigValues();
        $this->loadWebSearchProviders();
    }

    /**
     * Execute a tool test using a predefined example.
     */
    public function tryIt(int $exampleIndex): void
    {
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata || ! isset($metadata->testExamples[$exampleIndex])) {
            $this->tryItResult = __('Example not found.');
            $this->tryItIsError = true;
            $this->tryItErrorPayload = null;

            return;
        }

        $registry = app(AgentToolRegistry::class);
        $example = $metadata->testExamples[$exampleIndex];

        if (! ($example['runnable'] ?? true)) {
            $this->tryItResult = __('This example is for display only and cannot be executed from the workspace.');
            $this->tryItIsError = true;
            $this->tryItErrorPayload = null;

            return;
        }

        if (! $registry->isRegistered($this->toolName)) {
            $this->tryItResult = __('Error: This tool is not configured yet. Set the required API key and provider in the Configuration panel, then try again.');
            $this->tryItIsError = true;
            $this->tryItErrorPayload = null;

            return;
        }

        try {
            $result = $registry->execute($this->toolName, $example['input']);
            $this->tryItResult = (string) $result;
            $this->tryItIsError = $result->isError;
            $this->tryItErrorPayload = $this->serializeErrorPayload($result);
        } catch (\Throwable $e) {
            $this->tryItResult = __('Error: :message', ['message' => $e->getMessage()]);
            $this->tryItIsError = true;
            $this->tryItErrorPayload = null;
        }

        $this->verificationError = null;
        $this->storeVerification($this->tryItIsError);
    }

    public function clearTryItResult(): void
    {
        $this->tryItResult = null;
        $this->tryItIsError = false;
        $this->tryItErrorPayload = null;
    }

    // ── Web Search provider actions ─────────────────────────────────

    public function addWebSearchProvider(): void
    {
        $used = array_column($this->webSearchProviders, 'name');
        $available = array_diff(array_keys(WebSearchTool::PROVIDERS), $used);

        if ($available === []) {
            return;
        }

        $this->webSearchProviders[] = [
            'name' => reset($available),
            'api_key' => '',
            'has_key' => false,
            'key_preview' => '',
            'enabled' => true,
        ];
    }

    public function removeWebSearchProvider(int $index): void
    {
        unset($this->webSearchProviders[$index]);
        $this->webSearchProviders = array_values($this->webSearchProviders);
    }

    /**
     * Reorder providers from a drag-drop operation.
     *
     * @param  list<int>  $order  New index order
     */
    public function reorderWebSearchProviders(array $order): void
    {
        $reordered = [];

        foreach ($order as $oldIndex) {
            if (isset($this->webSearchProviders[$oldIndex])) {
                $reordered[] = $this->webSearchProviders[$oldIndex];
            }
        }

        $this->webSearchProviders = $reordered;
    }

    /**
     * Reset has_key when a provider name changes (key is name-bound).
     *
     * Livewire lifecycle hook: fires on any update to the webSearchProviders
     * array, including name changes via the provider dropdown.
     */
    public function updatedWebSearchProviders(mixed $value, string $key): void
    {
        // $key format: "0.name", "1.enabled", etc.
        if (str_ends_with($key, '.name')) {
            $index = (int) explode('.', $key, 2)[0];

            if (isset($this->webSearchProviders[$index])) {
                $providerName = $this->webSearchProviders[$index]['name'];
                $storedApiKey = $this->storedWebSearchApiKey(is_string($providerName) ? $providerName : '');

                $this->webSearchProviders[$index]['has_key'] = false;
                $this->webSearchProviders[$index]['api_key'] = '';
                $this->webSearchProviders[$index]['key_preview'] = '';

                if ($storedApiKey !== null && $storedApiKey !== '') {
                    $this->webSearchProviders[$index]['has_key'] = true;
                    $this->webSearchProviders[$index]['key_preview'] = BlbStr::maskMiddle($storedApiKey, 6, 4) ?? '';
                }
            }
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $metadataRegistry = app(ToolMetadataRegistry::class);
        $readinessService = app(ToolReadinessService::class);

        $metadata = $metadataRegistry->get($this->toolName);

        if (! $metadata) {
            return view('livewire.admin.ai.tools.workspace', [
                'metadata' => null,
                'readiness' => ToolReadiness::UNAVAILABLE,
                'lastVerified' => null,
                'availableProviders' => [],
                'markdown' => app(ChatMarkdownRenderer::class),
            ]);
        }

        return view('livewire.admin.ai.tools.workspace', [
            'metadata' => $metadata,
            'readiness' => $readinessService->readiness($this->toolName),
            'lastVerified' => $this->getLastVerified(),
            'availableProviders' => WebSearchTool::PROVIDERS,
            'markdown' => app(ChatMarkdownRenderer::class),
        ]);
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Load current config values from Settings for display in the form.
     */
    private function loadConfigValues(): void
    {
        $settings = app(SettingsService::class);
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata) {
            return;
        }

        foreach ($metadata->configFields as $field) {
            $defaultValue = config($field->key);
            $value = $settings->get($field->key, $defaultValue);

            // Mask secret fields that have a value
            if ($field->type === 'secret' && $value !== null && $value !== '') {
                data_set($this->configValues, $field->key, '');
            } else {
                data_set($this->configValues, $field->key, $value ?? $defaultValue ?? '');
            }
        }
    }

    /**
     * Load web search providers from settings for the provider management UI.
     */
    private function loadWebSearchProviders(): void
    {
        if ($this->toolName !== 'web_search') {
            return;
        }

        $settings = app(SettingsService::class);
        $providers = $settings->get('ai.tools.web_search.providers');

        if (is_array($providers) && $providers !== []) {
            $this->webSearchProviders = array_map(fn ($p) => [
                'name' => $p['name'] ?? 'parallel',
                'api_key' => '',
                'has_key' => ! empty($p['api_key'] ?? ''),
                'key_preview' => BlbStr::maskMiddle($p['api_key'] ?? null, 6, 4) ?? '',
                'enabled' => (bool) ($p['enabled'] ?? true),
            ], $providers);

            return;
        }

        // Fallback: bootstrap from legacy single-provider config
        $provider = $settings->get('ai.tools.web_search.provider', 'parallel');
        $apiKey = $settings->get("ai.tools.web_search.{$provider}.api_key");

        $this->webSearchProviders = [
            [
                'name' => is_string($provider) ? $provider : 'parallel',
                'api_key' => '',
                'has_key' => is_string($apiKey) && $apiKey !== '',
                'key_preview' => BlbStr::maskMiddle($apiKey, 6, 4) ?? '',
                'enabled' => true,
            ],
        ];
    }

    /**
     * Resolve the currently stored API key for a given web search provider.
     */
    private function storedWebSearchApiKey(string $providerName): ?string
    {
        if ($providerName === '') {
            return null;
        }

        $settings = app(SettingsService::class);
        $providers = $settings->get('ai.tools.web_search.providers');

        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (($provider['name'] ?? null) === $providerName) {
                    $apiKey = $provider['api_key'] ?? null;

                    return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
                }
            }
        }

        $legacyApiKey = $settings->get("ai.tools.web_search.{$providerName}.api_key");

        return is_string($legacyApiKey) && $legacyApiKey !== '' ? $legacyApiKey : null;
    }

    /**
     * Persist the provider list to settings.
     *
     * Merges user-entered API keys with existing stored keys (matched by
     * provider name) so that masked fields don't erase saved secrets.
     */
    private function saveWebSearchProviders(SettingsService $settings): void
    {
        if ($this->toolName !== 'web_search') {
            return;
        }

        $existing = $settings->get('ai.tools.web_search.providers') ?? [];

        // Build lookup of existing API keys by provider name
        $existingKeys = [];
        if (is_array($existing)) {
            foreach ($existing as $p) {
                $existingKeys[$p['name'] ?? ''] = $p['api_key'] ?? '';
            }
        }

        $providers = [];

        foreach ($this->webSearchProviders as $p) {
            $apiKey = trim($p['api_key'] ?? '');

            // Empty key means "keep existing" (the field was masked)
            if ($apiKey === '' && isset($existingKeys[$p['name']])) {
                $apiKey = $existingKeys[$p['name']];
            }

            $providers[] = [
                'name' => $p['name'],
                'api_key' => $apiKey,
                'enabled' => (bool) ($p['enabled'] ?? true),
            ];
        }

        $settings->set('ai.tools.web_search.providers', $providers, encrypted: true);
    }

    /**
     * Re-register a conditional tool after configuration changes.
     *
     * Conditional tools (web_search, memory_search) may not have been
     * registered at boot if their config was missing. After saving new
     * config, re-run the factory to register (or update) the tool.
     */
    private function refreshConditionalTool(): void
    {
        $registry = app(AgentToolRegistry::class);

        $tool = match ($this->toolName) {
            'web_search' => WebSearchTool::createIfConfigured(app(WebSearchService::class)),
            default => null,
        };

        if ($tool !== null) {
            $registry->register($tool);
        }
    }

    /**
     * Store verification result from a Try It execution.
     *
     * @param  bool  $isError  Whether the tool result was an error
     */
    private function storeVerification(bool $isError): void
    {
        try {
            $settings = app(SettingsService::class);

            $settings->set("ai.tools.{$this->toolName}.last_verified_at", now()->toIso8601String());
            $settings->set("ai.tools.{$this->toolName}.last_verified_success", ! $isError);
        } catch (\Throwable $e) {
            report($e);
            $this->verificationError = __('Could not save verification status. The error was: :message. If the settings table is out of date, run: php artisan migrate', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Serialize a ToolResult's error payload for Livewire public property storage.
     *
     * @return array{code: string, message: string, hint?: string, action?: array{label: string, suggested_prompt: string}}|null
     */
    private function serializeErrorPayload(ToolResult $result): ?array
    {
        if (! $result->isError || $result->errorPayload === null) {
            return null;
        }

        $payload = [
            'code' => $result->errorPayload->code,
            'message' => $result->errorPayload->message,
        ];

        if ($result->errorPayload->hint !== null) {
            $payload['hint'] = $result->errorPayload->hint;
        }

        if ($result->errorPayload->action !== null) {
            $payload['action'] = [
                'label' => $result->errorPayload->action->label,
                'suggested_prompt' => $result->errorPayload->action->suggestedPrompt,
            ];
        }

        return $payload;
    }

    /**
     * Get the last verification result for this tool.
     *
     * @return array{at: string, success: bool}|null
     */
    private function getLastVerified(): ?array
    {
        $settings = app(SettingsService::class);
        $at = $settings->get("ai.tools.{$this->toolName}.last_verified_at");

        if ($at === null) {
            return null;
        }

        return [
            'at' => $at,
            'success' => (bool) $settings->get("ai.tools.{$this->toolName}.last_verified_success", false),
        ];
    }
}
