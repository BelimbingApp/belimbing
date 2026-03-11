<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Full-page component for setting up a single AI provider connection.
// Handles API key entry, device flow (GitHub Copilot), and Cloudflare
// AI Gateway configuration. Replaces the multi-provider ConnectWizard.

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use Livewire\Component;

class ProviderSetup extends Component
{
    public string $providerKey = '';

    public string $displayName = '';

    public string $baseUrl = '';

    public string $apiKey = '';

    public ?string $apiKeyUrl = null;

    public string $authType = 'api_key';

    /** @var array{status: string, user_code: string|null, verification_uri: string|null, error: string|null} */
    public array $deviceFlow = ['status' => 'idle', 'user_code' => null, 'verification_uri' => null, 'error' => null];

    public string $cloudflareAccountId = '';

    public string $cloudflareGatewayId = '';

    public ?string $connectError = null;

    /**
     * Initialise component from route parameter and catalog template.
     *
     * @param  string  $providerKey  Provider key from route
     */
    public function mount(string $providerKey): void
    {
        $allProviders = app(ModelCatalogService::class)->getProviders();
        $tpl = $allProviders[$providerKey] ?? null;

        if ($tpl === null) {
            $this->redirectRoute('admin.ai.providers', navigate: true);

            return;
        }

        $this->providerKey = $providerKey;
        $this->displayName = $tpl['display_name'] ?? $providerKey;
        $this->baseUrl = $tpl['base_url'] ?? '';
        $this->apiKeyUrl = $tpl['api_key_url'] ?? null;
        $this->authType = $tpl['auth_type'] ?? 'api_key';

        if ($this->authType === 'device_flow') {
            $this->startDeviceFlow();
        }
    }

    /**
     * Start an interactive auth flow (e.g. GitHub device flow).
     */
    public function startDeviceFlow(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $this->deviceFlow = $service->startFlow($this->providerKey, $companyId, 0);
    }

    /**
     * Poll an active device flow for completion (called via wire:poll).
     */
    public function pollDeviceFlow(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $result = $service->pollFlow($this->providerKey, $companyId, 0);

        if ($result['status'] === 'pending') {
            return;
        }

        if ($result['status'] === 'success') {
            $this->apiKey = $result['api_key'] ?? '';
            $this->baseUrl = $result['base_url'] ?? $this->baseUrl;
        }

        $this->deviceFlow['status'] = $result['status'];
        $this->deviceFlow['error'] = $result['error'] ?? null;
    }

    /**
     * Connect the provider and import its models.
     */
    public function connect(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $rules = $this->buildValidationRules();

        $this->validate($rules, [
            'baseUrl.required' => __('Base URL is required.'),
            'apiKey.required' => __('API key is required.'),
            'cloudflareAccountId.required' => __('Account ID is required.'),
            'cloudflareGatewayId.required' => __('Gateway ID is required.'),
        ]);

        $this->connectError = null;

        try {
            $this->connectProvider($companyId);
            $this->cleanupAuthFlows();
            $this->redirectRoute('admin.ai.providers', navigate: true);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->connectError = __('Could not connect to :url — is the server running?', [
                'url' => $this->baseUrl,
            ]);

            \Illuminate\Support\Facades\Log::warning('Provider connect failed', [
                'provider' => $this->providerKey,
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->connectError = __('Failed to connect: :message', [
                'message' => $e->getMessage(),
            ]);

            \Illuminate\Support\Facades\Log::warning('Provider connect failed', [
                'provider' => $this->providerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Navigate back to the provider catalog.
     */
    public function backToCatalog(): void
    {
        $this->cleanupAuthFlows();
        $this->redirectRoute('admin.ai.providers', navigate: true);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.ai.providers.provider-setup');
    }

    /**
     * Build validation rules based on provider type.
     *
     * @return array<string, list<string>>
     */
    private function buildValidationRules(): array
    {
        $rules = [];

        if ($this->providerKey === 'cloudflare-ai-gateway') {
            $rules['cloudflareAccountId'] = ['required', 'string', 'max:255'];
            $rules['cloudflareGatewayId'] = ['required', 'string', 'max:255'];
        } else {
            $rules['baseUrl'] = ['required', 'string', 'max:2048'];
        }

        if (in_array($this->authType, ['api_key', 'custom', 'device_flow'], true)) {
            $rules['apiKey'] = ['required', 'string', 'max:2048'];
        } else {
            $rules['apiKey'] = ['nullable', 'string', 'max:2048'];
        }

        return $rules;
    }

    /**
     * Create the provider record and run initial model discovery.
     */
    private function connectProvider(int $companyId): void
    {
        $existing = AiProvider::query()
            ->forCompany($companyId)
            ->where('name', $this->providerKey)
            ->first();

        if ($existing) {
            $discovery = app(ModelDiscoveryService::class);

            if (! $existing->models()->exists()) {
                $discovery->syncModels($existing);
            }

            return;
        }

        $discovery = app(ModelDiscoveryService::class);

        $provider = AiProvider::query()->create([
            'company_id' => $companyId,
            'name' => $this->providerKey,
            'display_name' => $this->displayName,
            'base_url' => $this->resolveBaseUrl(),
            'api_key' => $this->apiKey !== '' ? $this->apiKey : 'not-required',
            'is_active' => true,
            'created_by' => auth()->user()->employee?->id,
        ]);

        $provider->assignNextPriority();
        $discovery->syncModels($provider);
    }

    /**
     * Build the provider base URL, handling Cloudflare's Account+Gateway ID pattern.
     */
    private function resolveBaseUrl(): string
    {
        if ($this->providerKey !== 'cloudflare-ai-gateway') {
            return $this->baseUrl;
        }

        $accountId = trim($this->cloudflareAccountId);
        $gatewayId = trim($this->cloudflareGatewayId);

        return "https://gateway.ai.cloudflare.com/v1/{$accountId}/{$gatewayId}/openai";
    }

    private function getCompanyId(): ?int
    {
        $user = auth()->user();

        return $user?->employee?->company_id ? (int) $user->employee->company_id : null;
    }

    /**
     * Clean up cached auth flow data for this company.
     */
    private function cleanupAuthFlows(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null || $this->deviceFlow['status'] === 'idle') {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $service->cleanupFlows($companyId, [0]);
    }
}
