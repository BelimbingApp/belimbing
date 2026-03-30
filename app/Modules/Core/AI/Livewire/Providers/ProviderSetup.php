<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Full-page component for setting up a single AI provider connection.
// Handles shared setup concerns (credential entry, validation, connect, and
// generic device-flow lifecycle). Provider-specific variants extend this class.

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\Livewire\Concerns\FormatsDisplayValues;
use App\Modules\Core\AI\Livewire\Concerns\ManagesModels;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviderHelp;
use App\Modules\Core\AI\Livewire\Concerns\ManagesSync;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ProviderSetup extends Component
{
    use FormatsDisplayValues;
    use ManagesModels;
    use ManagesProviderHelp;
    use ManagesSync;

    public string $providerKey = '';

    public string $displayName = '';

    public string $baseUrl = '';

    public string $apiKey = '';

    public ?string $apiKeyUrl = null;

    public string $authType = 'api_key';

    /** @var array{status: string, user_code: string|null, verification_uri: string|null, error: string|null} */
    public array $deviceFlow = ['status' => 'idle', 'user_code' => null, 'verification_uri' => null, 'error' => null];

    public ?string $connectError = null;

    /** The connected provider record, set after successful connection. */
    public ?int $connectedProviderId = null;

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

        $this->setUpProvider();
    }

    /**
     * Hook for provider-specific setup. Override in child classes.
     */
    protected function setUpProvider(): void {}

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

        $this->validate($rules, $this->buildValidationMessages());

        $this->connectError = null;

        try {
            $provider = $this->connectProvider($companyId);
            $this->cleanupAuthFlows();
            $this->connectedProviderId = $provider->id;
        } catch (ConnectionException $e) {
            $this->connectError = __('Could not connect to :url — is the server running?', [
                'url' => $this->baseUrl,
            ]);

            Log::warning('Provider connect failed', [
                'provider' => $this->providerKey,
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->connectError = __('Failed to connect: :message', [
                'message' => $e->getMessage(),
            ]);

            Log::warning('Provider connect failed', [
                'provider' => $this->providerKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-connect when the API key field is updated (blur).
     *
     * For standard providers (api_key, custom, oauth, subscription, local),
     * connecting as soon as credentials are entered removes the manual
     * "Connect & Import Models" step.
     */
    public function updatedApiKey(): void
    {
        $this->tryAutoConnect();
    }

    /**
     * Auto-connect when the base URL is updated (blur).
     *
     * For local/oauth providers where API key is optional, the base URL
     * alone is sufficient to attempt connection.
     */
    public function updatedBaseUrl(): void
    {
        $this->tryAutoConnect();
    }

    /**
     * Attempt auto-connect if all required fields are populated.
     */
    protected function tryAutoConnect(): void
    {
        if ($this->connectedProviderId !== null || $this->authType === 'device_flow') {
            return;
        }

        if ($this->baseUrl === '') {
            return;
        }

        $keyRequired = in_array($this->authType, ['api_key', 'custom'], true);

        if ($keyRequired && $this->apiKey === '') {
            return;
        }

        $this->connect();
    }

    /**
     * Mask the API key for visual verification.
     */
    public function getMaskedApiKeyProperty(): ?string
    {
        return BlbStr::maskMiddle($this->apiKey, 7, 4);
    }

    /**
     * Navigate back to the provider catalog.
     */
    public function backToCatalog(): void
    {
        $this->cleanupAuthFlows();
        $this->redirectRoute('admin.ai.providers', navigate: true);
    }

    /**
     * Navigate to the main AI Providers page after setup is complete.
     */
    public function done(): void
    {
        $this->redirectRoute('admin.ai.providers', navigate: true);
    }

    public function render(): View
    {
        $connectedProvider = null;
        $models = collect();

        if ($this->connectedProviderId !== null) {
            $connectedProvider = AiProvider::query()->find($this->connectedProviderId);

            if ($connectedProvider) {
                $models = AiProviderModel::query()
                    ->where('ai_provider_id', $connectedProvider->id)
                    ->orderBy('model_id')
                    ->get();
            }
        }

        return view('livewire.admin.ai.providers.provider-setup', [
            'connectedProvider' => $connectedProvider,
            'models' => $models,
        ]);
    }

    /**
     * Build validation rules based on provider type.
     *
     * @return array<string, list<string>>
     */
    protected function buildValidationRules(): array
    {
        $rules = [];

        $rules['baseUrl'] = ['required', 'string', 'max:2048'];

        if (in_array($this->authType, ['api_key', 'custom', 'device_flow'], true)) {
            $rules['apiKey'] = ['required', 'string', 'max:2048'];
        } else {
            $rules['apiKey'] = ['nullable', 'string', 'max:2048'];
        }

        return $rules;
    }

    /**
     * Build validation messages for shared provider setup fields.
     *
     * @return array<string, string>
     */
    protected function buildValidationMessages(): array
    {
        return [
            'baseUrl.required' => __('Base URL is required.'),
            'apiKey.required' => __('API key is required.'),
        ];
    }

    /**
     * Create the provider record and run initial model discovery.
     *
     * Returns the connected provider (existing or newly created) so the
     * setup page can transition to model management without redirecting.
     */
    private function connectProvider(int $companyId): AiProvider
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

            return $existing;
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

        return $provider;
    }

    /**
     * Build the provider base URL used for connection and model discovery.
     */
    protected function resolveBaseUrl(): string
    {
        return $this->baseUrl;
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
