<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Full-page component for setting up a single AI provider connection.
// Handles shared setup concerns (credential entry, validation, connect, and
// generic device-flow lifecycle). Provider-specific variants extend this class.

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Services\ModelCatalogService;
use App\Base\Foundation\Contracts\CompanyScoped;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\Livewire\Concerns\FormatsDisplayValues;
use App\Modules\Core\AI\Livewire\Concerns\ManagesModels;
use App\Modules\Core\AI\Livewire\Concerns\ManagesProviderHelp;
use App\Modules\Core\AI\Livewire\Concerns\ManagesSync;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
     *
     * Delegates validation to the provider definition. ValidationException
     * errors are remapped from definition field keys (e.g. base_url) to
     * Livewire property names (e.g. baseUrl) for correct error display.
     */
    public function connect(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $this->connectError = null;

        try {
            $provider = $this->connectProvider($companyId);
            $this->cleanupAuthFlows();
            $this->connectedProviderId = $provider->id;
        } catch (ValidationException $e) {
            $mapped = [];

            foreach ($e->errors() as $key => $messages) {
                $mapped[$this->mapFieldToProperty($key)] = $messages;
            }

            throw ValidationException::withMessages($mapped);
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
     * Gather raw input from Livewire properties for the definition.
     *
     * Keys must match the definition's expected field keys.
     * Override in child classes that have different credential shapes.
     *
     * @return array<string, mixed>
     */
    protected function gatherInput(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey,
        ];
    }

    /**
     * Map a definition field key to its Livewire property name for error display.
     *
     * Override in child classes with custom field mappings.
     */
    protected function mapFieldToProperty(string $fieldKey): string
    {
        return match ($fieldKey) {
            'base_url' => 'baseUrl',
            'api_key' => 'apiKey',
            default => $fieldKey,
        };
    }

    /**
     * Create the provider record and run initial model discovery.
     *
     * Delegates validation and normalization to the provider definition,
     * which returns model attributes (base_url, credentials, connection_config, auth_type).
     */
    private function connectProvider(int $companyId): AiProvider
    {
        $existing = AiProvider::query()
            ->forCompany($companyId)
            ->where('name', $this->providerKey)
            ->first();

        if ($existing) {
            if (! $existing->models()->exists()) {
                app(ModelDiscoveryService::class)->syncModels($existing);
            }

            return $existing;
        }

        $definition = app(ProviderDefinitionRegistry::class)->for($this->providerKey);
        $normalized = $definition->validateAndNormalize(
            $this->gatherInput(),
            ProviderOperation::Create,
        );

        $provider = AiProvider::query()->create(array_merge($normalized, [
            'company_id' => $companyId,
            'name' => $this->providerKey,
            'display_name' => $this->displayName,
            'is_active' => true,
            'created_by' => Auth::user()?->employee?->id,
        ]));

        $provider->assignNextPriority();
        app(ModelDiscoveryService::class)->syncModels($provider);

        return $provider;
    }

    protected function getCompanyId(): ?int
    {
        $user = Auth::user();

        return $user instanceof CompanyScoped
            ? $user->getCompanyId()
            : null;
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
