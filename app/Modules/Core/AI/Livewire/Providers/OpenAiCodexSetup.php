<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Enums\AiErrorType;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthStorage;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;
use App\Modules\Core\AI\Services\ProviderTestService;
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

    public string $oauthRedirectUri = '';

    public string $manualRedirectInput = '';

    public ?string $manualCompletionError = null;

    /** @var array<string, mixed>|null */
    public ?array $authState = null;

    /** @var array<string, mixed>|null */
    public ?array $verificationResult = null;

    public function mount(string $providerKey): void
    {
        parent::mount($providerKey);
        $this->oauthRedirectUri = app(OpenAiCodexAuthManager::class)->redirectUri();

        $provider = $this->loadProvider();
        if ($provider && ($this->readAuthState($provider)['status'] ?? null) === 'connected') {
            $provider = $this->syncModels($provider);
        }

        $this->syncStateFromProvider($provider);
    }

    public function startOauthLogin(): void
    {
        $provider = $this->loadOrCreateProvider();
        if (! $provider) {
            return;
        }

        $result = app(OpenAiCodexAuthManager::class)->startLogin($provider);
        $this->manualCompletionError = null;
        $this->verificationResult = null;
        $this->syncStateFromProvider($provider->fresh() ?? $provider);

        $this->dispatch('openai-codex-oauth-opened', url: $result['authorize_url']);
    }

    public function completeOauthLogin(): void
    {
        try {
            $provider = app(OpenAiCodexAuthManager::class)->completeManualInput($this->manualRedirectInput);
            $provider = $this->syncModels($provider);
            $this->manualRedirectInput = '';
            $this->manualCompletionError = null;
            $this->syncStateFromProvider($provider);
        } catch (\Throwable $e) {
            $this->manualCompletionError = $e->getMessage();
            $this->syncStateFromProvider($this->loadProvider());
        }
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
        $this->verificationResult = null;
    }

    public function verifyConnection(): void
    {
        $provider = $this->loadProvider();
        if (! $provider) {
            return;
        }

        $provider = $this->syncModels($provider);
        $model = $this->resolveVerificationModel($provider);

        if (! $model) {
            $this->storeVerificationFailure(
                $provider,
                'config_error',
                __('OpenAI Codex has no active model configured for verification.'),
            );

            return;
        }

        $result = app(ProviderTestService::class)->testSelection($provider->id, $model->model_id);

        $this->verificationResult = array_merge($result->toArray(), [
            'checked_at' => now()->toIso8601String(),
        ]);

        if ($result->connected) {
            app(OpenAiCodexAuthStorage::class)->clearDiagnosticError($provider->fresh() ?? $provider);
        } else {
            $error = $result->error;
            $this->storeVerificationFailure(
                $provider,
                $error?->errorType->value ?? 'verification_failed',
                $error?->userMessage ?? __('Verification failed.'),
                $error?->hint,
            );
        }

        $this->syncStateFromProvider($provider->fresh());
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
        $this->syncStateFromProvider($this->loadProvider());

        return parent::render();
    }

    public function providerConnectionDescription(): ?string
    {
        return __('Codex subscription — browser sign-in is required. BLB emulates the OpenClaw/OpenAI localhost callback flow and depends on an undocumented external contract that may break without notice.');
    }

    public function providerHeaderSubtitle(): ?string
    {
        return __('Connect with browser OAuth, then paste the localhost callback URL so BLB can store refreshable subscription credentials.');
    }

    public function providerHeaderHelpPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-help.openai-codex';
    }

    public function providerConnectedActionsPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.connected-actions.openai-codex';
    }

    public function providerStatusPanelPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-status.openai-codex';
    }

    public function providerCredentialsFormPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-form.openai-codex';
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

        $definition = app(ProviderDefinitionRegistry::class)->for(OpenAiCodexDefinition::KEY);
        $normalized = $definition->validateAndNormalize($this->gatherInput(), ProviderOperation::Create);

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

    private function syncStateFromProvider(?AiProvider $provider): void
    {
        $this->providerId = $provider?->id;
        $this->authState = $this->readAuthState($provider);
        $this->connectedProviderId = $provider && ($this->authState['status'] ?? null) === 'connected'
            ? $provider->id
            : null;
    }

    private function syncModels(AiProvider $provider): AiProvider
    {
        app(ModelDiscoveryService::class)->syncModels($provider);

        return $provider->fresh() ?? $provider;
    }

    private function resolveVerificationModel(AiProvider $provider): ?AiProviderModel
    {
        return AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->first();
    }

    private function storeVerificationFailure(AiProvider $provider, string $code, string $message, ?string $hint = null): void
    {
        $storage = app(OpenAiCodexAuthStorage::class);

        if ($code === AiErrorType::AuthError->value) {
            $storage->markExpired($provider, $code, $message);
        } else {
            $storage->recordDiagnosticFailure($provider, $code, $message);
        }

        $this->verificationResult = [
            'connected' => false,
            'provider_name' => $provider->name,
            'model' => $this->resolveVerificationModel($provider)?->model_id ?? '',
            'latency_ms' => null,
            'error_type' => $code,
            'user_message' => $message,
            'hint' => $hint,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
