<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\AI\Services\ModelCatalogService;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\Models\AiProvider;

/**
 * Provider CRUD state and actions for the provider manager component.
 *
 * Handles manual add/edit/delete of company-scoped AI providers, including
 * template pre-fill from the models.dev catalog and priority reordering.
 */
trait ManagesProviders
{
    public bool $showProviderForm = false;

    public bool $isEditingProvider = false;

    public ?int $editingProviderId = null;

    public string $providerName = '';

    public string $providerDisplayName = '';

    public string $providerBaseUrl = '';

    public string $providerApiKey = '';

    public ?string $providerApiKeyPreview = null;

    public bool $providerIsActive = true;

    public string $selectedTemplate = '';

    public bool $showDeleteProvider = false;

    public ?int $deletingProviderId = null;

    public string $deletingProviderName = '';

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
        $this->providerApiKeyPreview = BlbStr::maskMiddle($provider->credentials['api_key'] ?? '', 7, 4);
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
                unset($data['name']);

                if ($this->providerApiKey !== '') {
                    $data['credentials'] = ['api_key' => $this->providerApiKey];
                }

                $provider->update($data);
            }
        } else {
            $data['auth_type'] = 'api_key';
            $data['credentials'] = ['api_key' => $this->providerApiKey];
            $data['connection_config'] = [];
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
            $this->dispatch('provider-priority-saved-'.$providerId, message: __('Priority updated.'));
        }
    }

    private function resetProviderForm(): void
    {
        $this->editingProviderId = null;
        $this->providerName = '';
        $this->providerDisplayName = '';
        $this->providerBaseUrl = '';
        $this->providerApiKey = '';
        $this->providerApiKeyPreview = null;
        $this->providerIsActive = true;
        $this->selectedTemplate = '';
        $this->resetValidation();
    }
}
