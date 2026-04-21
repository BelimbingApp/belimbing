<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Provider setup page for OpenAI Codex OAuth.
 *
 * Establishes the browser PKCE login entrypoint and avoids the generic
 * auto-connect + model-sync assumptions in ProviderSetup.
 */
final class OpenAiCodexSetup extends ProviderSetup
{
    public ?int $providerId = null;

    /** @var array<string, mixed>|null */
    public ?array $authState = null;

    public function mount(string $providerKey): void
    {
        parent::mount($providerKey);

        $provider = $this->loadProvider();
        $this->providerId = $provider?->id;
        $this->authState = $this->readAuthState($provider);

        if ($provider && ($this->authState['status'] ?? null) === 'connected') {
            $this->connectedProviderId = $provider->id;
        }
    }

    public function startOauthLogin(): mixed
    {
        $provider = $this->loadOrCreateProvider();
        if (! $provider) {
            return null;
        }

        $redirectUri = route('admin.ai.providers.openai-codex.callback');
        $result = app(OpenAiCodexAuthManager::class)->startLogin($provider, $redirectUri);

        return redirect()->away($result['authorize_url']);
    }

    public function disconnect(): void
    {
        $provider = $this->loadProvider();
        if (! $provider) {
            return;
        }

        app(OpenAiCodexAuthManager::class)->logout($provider);
        $this->providerId = $provider->id;
        $this->authState = $this->readAuthState($provider->fresh());
        $this->connectedProviderId = null;
    }

    protected function tryAutoConnect(): void
    {
        // OAuth provider: never auto-connect based on base_url; login flow is explicit.
    }

    /**
     * Override: codex credentials are managed by OAuth, not pasted into apiKey.
     *
     * @return array<string, mixed>
     */
    protected function gatherInput(): array
    {
        return [
            'base_url' => $this->baseUrl,
        ];
    }

    protected function mapFieldToProperty(string $fieldKey): string
    {
        return match ($fieldKey) {
            'base_url' => 'baseUrl',
            default => parent::mapFieldToProperty($fieldKey),
        };
    }

    public function render(): View
    {
        $provider = $this->loadProvider();
        $this->authState = $this->readAuthState($provider);

        return parent::render();
    }

    private function loadProvider(): ?AiProvider
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return null;
        }

        return AiProvider::query()
            ->forCompany($companyId)
            ->where('name', OpenAiCodexDefinition::KEY)
            ->first();
    }

    private function loadOrCreateProvider(): ?AiProvider
    {
        $companyId = $this->getCompanyId();

        if ($companyId === null) {
            return null;
        }

        $existing = $this->loadProvider();
        if ($existing) {
            return $existing;
        }

        $definition = app(\App\Modules\Core\AI\Services\ProviderDefinitionRegistry::class)->for(OpenAiCodexDefinition::KEY);
        $normalized = $definition->validateAndNormalize($this->gatherInput(), \App\Modules\Core\AI\Enums\ProviderOperation::Create);

        $provider = AiProvider::query()->create(array_merge($normalized, [
            'company_id' => $companyId,
            'name' => OpenAiCodexDefinition::KEY,
            'display_name' => $this->displayName,
            'is_active' => true,
            'created_by' => Auth::user()?->employee?->id,
        ]));

        $provider->assignNextPriority();

        return $provider;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAuthState(?AiProvider $provider): ?array
    {
        if (! $provider) {
            return null;
        }

        $auth = $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? null;

        return is_array($auth) ? $auth : null;
    }
}
