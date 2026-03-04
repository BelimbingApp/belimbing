<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Provider catalog and onboarding flow inspired by OpenClaw
// (github.com/nicepkg/openclaw). Adapted for BLB's GUI context.

use App\Base\AI\Providers\Help\ProviderHelpRegistry;
use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $search = '';

    public ?int $expandedProviderId = null;

    // --- Wizard state ---

    /** @var string|null null = manage view, 'catalog' = step 1, 'connect' = step 2 */
    public ?string $wizardStep = null;

    /** @var list<string> Selected template keys for onboarding */
    public array $selectedTemplates = [];

    /** @var string|null Which catalog provider row is expanded */
    public ?string $expandedCatalogProvider = null;

    /** @var list<array{key: string, display_name: string, base_url: string, api_key: string, api_key_url: string|null}>  Connect form data */
    public array $connectForms = [];

    /** @var array<int, array{status: string, user_code: string|null, verification_uri: string|null, error: string|null}>  Device flow state per connect form index */
    public array $deviceFlows = [];

    /** @var array<int, string>  Per-card connection errors shown on the connect step */
    public array $connectErrors = [];

    // --- Provider form (manual CRUD) ---

    public bool $showProviderForm = false;

    public bool $isEditingProvider = false;

    public ?int $editingProviderId = null;

    public string $providerName = '';

    public string $providerDisplayName = '';

    public string $providerBaseUrl = '';

    public string $providerApiKey = '';

    public bool $providerIsActive = true;

    public string $selectedTemplate = '';

    // Provider delete
    public bool $showDeleteProvider = false;

    public ?int $deletingProviderId = null;

    public string $deletingProviderName = '';

    // Model form
    public bool $showModelForm = false;

    public bool $isEditingModel = false;

    public ?int $editingModelId = null;

    public ?int $modelProviderId = null;

    public string $modelModelName = '';

    public bool $modelIsActive = true;

    public string $modelCostInput = '0.000000';

    public string $modelCostOutput = '0.000000';

    public string $modelCostCacheRead = '0.000000';

    public string $modelCostCacheWrite = '0.000000';

    // Sync result flash
    public ?string $syncMessage = null;

    /** Persistent sync error (connection failures — not auto-dismissed) */
    public ?string $syncError = null;

    /** Provider ID the current syncError belongs to */
    public ?int $syncErrorProviderId = null;

    // Help modal
    public ?string $helpProviderKey = null;

    public ?string $helpProviderAuthType = null;

    // Model delete
    public bool $showDeleteModel = false;

    public ?int $deletingModelId = null;

    public string $deletingModelName = '';

    public function mount(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $hasProviders = AiProvider::query()->forCompany($companyId)->exists();

        if (! $hasProviders) {
            $this->wizardStep = 'catalog';
        }
    }

    public function toggleProvider(int $providerId): void
    {
        $this->expandedProviderId = $this->expandedProviderId === $providerId ? null : $providerId;
    }

    // --- Wizard methods ---

    /**
     * Open the provider catalog (step 1 of onboarding wizard).
     */
    public function openCatalog(): void
    {
        $this->selectedTemplates = [];
        $this->expandedCatalogProvider = null;
        $this->wizardStep = 'catalog';
    }

    /**
     * Toggle expansion of a provider row in the catalog view.
     */
    public function toggleCatalogProvider(string $key): void
    {
        $this->expandedCatalogProvider = $this->expandedCatalogProvider === $key ? null : $key;
    }

    /**
     * Toggle selection of a template provider in the catalog.
     */
    public function toggleSelectTemplate(string $key): void
    {
        if (in_array($key, $this->selectedTemplates, true)) {
            $this->selectedTemplates = array_values(array_diff($this->selectedTemplates, [$key]));
        } else {
            $this->selectedTemplates[] = $key;
        }
    }

    /**
     * Advance from catalog (step 1) to connect (step 2).
     *
     * Builds per-provider connect forms pre-filled from templates.
     */
    public function proceedToConnect(): void
    {
        if (count($this->selectedTemplates) === 0) {
            return;
        }

        $templates = app(ModelCatalogService::class)->getProviders();
        $this->connectForms = [];

        foreach ($this->selectedTemplates as $key) {
            $tpl = $templates[$key] ?? null;

            if ($tpl === null) {
                continue;
            }

            $formEntry = [
                'key' => $key,
                'display_name' => $tpl['display_name'] ?? $key,
                'base_url' => $tpl['base_url'] ?? '',
                'api_key' => '',
                'api_key_url' => $tpl['api_key_url'] ?? null,
                'auth_type' => $tpl['auth_type'] ?? 'api_key',
            ];

            // Cloudflare AI Gateway needs Account ID + Gateway ID to build the URL
            if ($key === 'cloudflare-ai-gateway') {
                $formEntry['cloudflare_account_id'] = '';
                $formEntry['cloudflare_gateway_id'] = '';
            }

            $this->connectForms[] = $formEntry;
        }

        $this->wizardStep = 'connect';

        // Auto-start device flows (e.g. GitHub Copilot) — no idle screen needed
        foreach ($this->connectForms as $index => $form) {
            if (($form['auth_type'] ?? 'api_key') === 'device_flow') {
                $this->startDeviceFlow($index);
            }
        }
    }

    /**
     * Go back from connect (step 2) to catalog (step 1).
     */
    public function backToCatalog(): void
    {
        $this->cleanupAuthFlows();
        $this->deviceFlows = [];
        $this->connectErrors = [];
        $this->wizardStep = 'catalog';
    }

    /**
     * Remove a single provider from the connect forms.
     *
     * If no forms remain, returns to catalog. Cleans up any active
     * device flow for the removed form and re-indexes arrays.
     *
     * @param  int  $index  Connect form index to remove
     */
    public function removeConnectForm(int $index): void
    {
        if (! isset($this->connectForms[$index])) {
            return;
        }

        $companyId = $this->getCompanyId();
        $key = $this->connectForms[$index]['key'];

        // Clean up device flow cache if active
        if ($companyId !== null && isset($this->deviceFlows[$index])) {
            app(ProviderAuthFlowService::class)->cleanupFlows($companyId, [$index]);
        }

        // Remove from selectedTemplates so catalog reflects the change
        $this->selectedTemplates = array_values(array_diff($this->selectedTemplates, [$key]));

        // Remove form and re-index
        array_splice($this->connectForms, $index, 1);
        unset($this->deviceFlows[$index], $this->connectErrors[$index]);
        $this->deviceFlows = array_values($this->deviceFlows);
        $this->connectErrors = array_values($this->connectErrors);

        // If no forms left, return to catalog
        if (count($this->connectForms) === 0) {
            $this->wizardStep = 'catalog';
        }
    }

    // --- Auth Flow methods (dispatched to ProviderAuthFlowService) ---

    /**
     * Start an interactive auth flow for a connect form entry.
     *
     * Delegates to ProviderAuthFlowService which handles provider-specific
     * logic (e.g., GitHub device flow). Sensitive data stays in server cache.
     *
     * @param  int  $index  Connect form index
     */
    public function startDeviceFlow(int $index): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $this->deviceFlows[$index] = $service->startFlow(
            $this->connectForms[$index]['key'],
            $companyId,
            $index,
        );
    }

    /**
     * Poll an active auth flow for completion (called via wire:poll).
     *
     * On success, updates connectForms with the obtained credentials.
     *
     * @param  int  $index  Connect form index
     */
    public function pollDeviceFlow(int $index): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $result = $service->pollFlow(
            $this->connectForms[$index]['key'],
            $companyId,
            $index,
        );

        if ($result['status'] === 'pending') {
            return;
        }

        if ($result['status'] === 'success') {
            $this->connectForms[$index]['api_key'] = $result['api_key'] ?? '';
            $this->connectForms[$index]['base_url'] = $result['base_url'] ?? $this->connectForms[$index]['base_url'];
        }

        $this->deviceFlows[$index]['status'] = $result['status'];
        $this->deviceFlows[$index]['error'] = $result['error'] ?? null;
    }

    /**
     * Create providers and import models for all connected forms.
     *
     * Processes each provider independently — failures on one provider do not
     * block others. Errors are captured per-card in $connectErrors and shown
     * inline. Successfully connected providers are removed from the form list.
     * The wizard only exits when all providers succeed.
     */
    public function connectAll(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $rules = [];

        foreach ($this->connectForms as $index => $form) {
            $authType = $form['auth_type'] ?? 'api_key';
            $key = $form['key'];

            // Cloudflare uses Account ID + Gateway ID instead of base_url input
            if ($key === 'cloudflare-ai-gateway') {
                $rules["connectForms.{$index}.cloudflare_account_id"] = ['required', 'string', 'max:255'];
                $rules["connectForms.{$index}.cloudflare_gateway_id"] = ['required', 'string', 'max:255'];
            } else {
                $rules["connectForms.{$index}.base_url"] = ['required', 'string', 'max:2048'];
            }

            if (in_array($authType, ['api_key', 'custom', 'device_flow'], true)) {
                $rules["connectForms.{$index}.api_key"] = ['required', 'string', 'max:2048'];
            } else {
                $rules["connectForms.{$index}.api_key"] = ['nullable', 'string', 'max:2048'];
            }
        }

        $this->validate($rules, [
            'connectForms.*.base_url.required' => __('Base URL is required.'),
            'connectForms.*.api_key.required' => __('API key is required.'),
            'connectForms.*.cloudflare_account_id.required' => __('Account ID is required.'),
            'connectForms.*.cloudflare_gateway_id.required' => __('Gateway ID is required.'),
        ]);

        $this->connectErrors = [];
        $discovery = app(ModelDiscoveryService::class);
        $succeeded = [];

        foreach ($this->connectForms as $index => $form) {
            try {
                $key = $form['key'];

                $existing = AiProvider::query()
                    ->forCompany($companyId)
                    ->where('name', $key)
                    ->first();

                if ($existing) {
                    // Provider exists but has no models — retry model import
                    if ($existing->models()->count() === 0) {
                        $discovery->syncModels($existing);
                    }

                    $succeeded[] = $index;

                    continue;
                }

                // Cloudflare: build base_url from Account ID + Gateway ID
                $baseUrl = $form['base_url'];

                if ($key === 'cloudflare-ai-gateway') {
                    $accountId = trim($form['cloudflare_account_id'] ?? '');
                    $gatewayId = trim($form['cloudflare_gateway_id'] ?? '');
                    $baseUrl = "https://gateway.ai.cloudflare.com/v1/{$accountId}/{$gatewayId}/openai";
                }

                $provider = AiProvider::query()->create([
                    'company_id' => $companyId,
                    'name' => $key,
                    'display_name' => $form['display_name'],
                    'base_url' => $baseUrl,
                    'api_key' => $form['api_key'] !== '' ? $form['api_key'] : 'not-required',
                    'is_active' => true,
                    'created_by' => auth()->user()->employee?->id,
                ]);

                // Auto-assign priority for each new provider
                $provider->assignNextPriority();

                // Discover and import models (falls back to template on failure)
                $discovery->syncModels($provider);

                $succeeded[] = $index;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $this->connectErrors[$index] = __('Could not connect to :url — is the server running?', [
                    'url' => $form['base_url'],
                ]);

                \Illuminate\Support\Facades\Log::warning('Provider connect failed', [
                    'provider' => $form['key'],
                    'base_url' => $form['base_url'],
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $this->connectErrors[$index] = __('Failed to connect: :message', [
                    'message' => $e->getMessage(),
                ]);

                \Illuminate\Support\Facades\Log::warning('Provider connect failed', [
                    'provider' => $form['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remove succeeded forms (iterate in reverse to preserve indices)
        foreach (array_reverse($succeeded) as $index) {
            $key = $this->connectForms[$index]['key'];
            $this->selectedTemplates = array_values(array_diff($this->selectedTemplates, [$key]));
            array_splice($this->connectForms, $index, 1);
        }

        // Re-index connectErrors to match new form indices
        $newErrors = [];
        $errorIndex = 0;
        foreach ($this->connectErrors as $oldIndex => $error) {
            // Count how many succeeded forms had indices <= oldIndex
            $offset = count(array_filter($succeeded, fn (int $i): bool => $i < $oldIndex));
            $newErrors[$oldIndex - $offset] = $error;
        }
        $this->connectErrors = $newErrors;

        // All succeeded — exit wizard
        if (count($this->connectForms) === 0) {
            $this->cleanupAuthFlows();
            $this->wizardStep = null;
            $this->selectedTemplates = [];
            $this->connectForms = [];
            $this->deviceFlows = [];
            $this->connectErrors = [];
        }
    }

    /**
     * Cancel the wizard and return to management view.
     */
    public function cancelWizard(): void
    {
        $this->cleanupAuthFlows();
        $this->wizardStep = null;
        $this->selectedTemplates = [];
        $this->connectForms = [];
        $this->deviceFlows = [];
        $this->connectErrors = [];
        $this->expandedCatalogProvider = null;
    }

    // --- Provider CRUD ---

    public function openCreateProvider(): void
    {
        $this->resetProviderForm();
        $this->isEditingProvider = false;
        $this->showProviderForm = true;
    }

    /**
     * Apply a provider template, pre-filling form fields from config.
     */
    public function applyTemplate(string $templateKey): void
    {
        $this->selectedTemplate = $templateKey;

        if ($templateKey === '') {
            return;
        }

        $template = app(ModelCatalogService::class)->getProvider($templateKey);

        if ($template === null) {
            return;
        }

        $this->providerName = $templateKey;
        $this->providerDisplayName = $template['display_name'] ?? '';
        $this->providerBaseUrl = $template['base_url'] ?? '';
    }

    /**
     * Sync models for a provider from its live API, with template fallback.
     *
     * Replaces the old "Import Suggested" action with live discovery + upsert.
     */
    public function syncProviderModels(int $providerId): void
    {
        $provider = AiProvider::query()->find($providerId);

        if (! $provider) {
            return;
        }

        // Clear any prior error for this provider before retrying
        if ($this->syncErrorProviderId === $providerId) {
            $this->syncError = null;
            $this->syncErrorProviderId = null;
        }

        try {
            $discovery = app(ModelDiscoveryService::class);
            $result = $discovery->syncModels($provider);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->syncError = __('Could not connect to :url — is the server running?', [
                'url' => $provider->base_url,
            ]);
            $this->syncErrorProviderId = $providerId;

            \Illuminate\Support\Facades\Log::warning('Model sync failed', [
                'provider' => $provider->name,
                'base_url' => $provider->base_url,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (\Exception $e) {
            $this->syncError = __('Sync failed: :message', ['message' => $e->getMessage()]);
            $this->syncErrorProviderId = $providerId;

            \Illuminate\Support\Facades\Log::warning('Model sync failed', [
                'provider' => $provider->name,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($result['added'] > 0 && $result['updated'] > 0) {
            $this->syncMessage = __('Added :added, updated :updated models.', [
                'added' => $result['added'],
                'updated' => $result['updated'],
            ]);
        } elseif ($result['added'] > 0) {
            $this->syncMessage = __('Added :count new models.', ['count' => $result['added']]);
        } elseif ($result['updated'] > 0) {
            $this->syncMessage = __('Updated :count models.', ['count' => $result['updated']]);
        } else {
            $this->syncMessage = __('Models are up to date.');
        }
    }

    /**
     * Dismiss the persistent sync error.
     */
    public function clearSyncError(): void
    {
        $this->syncError = null;
        $this->syncErrorProviderId = null;
    }

    /**
     * Open the provider help modal.
     */
    public function openProviderHelp(string $providerKey, string $authType = 'api_key'): void
    {
        // Toggle: clicking the same provider's ? again closes it
        if ($this->helpProviderKey === $providerKey) {
            $this->helpProviderKey = null;
            $this->helpProviderAuthType = null;

            return;
        }

        $this->helpProviderKey = $providerKey;
        $this->helpProviderAuthType = $authType;
    }

    /**
     * Close the provider help panel.
     */
    public function closeProviderHelp(): void
    {
        $this->helpProviderKey = null;
        $this->helpProviderAuthType = null;
    }

    /**
     * Return structured help content for the currently open help modal.
     *
     * @return array{setup_steps: list<string>, troubleshooting_tips: list<string>, documentation_url: string|null, connection_error_advice: string}|null
     */
    public function activeProviderHelp(): ?array
    {
        if ($this->helpProviderKey === null) {
            return null;
        }

        $registry = app(ProviderHelpRegistry::class);
        $help = $registry->get($this->helpProviderKey, $this->helpProviderAuthType);

        return [
            'setup_steps'             => $help->setupSteps(),
            'troubleshooting_tips'    => $help->troubleshootingTips(),
            'documentation_url'       => $help->documentationUrl(),
            'connection_error_advice' => $help->connectionErrorAdvice(),
        ];
    }

    /**
     * Move a provider up one position in priority (lower number = higher priority).
     */
    public function movePriorityUp(int $providerId): void
    {
        $provider = AiProvider::query()->find($providerId);

        if (! $provider || $provider->priority <= 1) {
            return;
        }

        $above = AiProvider::query()
            ->where('company_id', $provider->company_id)
            ->where('priority', $provider->priority - 1)
            ->first();

        if ($above) {
            $provider->swapPriority($above);
        }
    }

    /**
     * Toggle default status for a model.
     */
    public function toggleDefaultModel(int $modelId): void
    {
        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        if ($model->is_default) {
            $model->unsetDefault();
        } else {
            $model->setAsDefault();
        }
    }

    public function openEditProvider(int $providerId): void
    {
        $provider = AiProvider::query()->find($providerId);

        if (! $provider) {
            return;
        }

        $this->resetProviderForm();
        $this->isEditingProvider = true;
        $this->editingProviderId = $providerId;
        $this->providerName = $provider->name;
        $this->providerDisplayName = $provider->display_name ?? '';
        $this->providerBaseUrl = $provider->base_url;
        $this->providerIsActive = $provider->is_active;
        $this->showProviderForm = true;
    }

    public function saveProvider(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return;
        }

        $rules = [
            'providerName' => ['required', 'string', 'max:255'],
            'providerDisplayName' => ['nullable', 'string', 'max:255'],
            'providerBaseUrl' => ['required', 'string', 'max:2048'],
            'providerIsActive' => ['boolean'],
        ];

        if ($this->isEditingProvider) {
            $rules['providerApiKey'] = ['nullable', 'string', 'max:2048'];
        } else {
            $rules['providerApiKey'] = ['required', 'string', 'max:2048'];
        }

        $this->validate($rules);

        $data = [
            'company_id' => $companyId,
            'name' => $this->providerName,
            'display_name' => $this->providerDisplayName ?: $this->providerName,
            'base_url' => $this->providerBaseUrl,
            'is_active' => $this->providerIsActive,
        ];

        if ($this->isEditingProvider && $this->editingProviderId) {
            $provider = AiProvider::query()->find($this->editingProviderId);

            if ($provider) {
                // Name is immutable after creation — omit from update data
                unset($data['name']);

                if ($this->providerApiKey !== '') {
                    $data['api_key'] = $this->providerApiKey;
                }

                $provider->update($data);
            }
        } else {
            $data['api_key'] = $this->providerApiKey;
            $data['created_by'] = auth()->user()->employee?->id;
            AiProvider::query()->create($data);
        }

        $this->showProviderForm = false;
        $this->resetProviderForm();
    }

    public function confirmDeleteProvider(int $providerId): void
    {
        $provider = AiProvider::query()->find($providerId);

        if (! $provider) {
            return;
        }

        $this->deletingProviderId = $providerId;
        $this->deletingProviderName = $provider->display_name ?? $provider->name;
        $this->showDeleteProvider = true;
    }

    public function deleteProvider(): void
    {
        if ($this->deletingProviderId === null) {
            return;
        }

        $provider = AiProvider::query()->find($this->deletingProviderId);

        if ($provider) {
            $provider->models()->delete();
            $provider->delete();
        }

        if ($this->expandedProviderId === $this->deletingProviderId) {
            $this->expandedProviderId = null;
        }

        $this->showDeleteProvider = false;
        $this->deletingProviderId = null;
        $this->deletingProviderName = '';
    }

    // --- Model CRUD ---

    public function openCreateModel(int $providerId): void
    {
        $this->resetModelForm();
        $this->isEditingModel = false;
        $this->modelProviderId = $providerId;
        $this->showModelForm = true;
    }

    public function openEditModel(int $modelId): void
    {
        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        $this->resetModelForm();
        $this->isEditingModel = true;
        $this->editingModelId = $modelId;
        $this->modelProviderId = $model->ai_provider_id;
        $this->modelModelName = $model->model_id;
        $this->modelIsActive = $model->is_active;
        $cost = $model->cost_override ?? [];
        $this->modelCostInput = $cost['input'] ?? '0.000000';
        $this->modelCostOutput = $cost['output'] ?? '0.000000';
        $this->modelCostCacheRead = $cost['cache_read'] ?? '0.000000';
        $this->modelCostCacheWrite = $cost['cache_write'] ?? '0.000000';
        $this->showModelForm = true;
    }

    public function saveModel(): void
    {
        if ($this->modelProviderId === null) {
            return;
        }

        $this->validate([
            'modelModelName' => ['required', 'string', 'max:255'],
            'modelIsActive' => ['boolean'],
            'modelCostInput' => ['nullable', 'numeric', 'min:0'],
            'modelCostOutput' => ['nullable', 'numeric', 'min:0'],
            'modelCostCacheRead' => ['nullable', 'numeric', 'min:0'],
            'modelCostCacheWrite' => ['nullable', 'numeric', 'min:0'],
        ]);

        $costOverride = [
            'input' => $this->modelCostInput ?: null,
            'output' => $this->modelCostOutput ?: null,
            'cache_read' => $this->modelCostCacheRead ?: null,
            'cache_write' => $this->modelCostCacheWrite ?: null,
        ];
        $hasAnyCost = array_filter($costOverride, fn ($v) => $v !== null && $v !== '') !== [];

        $data = [
            'ai_provider_id' => $this->modelProviderId,
            'model_id' => $this->modelModelName,
            'is_active' => $this->modelIsActive,
            'cost_override' => $hasAnyCost ? $costOverride : null,
        ];

        if ($this->isEditingModel && $this->editingModelId) {
            $model = AiProviderModel::query()->find($this->editingModelId);

            if ($model) {
                // Model ID is immutable after creation
                unset($data['model_id']);
                $model->update($data);
            }
        } else {
            AiProviderModel::query()->create($data);
        }

        $this->showModelForm = false;
        $this->resetModelForm();
    }

    public function confirmDeleteModel(int $modelId): void
    {
        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        $this->deletingModelId = $modelId;
        $this->deletingModelName = $model->model_id;
        $this->showDeleteModel = true;
    }

    public function deleteModel(): void
    {
        if ($this->deletingModelId === null) {
            return;
        }

        AiProviderModel::query()->where('id', $this->deletingModelId)->delete();

        $this->showDeleteModel = false;
        $this->deletingModelId = null;
        $this->deletingModelName = '';
    }

    public function with(): array
    {
        $companyId = $this->getCompanyId();
        $providers = collect();
        $expandedModels = collect();

        if ($companyId !== null) {
            $query = AiProvider::query()
                ->forCompany($companyId)
                ->withCount('models');

            if ($this->search !== '') {
                $search = $this->search;
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('display_name', 'like', '%'.$search.'%');
                });
            }

            $providers = $query->orderBy('priority')->orderBy('display_name')->get();

            if ($this->expandedProviderId !== null) {
                $expandedModels = AiProviderModel::query()
                    ->where('ai_provider_id', $this->expandedProviderId)
                    ->orderBy('model_id')
                    ->get();
            }
        }

        $catalogService = app(ModelCatalogService::class);
        $allProviders = $catalogService->getProviders();

        $templates = collect($allProviders)
            ->map(fn ($t, $key) => ['value' => $key, 'label' => $t['display_name'] ?? $key])
            ->values()
            ->all();

        $connectedNames = $providers->pluck('name')->all();

        $catalog = collect($allProviders)
            ->map(function ($tpl, $key) use ($connectedNames) {
                $models = is_array($tpl['models'] ?? null) ? $tpl['models'] : [];
                $allCosts = [];

                foreach ($models as $modelId => $m) {
                    $cost = $m['cost'] ?? [];
                    foreach (['input', 'output'] as $dim) {
                        $c = $cost[$dim] ?? null;
                        if ($c !== null && $c !== '') {
                            $allCosts[] = (float) $c;
                        }
                    }
                }

                $minCost = $allCosts !== [] ? min($allCosts) : null;
                $maxCost = $allCosts !== [] ? max($allCosts) : null;
                $costRange = null;
                if ($minCost !== null && $maxCost !== null) {
                    $costRange = $minCost === $maxCost ? $minCost : ['min' => $minCost, 'max' => $maxCost];
                }

                return [
                    'key' => $key,
                    'display_name' => $tpl['display_name'] ?? $key,
                    'description' => $tpl['description'] ?? '',
                    'base_url' => $tpl['base_url'] ?? '',
                    'api_key_url' => $tpl['api_key_url'] ?? null,
                    'auth_type' => $tpl['auth_type'] ?? 'api_key',
                    'category' => $tpl['category'] ?? ['specialized'],
                    'region' => $tpl['region'] ?? ['global'],
                    'model_count' => count($models),
                    'cost_range' => $costRange,
                    'models' => collect($models)->map(fn ($m, $id) => [
                        'model_id' => is_string($id) ? $id : ($m['id'] ?? ''),
                        'display_name' => $m['name'] ?? $m['id'] ?? $id,
                        'context_window' => $m['limit']['context'] ?? null,
                        'max_tokens' => $m['limit']['output'] ?? null,
                        'cost' => $m['cost'] ?? [],
                    ])->values()->all(),
                    'connected' => in_array($key, $connectedNames, true),
                ];
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $catalogCollection = collect($catalog);
        $categoryOptions = $catalogCollection->pluck('category')->flatten()->unique()->sort()->values()->all();
        $regionOptions = $catalogCollection->pluck('region')->flatten()->unique()->sort()->values()->all();

        return [
            'providers' => $providers,
            'expandedModels' => $expandedModels,
            'templateOptions' => $templates,
            'catalog' => $catalog,
            'categoryOptions' => $categoryOptions,
            'regionOptions' => $regionOptions,
        ];
    }

    /**
     * Format a cost value for display (2 decimal places).
     */
    public function formatCost(?string $cost): string
    {
        if ($cost === null || $cost === '') {
            return '—';
        }

        return '$'.number_format((float) $cost, 2);
    }

    /**
     * Format a token count for display (e.g. 200000 → "200K", 1048576 → "1M").
     */
    public function formatTokenCount(?int $count): string
    {
        if ($count === null) {
            return '—';
        }

        if ($count >= 1000000) {
            $value = $count / 1000000;

            return ($value == (int) $value ? (int) $value : number_format($value, 1)).'M';
        }

        if ($count >= 1000) {
            $value = $count / 1000;

            return ($value == (int) $value ? (int) $value : number_format($value, 1)).'K';
        }

        return (string) $count;
    }

    private function getCompanyId(): ?int
    {
        $user = auth()->user();

        return $user?->employee?->company_id ? (int) $user->employee->company_id : null;
    }

    /**
     * Clean up all cached auth flow data for this company.
     */
    private function cleanupAuthFlows(): void
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null || count($this->deviceFlows) === 0) {
            return;
        }

        $service = app(ProviderAuthFlowService::class);
        $service->cleanupFlows($companyId, array_keys($this->deviceFlows));
    }

    private function resetProviderForm(): void
    {
        $this->editingProviderId = null;
        $this->providerName = '';
        $this->providerDisplayName = '';
        $this->providerBaseUrl = '';
        $this->providerApiKey = '';
        $this->providerIsActive = true;
        $this->selectedTemplate = '';
        $this->resetValidation();
    }

    private function resetModelForm(): void
    {
        $this->editingModelId = null;
        $this->modelProviderId = null;
        $this->modelModelName = '';
        $this->modelIsActive = true;
        $this->modelCostInput = '0.000000';
        $this->modelCostOutput = '0.000000';
        $this->modelCostCacheRead = '0.000000';
        $this->modelCostCacheWrite = '0.000000';
        $this->resetValidation();
    }
}; ?>

<div>
    <x-slot name="title">{{ __('LLM Providers') }}</x-slot>

    @if($wizardStep === 'catalog')
        {{-- ========================================== --}}
        {{-- STEP 1: Provider Catalog                   --}}
        {{-- ========================================== --}}
        <div class="space-y-section-gap">
            <x-ui.page-header :title="__('Choose Providers')" :subtitle="__('Browse available LLM providers and select the ones you want to connect.')">
                <x-slot name="help">
                    <div class="space-y-3">
                        <p>{{ __('An LLM provider is a service that hosts AI models your Digital Workers use to think and respond. You need at least one provider connected before Digital Workers can function.') }}</p>

                        <div>
                            <p class="font-medium text-ink">{{ __('Which provider should I choose?') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('Already have a GitHub Copilot subscription? Start there — it includes models from OpenAI, Anthropic, Google, and xAI at no extra per-token cost.') }}</li>
                                <li>{{ __('Need the latest models with full control? OpenAI and Anthropic offer direct API access with pay-per-token pricing.') }}</li>
                                <li>{{ __('Want to keep data on-premise? Ollama runs models locally on your own hardware for free.') }}</li>
                                <li>{{ __('Not sure? Select multiple providers now — you can disable or remove any of them later.') }}</li>
                            </ul>
                        </div>

                        <div>
                            <p class="font-medium text-ink">{{ __('How to use this page') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('Tap a row to expand it and compare models, context windows, and pricing.') }}</li>
                                <li>{{ __('Check the box next to each provider you want, then click "Connect Providers".') }}</li>
                                <li>{{ __('On the next step you\'ll enter your API key (or log in for GitHub Copilot).') }}</li>
                            </ul>
                        </div>
                    </div>
                </x-slot>
                <x-slot name="actions">
                    @if($providers->isNotEmpty())
                        <x-ui.button variant="ghost" wire:click="cancelWizard">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button
                        variant="primary"
                        wire:click="proceedToConnect"
                        :disabled="count($selectedTemplates) === 0"
                    >
                        <x-icon name="heroicon-m-sparkles" class="w-4 h-4" />
                        {{ count($selectedTemplates) === 0 ? __('Connect Providers') : __('Connect Providers (:count)', ['count' => count($selectedTemplates)]) }}
                    </x-ui.button>
                </x-slot>
            </x-ui.page-header>

            <x-ui.card x-data="{
                catalogSearch: '',
                selectedCategories: [],
                selectedRegions: [],
                categoryOpen: false,
                regionOpen: false,
                toggleCategory(cat) {
                    const idx = this.selectedCategories.indexOf(cat);
                    idx === -1 ? this.selectedCategories.push(cat) : this.selectedCategories.splice(idx, 1);
                },
                toggleRegion(reg) {
                    const idx = this.selectedRegions.indexOf(reg);
                    idx === -1 ? this.selectedRegions.push(reg) : this.selectedRegions.splice(idx, 1);
                },
                matchesFilters(categories, regions) {
                    const catMatch = this.selectedCategories.length === 0 || categories.some(c => this.selectedCategories.includes(c));
                    const regMatch = this.selectedRegions.length === 0 || regions.some(r => this.selectedRegions.includes(r));
                    return catMatch && regMatch;
                },
                matchesSearch(text) {
                    return this.catalogSearch === '' || text.includes(this.catalogSearch.toLowerCase());
                },
                categoryLabels: {
                    'cloud-provider': '{{ __('Cloud Provider') }}',
                    'developer-tool': '{{ __('Developer Tool') }}',
                    'gateway': '{{ __('Gateway') }}',
                    'inference-platform': '{{ __('Inference Platform') }}',
                    'leading-lab': '{{ __('Leading Lab') }}',
                    'local': '{{ __('Local') }}',
                    'specialized': '{{ __('Specialized') }}',
                },
                regionLabels: {
                    'china': '{{ __('China') }}',
                    'europe': '{{ __('Europe') }}',
                    'global': '{{ __('Global') }}',
                },
            }">
                <div class="mb-2 flex flex-col sm:flex-row gap-2">
                    <div class="flex-1">
                        <x-ui.search-input
                            x-model.debounce.200ms="catalogSearch"
                            placeholder="{{ __('Search providers...') }}"
                        />
                    </div>

                    {{-- Category filter --}}
                    <div class="relative" @click.outside="categoryOpen = false">
                        <button
                            type="button"
                            @click="categoryOpen = !categoryOpen"
                            class="inline-flex items-center gap-1.5 px-3 py-input-y text-sm border border-border-input rounded-2xl bg-surface-card text-ink hover:bg-surface-subtle/50 transition-colors whitespace-nowrap"
                        >
                            <x-icon name="heroicon-o-funnel" class="w-4 h-4 text-muted" />
                            {{ __('Category') }}
                            <template x-if="selectedCategories.length > 0">
                                <span class="inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold rounded-full bg-accent text-on-accent" x-text="selectedCategories.length"></span>
                            </template>
                            <x-icon name="heroicon-m-chevron-down" class="w-3.5 h-3.5 text-muted" />
                        </button>
                        <div
                            x-show="categoryOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-20 mt-1 w-56 rounded-xl border border-border-default bg-surface-card shadow-lg py-1"
                        >
                            @foreach($categoryOptions as $cat)
                                <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink hover:bg-surface-subtle/50 cursor-pointer">
                                    <input type="checkbox" :checked="selectedCategories.includes('{{ $cat }}')" @click="toggleCategory('{{ $cat }}')" class="w-4 h-4 rounded border border-border-input accent-accent" />
                                    <span x-text="categoryLabels['{{ $cat }}'] || '{{ $cat }}'"></span>
                                </label>
                            @endforeach
                            <template x-if="selectedCategories.length > 0">
                                <div class="border-t border-border-default mt-1 pt-1 px-3 pb-1">
                                    <button type="button" @click="selectedCategories = []" class="text-xs text-accent hover:text-accent/80">{{ __('Clear') }}</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Region filter --}}
                    <div class="relative" @click.outside="regionOpen = false">
                        <button
                            type="button"
                            @click="regionOpen = !regionOpen"
                            class="inline-flex items-center gap-1.5 px-3 py-input-y text-sm border border-border-input rounded-2xl bg-surface-card text-ink hover:bg-surface-subtle/50 transition-colors whitespace-nowrap"
                        >
                            <x-icon name="heroicon-o-globe-alt" class="w-4 h-4 text-muted" />
                            {{ __('Region') }}
                            <template x-if="selectedRegions.length > 0">
                                <span class="inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold rounded-full bg-accent text-on-accent" x-text="selectedRegions.length"></span>
                            </template>
                            <x-icon name="heroicon-m-chevron-down" class="w-3.5 h-3.5 text-muted" />
                        </button>
                        <div
                            x-show="regionOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-20 mt-1 w-44 rounded-xl border border-border-default bg-surface-card shadow-lg py-1"
                        >
                            @foreach($regionOptions as $reg)
                                <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink hover:bg-surface-subtle/50 cursor-pointer">
                                    <input type="checkbox" :checked="selectedRegions.includes('{{ $reg }}')" @click="toggleRegion('{{ $reg }}')" class="w-4 h-4 rounded border border-border-input accent-accent" />
                                    <span x-text="regionLabels['{{ $reg }}'] || '{{ $reg }}'"></span>
                                </label>
                            @endforeach
                            <template x-if="selectedRegions.length > 0">
                                <div class="border-t border-border-default mt-1 pt-1 px-3 pb-1">
                                    <button type="button" @click="selectedRegions = []" class="text-xs text-accent hover:text-accent/80">{{ __('Clear') }}</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y w-8"></th>
                                <th class="px-table-cell-x py-table-header-y w-8"></th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Provider') }}</th>
                                <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                                <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Models') }}</th>
                                <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost $/1M') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach($catalog as $entry)
                                <tr
                                    wire:key="catalog-{{ $entry['key'] }}"
                                    wire:click="toggleCatalogProvider('{{ $entry['key'] }}')"
                                    class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                                    x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                                >
                                    <td class="px-table-cell-x py-table-cell-y" wire:click.stop>
                                        @if($entry['connected'])
                                            <span class="w-4 h-4 block"></span>
                                        @else
                                            <input
                                                type="checkbox"
                                                class="w-4 h-4 rounded border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                                @checked(in_array($entry['key'], $selectedTemplates, true))
                                                wire:click="toggleSelectTemplate('{{ $entry['key'] }}')"
                                            />
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-icon
                                            :name="$expandedCatalogProvider === $entry['key'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                            class="w-4 h-4 text-muted"
                                        />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                        <div class="flex items-center gap-1">
                                            <span>{{ $entry['display_name'] }}</span>
                                            <x-ui.help
                                                wire:click.stop="openProviderHelp('{{ $entry['key'] }}', '{{ $entry['auth_type'] ?? 'api_key' }}')"
                                                title="{{ __('Setup & troubleshooting') }}"
                                            />
                                        </div>
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y text-sm text-muted">{{ $entry['description'] }}</td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $entry['model_count'] ?: '—' }}</td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">
                                        @if(is_array($entry['cost_range'] ?? null))
                                            {{ $this->formatCost((string) $entry['cost_range']['min']) }}–{{ $this->formatCost((string) $entry['cost_range']['max']) }}
                                        @elseif(($entry['cost_range'] ?? null) !== null)
                                            {{ $this->formatCost((string) $entry['cost_range']) }}
                                        @elseif($entry['model_count'] > 0)
                                            {{ __('Subscription') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        @if($entry['connected'])
                                            <x-ui.badge variant="success">{{ __('Connected') }}</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>

                                {{-- Provider help panel (inline, like page-header help) --}}
                                @if($helpProviderKey === $entry['key'])
                                    @php $help = $this->activeProviderHelp(); @endphp
                                    <tr
                                        wire:key="catalog-{{ $entry['key'] }}-help"
                                        x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                                    >
                                        <td colspan="7" class="px-4 pb-3 pt-0">
                                            <div
                                                x-data
                                                x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })"
                                                class="mt-3 rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted shadow-lg shadow-black/[0.02]"
                                            >
                                                <div class="flex items-start justify-between gap-3 mb-3">
                                                    <div class="flex items-center gap-1.5">
                                                        <x-icon name="heroicon-o-question-mark-circle" class="w-4 h-4 text-accent shrink-0" />
                                                        <span class="text-xs font-semibold uppercase tracking-wider text-accent">{{ __('Setup & Troubleshooting') }}</span>
                                                        <span class="text-xs text-muted">— {{ $entry['display_name'] }}</span>
                                                    </div>
                                                    <button
                                                        wire:click.stop="closeProviderHelp"
                                                        class="text-muted hover:text-ink p-0.5 rounded hover:bg-surface-subtle shrink-0"
                                                        title="{{ __('Close') }}"
                                                    >
                                                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                                    </button>
                                                </div>

                                                <div class="grid sm:grid-cols-2 gap-4">
                                                    @if(!empty($help['setup_steps']))
                                                        <div>
                                                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('How to set up') }}</p>
                                                            <ol class="space-y-1 list-decimal list-outside pl-4">
                                                                @foreach($help['setup_steps'] as $step)
                                                                    <li class="leading-relaxed">{{ $step }}</li>
                                                                @endforeach
                                                            </ol>
                                                        </div>
                                                    @endif

                                                    @if(!empty($help['troubleshooting_tips']))
                                                        <div>
                                                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('Troubleshooting') }}</p>
                                                            <ul class="space-y-1 list-disc list-outside pl-4">
                                                                @foreach($help['troubleshooting_tips'] as $tip)
                                                                    <li class="leading-relaxed">{{ $tip }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                </div>

                                                @if($help['documentation_url'])
                                                    <div class="mt-3 pt-3 border-t border-border-default">
                                                        <a
                                                            href="{{ $help['documentation_url'] }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="inline-flex items-center gap-1 text-xs text-accent hover:underline"
                                                        >
                                                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                                            {{ __('Official documentation') }}
                                                        </a>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                {{-- Expanded model catalog --}}
                                @if($expandedCatalogProvider === $entry['key'] && count($entry['models']) > 0)
                                    <tr wire:key="catalog-{{ $entry['key'] }}-models"
                                        x-show="matchesSearch('{{ mb_strtolower($entry['key'].' '.$entry['display_name'].' '.($entry['description'] ?? '')) }}') && matchesFilters({{ json_encode($entry['category']) }}, {{ json_encode($entry['region']) }})"
                                    >
                                        <td colspan="7" class="p-0">
                                            <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2 block">{{ __('Model Catalog') }}</span>
                                                <table class="min-w-full divide-y divide-border-default text-sm">
                                                    <thead class="bg-surface-subtle/80">
                                                        <tr>
                                                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model') }}</th>
                                                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Context') }}</th>
                                                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Max Output') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Input $/1M') }}</th>
                                                            <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Output $/1M') }}</th>
                                                            </tr>
                                                            </thead>
                                                            <tbody class="bg-surface-card divide-y divide-border-default">
                                                            @foreach($entry['models'] as $catModel)
                                                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">{{ $catModel['display_name'] }}</td>
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['context_window'] ?? null) }}</td>
                                                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatTokenCount($catModel['max_tokens'] ?? null) }}</td>
                                                                @php $cost = $catModel['cost'] ?? []; @endphp
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['input'] ?? null) }}</td>
                                                                <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['output'] ?? null) }}</td>
                                                                </tr>
                                                                @endforeach
                                                                </tbody>
                                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                @elseif($expandedCatalogProvider === $entry['key'] && count($entry['models']) === 0)
                                    <tr wire:key="catalog-{{ $entry['key'] }}-empty">
                                        <td colspan="7" class="p-0">
                                            <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                                <p class="text-sm text-muted py-2 text-center">{{ __('Models are discovered dynamically after connecting. Add models manually from the management view.') }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

    @elseif($wizardStep === 'connect')
        {{-- ========================================== --}}
        {{-- STEP 2: Connect Providers                  --}}
        {{-- ========================================== --}}
        <div class="space-y-section-gap">
            @php
                $hasIncompleteDeviceFlow = false;
                foreach ($connectForms as $i => $f) {
                    if (($f['auth_type'] ?? 'api_key') === 'device_flow') {
                        $flowStatus = $deviceFlows[$i]['status'] ?? 'idle';
                        if ($flowStatus !== 'success') {
                            $hasIncompleteDeviceFlow = true;
                        }
                    }
                }
            @endphp
            <x-ui.page-header :title="__('Connect Providers')" :subtitle="__('Enter your API key for each selected provider.')">
                <x-slot name="actions">
                    <x-ui.button variant="ghost" wire:click="backToCatalog">
                        <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                        {{ __('Back') }}
                    </x-ui.button>
                    <x-ui.button variant="primary" wire:click="connectAll" :disabled="$hasIncompleteDeviceFlow">
                        <x-icon name="heroicon-m-bolt" class="w-4 h-4" />
                        {{ __('Connect All & Import Models') }}
                    </x-ui.button>
                </x-slot>
            </x-ui.page-header>

            <div class="space-y-4">
                @foreach($connectForms as $index => $form)
                    @php $authType = $form['auth_type'] ?? 'api_key'; @endphp
                    <x-ui.card wire:key="connect-{{ $form['key'] }}">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h3 class="text-base font-medium tracking-tight text-ink">{{ $form['display_name'] }}</h3>
                                @if($form['key'] === 'copilot-proxy')
                                    <p class="text-xs text-muted mt-0.5">{{ __('Requires the Copilot Proxy extension running in VS Code — start the extension, then connect.') }}</p>
                                @elseif($authType === 'local')
                                    <p class="text-xs text-muted mt-0.5">{{ __('Local server — API key is optional') }}</p>
                                @elseif($authType === 'oauth')
                                    <p class="text-xs text-muted mt-0.5">{{ __('OAuth provider — paste API key if available, or configure after connecting') }}</p>
                                @elseif($authType === 'subscription')
                                    <p class="text-xs text-muted mt-0.5">{{ __('Subscription service — paste access token or API key') }}</p>
                                @elseif($authType === 'custom')
                                    <p class="text-xs text-muted mt-0.5">{{ __('Requires additional configuration after connecting') }}</p>
                                @elseif($authType === 'device_flow')
                                    <p class="text-xs text-muted mt-0.5">{{ __('Requires GitHub device login — an active GitHub Copilot subscription is needed') }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if(!empty($form['api_key_url']))
                                    <a
                                        href="{{ $form['api_key_url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-sm text-accent hover:underline inline-flex items-center gap-1"
                                    >
                                        {{ __('Get API Key') }}
                                        <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                    </a>
                                @endif
                                    <button
                                        type="button"
                                        wire:click="removeConnectForm({{ $index }})"
                                        class="p-1 rounded-md text-muted hover:text-ink hover:bg-surface-subtle transition-colors focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                        aria-label="{{ __('Remove :provider', ['provider' => $form['display_name']]) }}"
                                    >
                                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                                    </button>
                            </div>
                        </div>

                        {{-- Per-card connection error --}}
                        @if(isset($connectErrors[$index]))
                            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 mb-3">
                                <p class="text-sm text-red-700 dark:text-red-400">{{ $connectErrors[$index] }}</p>
                            </div>
                        @endif

                        @if($authType === 'device_flow')
                            {{-- ── Device Flow UI (GitHub Copilot) ── --}}
                            @php $flow = $deviceFlows[$index] ?? ['status' => 'idle', 'user_code' => null, 'verification_uri' => null, 'error' => null]; @endphp

                            @if($flow['status'] === 'pending')
                                <div wire:poll.5s="pollDeviceFlow({{ $index }})">
                                    <div
                                        class="bg-surface-subtle rounded-lg p-4 space-y-3"
                                        x-data="{ copied: false }"
                                    >
                                        <div class="space-y-2">
                                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 1 — Copy your authorization code') }}</span>
                                            <div class="flex items-center gap-3">
                                                <p class="text-2xl font-mono font-bold text-ink tracking-[0.3em] select-all">{{ $flow['user_code'] }}</p>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-accent bg-surface-card border border-border-default rounded-md hover:bg-surface-subtle transition-colors focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                                    x-on:click="navigator.clipboard.writeText('{{ $flow['user_code'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                    x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"
                                                    :aria-label="copied ? '{{ __('Code copied to clipboard') }}' : '{{ __('Copy authorization code') }}'"
                                                >
                                                </button>
                                            </div>
                                        </div>

                                        <div class="space-y-1.5">
                                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 2 — Paste it on GitHub') }}</span>
                                            <p class="text-xs text-muted">{{ __('Open the link below, paste the code, and approve access for BLB.') }}</p>
                                            <a
                                                href="{{ $flow['verification_uri'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-sm text-accent hover:underline inline-flex items-center gap-1"
                                            >
                                                {{ $flow['verification_uri'] }}
                                                <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                            </a>
                                        </div>

                                        <div class="flex items-center gap-2 pt-1 border-t border-border-default">
                                            <div class="animate-spin h-3.5 w-3.5 border-2 border-accent border-t-transparent rounded-full"></div>
                                            <span class="text-xs text-muted">{{ __('Listening for approval — this will update automatically once you authorize on GitHub.') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @elseif($flow['status'] === 'idle')
                                <div class="space-y-3">
                                    <p class="text-xs text-muted">{{ __('Connecting to GitHub Copilot requires that you authorize this application on GitHub.') }}</p>
                                    <x-ui.button variant="primary" wire:click="startDeviceFlow({{ $index }})">
                                        <x-icon name="github" class="w-4 h-4" />
                                        {{ __('Start GitHub Login') }}
                                    </x-ui.button>
                                </div>
                            @elseif($flow['status'] === 'success')
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2">
                                        <x-icon name="heroicon-o-check-circle" class="w-5 h-5 text-status-success" />
                                        <span class="text-sm font-medium text-ink">{{ __('GitHub Copilot authorized successfully') }}</span>
                                    </div>
                                    <p class="text-xs text-muted">{{ __('Click "Connect All & Import Models" above to finish setup.') }}</p>
                                </div>
                            @else
                                {{-- error / expired / denied --}}
                                <div class="space-y-3">
                                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                                        <p class="text-sm text-red-700 dark:text-red-400">{{ $flow['error'] ?? __('Authorization failed') }}</p>
                                    </div>
                                    <x-ui.button variant="ghost" wire:click="startDeviceFlow({{ $index }})">
                                        <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                                        {{ __('Try Again') }}
                                    </x-ui.button>
                                </div>
                            @endif
                        @elseif($form['key'] === 'cloudflare-ai-gateway')
                            {{-- ── Cloudflare AI Gateway (Account ID + Gateway ID + API Key) ── --}}
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <x-ui.input
                                        wire:model="connectForms.{{ $index }}.cloudflare_account_id"
                                        label="{{ __('Account ID') }}"
                                        required
                                        placeholder="{{ __('Cloudflare Account ID') }}"
                                        :error="$errors->first('connectForms.'.$index.'.cloudflare_account_id')"
                                    />
                                    <x-ui.input
                                        wire:model="connectForms.{{ $index }}.cloudflare_gateway_id"
                                        label="{{ __('Gateway ID') }}"
                                        required
                                        placeholder="{{ __('AI Gateway name') }}"
                                        :error="$errors->first('connectForms.'.$index.'.cloudflare_gateway_id')"
                                    />
                                </div>
                                <x-ui.input
                                    wire:model="connectForms.{{ $index }}.api_key"
                                    type="password"
                                    label="{{ __('API Key') }}"
                                    required
                                    placeholder="{{ __('Cloudflare API token') }}"
                                    :error="$errors->first('connectForms.'.$index.'.api_key')"
                                />
                                <p class="text-xs text-muted">{{ __('The base URL will be computed as: gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openai') }}</p>
                            </div>
                        @else
                            {{-- ── Standard API Key / URL form ── --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <x-ui.input
                                    wire:model="connectForms.{{ $index }}.base_url"
                                    label="{{ __('Base URL') }}"
                                    required
                                    :error="$errors->first('connectForms.'.$index.'.base_url')"
                                />

                                <x-ui.input
                                    wire:model="connectForms.{{ $index }}.api_key"
                                    type="password"
                                    :label="in_array($authType, ['local', 'oauth', 'subscription']) ? __('API Key (optional)') : __('API Key')"
                                    :required="in_array($authType, ['api_key', 'custom'])"
                                    :placeholder="match($authType) {
                                        'local' => __('Leave empty for local servers'),
                                        'oauth' => __('Paste API key if available'),
                                        'subscription' => __('Paste access token'),
                                        default => __('Paste your API key'),
                                    }"
                                    :error="$errors->first('connectForms.'.$index.'.api_key')"
                                />
                            </div>
                            @if($form['key'] === 'copilot-proxy')
                                <div class="bg-surface-subtle rounded-lg p-3 mt-3">
                                    <p class="text-xs font-medium text-ink mb-1">{{ __('Setup instructions') }}</p>
                                    <ol class="text-xs text-muted space-y-0.5 list-decimal list-inside">
                                        <li>{{ __('Install the "Copilot Proxy" extension in VS Code.') }}</li>
                                        <li>{{ __('Open VS Code and ensure you are signed in to GitHub Copilot.') }}</li>
                                        <li>{{ __('Start the proxy via the extension (it listens on localhost:1337 by default).') }}</li>
                                        <li>{{ __('Click "Connect All" above — BLB will discover available models from the proxy.') }}</li>
                                    </ol>
                                </div>
                            @endif
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        </div>

    @else
        {{-- ========================================== --}}
        {{-- MANAGEMENT VIEW (existing providers)       --}}
        {{-- ========================================== --}}
        <div class="space-y-section-gap">
            <x-ui.page-header :title="__('LLM Providers')" :subtitle="__('Manage AI providers, their models, and priority order. Higher-priority providers are tried first.')">
                <x-slot name="help">
                    <div class="space-y-3">
                        <p>{{ __('This page shows the LLM providers and models your organization has connected. Digital Workers use these models to think, reason, and respond — at least one active provider with one active model is required.') }}</p>

                        <div>
                            <p class="font-medium text-ink">{{ __('Managing providers') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('Click a provider row to expand it and see its models.') }}</li>
                                <li>{{ __('"Browse Providers" opens the catalog to connect additional providers.') }}</li>
                                <li>{{ __('"Manual Add" lets you enter a custom provider not in the catalog (e.g. a private deployment).') }}</li>
                                <li>{{ __('Use "Update Models" to fetch the latest model list from the provider\'s API.') }}</li>
                            </ul>
                        </div>

                        <div>
                            <p class="font-medium text-ink">{{ __('Priority') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('The Priority column shows the order in which providers are tried when a Digital Worker needs a model.') }}</li>
                                <li>{{ __('Lower numbers mean higher priority — provider #1 is tried first.') }}</li>
                                <li>{{ __('Click the ↑ arrow to move a provider up one position.') }}</li>
                                <li>{{ __('If the top-priority provider fails or is unavailable, the system automatically falls back to the next one.') }}</li>
                            </ul>
                        </div>

                        <div>
                            <p class="font-medium text-ink">{{ __('Default model') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('Each provider can have one default model, marked with a ★ star icon.') }}</li>
                                <li>{{ __('The default model is used as the fallback when a Digital Worker does not specify a particular model.') }}</li>
                                <li>{{ __('Click the star icon next to any model to set or unset it as the default.') }}</li>
                            </ul>
                        </div>

                        <div>
                            <p class="font-medium text-ink">{{ __('Costs & billing') }}</p>
                            <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                                <li>{{ __('API providers (OpenAI, Anthropic, etc.) bill per token used — costs are shown per 1M tokens.') }}</li>
                                <li>{{ __('Subscription providers (GitHub Copilot) are included in your subscription at no extra per-token cost.') }}</li>
                                <li>{{ __('Local providers (Ollama, vLLM) run on your own hardware and have no API fees.') }}</li>
                            </ul>
                        </div>

                        <p>{!! __('Once providers and models are set up here, assign them to Digital Workers from the :link.', ['link' => '<a href="' . route('admin.ai.playground') . '" class="text-accent hover:underline">' . e(__('AI Playground')) . '</a>']) !!}</p>
                    </div>
                </x-slot>
                <x-slot name="actions">
                    <x-ui.button variant="ghost" wire:click="openCreateProvider">
                        <x-icon name="heroicon-m-plus" class="w-4 h-4" />
                        {{ __('Manual Add') }}
                    </x-ui.button>
                    <x-ui.button variant="primary" wire:click="openCatalog">
                        <x-icon name="heroicon-m-rectangle-stack" class="w-4 h-4" />
                        {{ __('Browse Providers') }}
                    </x-ui.button>
                </x-slot>
            </x-ui.page-header>

            <x-ui.card>
                <div class="mb-2">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by name...') }}"
                    />
                </div>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y w-8"></th>
                                <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Display Name') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</th>
                                <th class="hidden md:table-cell px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Base URL') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Models') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse($providers as $provider)
                                <tr
                                    wire:key="provider-{{ $provider->id }}"
                                    wire:click="toggleProvider({{ $provider->id }})"
                                    class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                                >
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-icon
                                            :name="$expandedProviderId === $provider->id ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                            class="w-4 h-4 text-muted"
                                        />
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                        <div class="flex items-center gap-1">
                                            <span>{{ $provider->name }}</span>
                                            <x-ui.help
                                                wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type ?? 'api_key' }}')"
                                                title="{{ __('Setup & troubleshooting') }}"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $provider->display_name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-left" @click.stop>
                                        <div class="inline-flex items-center gap-1">
                                            <span class="text-xs text-muted tabular-nums">{{ $provider->priority }}</span>
                                            @if($provider->priority > 1)
                                                <button
                                                    wire:click="movePriorityUp({{ $provider->id }})"
                                                    class="text-muted hover:text-ink hover:bg-surface-subtle p-0.5 rounded transition-colors"
                                                    title="{{ __('Move up') }}"
                                                >
                                                    <x-icon name="heroicon-m-arrow-up" class="w-3.5 h-3.5" />
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono text-xs truncate max-w-[200px]">{{ $provider->base_url }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $provider->models_count }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            @if($provider->is_active)
                                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                wire:click.stop="openEditProvider({{ $provider->id }})"
                                                class="text-accent hover:bg-surface-subtle p-1 rounded"
                                                title="{{ __('Edit') }}"
                                            >
                                                <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                            </button>
                                            <button
                                                wire:click.stop="confirmDeleteProvider({{ $provider->id }})"
                                                class="text-accent hover:bg-surface-subtle p-1 rounded"
                                                title="{{ __('Delete') }}"
                                            >
                                                <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                {{-- Provider help panel (inline, like page-header help) --}}
                                @if($helpProviderKey === $provider->name)
                                    @php $help = $this->activeProviderHelp(); @endphp
                                    <tr wire:key="provider-{{ $provider->id }}-help">
                                        <td colspan="8" class="px-4 pb-3 pt-0">
                                            <div
                                                x-data
                                                x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })"
                                                class="mt-3 rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted shadow-lg shadow-black/[0.02]"
                                            >
                                                <div class="flex items-start justify-between gap-3 mb-3">
                                                    <div class="flex items-center gap-1.5">
                                                        <x-icon name="heroicon-o-question-mark-circle" class="w-4 h-4 text-accent shrink-0" />
                                                        <span class="text-xs font-semibold uppercase tracking-wider text-accent">{{ __('Setup & Troubleshooting') }}</span>
                                                        <span class="text-xs text-muted">— {{ $provider->display_name }}</span>
                                                    </div>
                                                    <button
                                                        wire:click.stop="closeProviderHelp"
                                                        class="text-muted hover:text-ink p-0.5 rounded hover:bg-surface-subtle shrink-0"
                                                        title="{{ __('Close') }}"
                                                    >
                                                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                                    </button>
                                                </div>

                                                <div class="grid sm:grid-cols-2 gap-4">
                                                    @if(!empty($help['setup_steps']))
                                                        <div>
                                                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('How to set up') }}</p>
                                                            <ol class="space-y-1 list-decimal list-outside pl-4">
                                                                @foreach($help['setup_steps'] as $step)
                                                                    <li class="leading-relaxed">{{ $step }}</li>
                                                                @endforeach
                                                            </ol>
                                                        </div>
                                                    @endif

                                                    @if(!empty($help['troubleshooting_tips']))
                                                        <div>
                                                            <p class="text-xs font-semibold uppercase tracking-wider text-muted mb-1.5">{{ __('Troubleshooting') }}</p>
                                                            <ul class="space-y-1 list-disc list-outside pl-4">
                                                                @foreach($help['troubleshooting_tips'] as $tip)
                                                                    <li class="leading-relaxed">{{ $tip }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                </div>

                                                @if($help['documentation_url'])
                                                    <div class="mt-3 pt-3 border-t border-border-default">
                                                        <a
                                                            href="{{ $help['documentation_url'] }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="inline-flex items-center gap-1 text-xs text-accent hover:underline"
                                                        >
                                                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                                            {{ __('Official documentation') }}
                                                        </a>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                {{-- Expanded models sub-table --}}
                                @if($expandedProviderId === $provider->id)
                                    <tr wire:key="provider-{{ $provider->id }}-models">
                                        <td colspan="8" class="p-0">
                                            <div class="bg-surface-subtle/30 border-t border-border-default px-8 py-3">
                                               <div class="flex items-center justify-between mb-2">
                                                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Models') }}</span>
                                                    <div class="flex items-center gap-1">
                                                        <x-ui.button variant="ghost" size="sm" wire:click.stop="syncProviderModels({{ $provider->id }})">
                                                            <x-icon name="heroicon-o-arrow-path" class="w-3.5 h-3.5" />
                                                            {{ __('Update Models') }}
                                                        </x-ui.button>
                                                        <x-ui.button variant="ghost" size="sm" wire:click.stop="openCreateModel({{ $provider->id }})">
                                                            <x-icon name="heroicon-o-plus" class="w-3.5 h-3.5" />
                                                            {{ __('Add Model') }}
                                                        </x-ui.button>
                                                    </div>
                                                </div>

                                               @if($syncMessage)
                                                    <div
                                                        class="mb-2 px-3 py-1.5 bg-surface-subtle rounded text-sm text-muted"
                                                        x-data="{ show: true }"
                                                        x-init="setTimeout(() => { show = false; $wire.set('syncMessage', null) }, 4000)"
                                                        x-show="show"
                                                        x-transition.opacity
                                                    >
                                                        {{ $syncMessage }}
                                                    </div>
                                                @endif

                                                @if($syncError && $syncErrorProviderId === $provider->id)
                                                    @php $helpAdvice = app(\App\Base\AI\Providers\Help\ProviderHelpRegistry::class)->get($provider->name, $provider->auth_type ?? 'api_key')->connectionErrorAdvice(); @endphp
                                                    <div class="mb-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
                                                        <div class="flex items-start justify-between gap-2">
                                                            <div class="flex items-start gap-2 min-w-0">
                                                                <x-icon name="heroicon-o-exclamation-circle" class="w-4 h-4 text-red-500 dark:text-red-400 mt-0.5 shrink-0" />
                                                                <div class="min-w-0">
                                                                    <p class="text-sm text-red-700 dark:text-red-300 font-medium">{{ $syncError }}</p>
                                                                    <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ $helpAdvice }}</p>
                                                                </div>
                                                            </div>
                                                            <div class="flex items-center gap-1 shrink-0">
                                                                <button
                                                                    wire:click.stop="openProviderHelp('{{ $provider->name }}', '{{ $provider->auth_type ?? 'api_key' }}')"
                                                                    class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 underline whitespace-nowrap"
                                                                >
                                                                    {{ __('Get help') }}
                                                                </button>
                                                                <button
                                                                    wire:click.stop="clearSyncError"
                                                                    class="p-0.5 rounded text-red-400 hover:text-red-600 dark:hover:text-red-200 hover:bg-red-100 dark:hover:bg-red-800/50"
                                                                    title="{{ __('Dismiss') }}"
                                                                >
                                                                    <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if($expandedModels->count() > 0)
                                                    <table class="min-w-full divide-y divide-border-default text-sm">
                                                         <thead class="bg-surface-subtle/80">
                                                            <tr>
                                                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model ID') }}</th>
                                                                <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Input $/1M') }}</th>
                                                                <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cost Override Output $/1M') }}</th>
                                                                <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Read $/1M') }}</th>
                                                                <th class="hidden lg:table-cell px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cache Write $/1M') }}</th>
                                                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="bg-surface-card divide-y divide-border-default">
                                                            @foreach($expandedModels as $model)
                                                                <tr wire:key="model-{{ $model->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink font-mono">{{ $model->model_id }}</td>
                                                                    @php $cost = $model->cost_override ?? []; @endphp
                                                                    <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['input'] ?? null) }}</td>
                                                                    <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['output'] ?? null) }}</td>
                                                                    <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['cache_read'] ?? null) }}</td>
                                                                    <td class="hidden lg:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums text-right">{{ $this->formatCost($cost['cache_write'] ?? null) }}</td>
                                                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                                        <div class="flex items-center gap-1.5">
                                                                            @if($model->is_active)
                                                                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                                                            @else
                                                                                <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                                                            @endif
                                                                            @if($model->is_default)
                                                                                <x-ui.badge variant="accent">{{ __('Default') }}</x-ui.badge>
                                                                            @endif
                                                                        </div>
                                                                    </td>
                                                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                                                        <div class="flex items-center justify-end gap-1">
                                                                            <button
                                                                                wire:click="toggleDefaultModel({{ $model->id }})"
                                                                                class="{{ $model->is_default ? 'text-accent' : 'text-muted hover:text-accent' }} hover:bg-surface-subtle p-1 rounded"
                                                                                title="{{ $model->is_default ? __('Unset default') : __('Set as default') }}"
                                                                            >
                                                                                <x-icon :name="$model->is_default ? 'heroicon-s-star' : 'heroicon-o-star'" class="w-4 h-4" />
                                                                            </button>
                                                                            <button wire:click="openEditModel({{ $model->id }})" class="text-accent hover:bg-surface-subtle p-1 rounded" title="{{ __('Edit') }}">
                                                                                <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                                                            </button>
                                                                            <button wire:click="confirmDeleteModel({{ $model->id }})" class="text-accent hover:bg-surface-subtle p-1 rounded" title="{{ __('Delete') }}">
                                                                                <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @else
                                                    <p class="text-sm text-muted py-4 text-center">{{ __('No models registered for this provider.') }}</p>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="8" class="px-table-cell-x py-8 text-center">
                                        <div class="space-y-2">
                                            <p class="text-sm text-muted">{{ __('No providers connected yet.') }}</p>
                                            <x-ui.button variant="primary" wire:click="openCatalog">
                                                <x-icon name="heroicon-o-rectangle-stack" class="w-4 h-4" />
                                                {{ __('Browse Provider Catalog') }}
                                            </x-ui.button>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Provider Create/Edit Modal (manual add) --}}
    <x-ui.modal wire:model="showProviderForm" class="max-w-lg">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingProvider ? __('Edit Provider') : __('Add Provider') }}
                </h3>
                <button wire:click="$set('showProviderForm', false)" class="text-muted hover:text-ink">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <form wire:submit="saveProvider" class="space-y-4">
                @unless($isEditingProvider)
                    <x-ui.select wire:change="applyTemplate($event.target.value)" label="{{ __('Template') }}">
                        <option value="">{{ __('Other provider') }}</option>
                        @foreach($templateOptions as $tpl)
                            <option value="{{ $tpl['value'] }}" @selected($selectedTemplate === $tpl['value'])>{{ $tpl['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                @endunless

                <x-ui.input
                    wire:model="providerName"
                    label="{{ __('Name') }}"
                    required
                    placeholder="{{ __('e.g. openai') }}"
                    :disabled="$isEditingProvider"
                    :error="$errors->first('providerName')"
                />

                <x-ui.input
                    wire:model="providerDisplayName"
                    label="{{ __('Display Name') }}"
                    placeholder="{{ __('e.g. OpenAI') }}"
                    :error="$errors->first('providerDisplayName')"
                />

                <x-ui.input
                    wire:model="providerBaseUrl"
                    label="{{ __('Base URL') }}"
                    required
                    placeholder="{{ __('e.g. https://api.openai.com/v1') }}"
                    :error="$errors->first('providerBaseUrl')"
                />

                <x-ui.input
                    wire:model="providerApiKey"
                    type="password"
                    label="{{ __('API Key') }}"
                    :required="!$isEditingProvider"
                    :placeholder="$isEditingProvider ? __('Leave blank to keep current key') : ''"
                    :error="$errors->first('providerApiKey')"
                />

                <x-ui.checkbox wire:model="providerIsActive" label="{{ __('Active') }}" />

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" wire:click="$set('showProviderForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ $isEditingProvider ? __('Update') : __('Create') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Provider Delete Confirmation --}}
    <x-ui.modal wire:model="showDeleteProvider" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Delete Provider') }}</h3>
                <button wire:click="$set('showDeleteProvider', false)" class="text-muted hover:text-ink">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to delete :name? All associated models will also be removed.', ['name' => $deletingProviderName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteProvider', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteProvider">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Model Create/Edit Modal --}}
    <x-ui.modal wire:model="showModelForm" class="max-w-lg">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingModel ? __('Edit Model') : __('Add Model') }}
                </h3>
                <button wire:click="$set('showModelForm', false)" class="text-muted hover:text-ink">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <form wire:submit="saveModel" class="space-y-4">
                <x-ui.input
                    wire:model="modelModelName"
                    label="{{ __('Model ID') }}"
                    required
                    placeholder="{{ __('e.g. gpt-4o') }}"
                    :disabled="$isEditingModel"
                    :error="$errors->first('modelModelName')"
                />

                <div class="grid grid-cols-2 gap-4">
                    <x-ui.input
                        wire:model="modelCostInput"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Input Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostInput')"
                    />
                    <x-ui.input
                        wire:model="modelCostOutput"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Output Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostOutput')"
                    />
                    <x-ui.input
                        wire:model="modelCostCacheRead"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Cache Read Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostCacheRead')"
                    />
                    <x-ui.input
                        wire:model="modelCostCacheWrite"
                        type="number"
                        step="0.000001"
                        min="0"
                        label="{{ __('Cache Write Cost / 1M tokens') }}"
                        :error="$errors->first('modelCostCacheWrite')"
                    />
                </div>

                <x-ui.checkbox wire:model="modelIsActive" label="{{ __('Active') }}" />

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" wire:click="$set('showModelForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ $isEditingModel ? __('Update') : __('Create') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Model Delete Confirmation --}}
    <x-ui.modal wire:model="showDeleteModel" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Delete Model') }}</h3>
                <button wire:click="$set('showDeleteModel', false)" class="text-muted hover:text-ink">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to delete :name?', ['name' => $deletingModelName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteModel', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteModel">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
