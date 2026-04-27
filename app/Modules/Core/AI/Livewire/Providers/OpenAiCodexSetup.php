<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

use App\Base\AI\Enums\AiErrorType;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Livewire\Providers\Concerns\ConfiguresOpenAiCodexSetupView;
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
    use ConfiguresOpenAiCodexSetupView;

    public ?int $providerId = null;

    public string $oauthRedirectUri = '';

    public string $manualRedirectInput = '';

    public ?string $manualCompletionError = null;

    public bool $listenerSpawned = false;

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

        $this->listenerSpawned = $this->spawnCallbackListener();

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

    public function render(): View
    {
        $this->syncStateFromProvider($this->loadProvider());

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

    /**
     * Spawn a background artisan process to listen on localhost:1455
     * for the OAuth callback, so the user does not need to paste the URL.
     */
    private function spawnCallbackListener(): bool
    {
        if (! $this->isPortAvailable(1455)) {
            return false;
        }

        $php = $this->resolvePhpBinary();
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/codex-auth-listen.log');
        $cmd = sprintf(
            'nohup %s %s blb:ai:codex:auth-listen --timeout=180 >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($logFile),
        );

        exec($cmd);

        return true;
    }

    /**
     * Resolve the PHP CLI binary path.
     *
     * FrankenPHP workers set PHP_BINARY to empty string. Fall back to
     * a versioned binary in PHP_BINDIR, then to an unversioned `php`.
     */
    private function resolvePhpBinary(): string
    {
        $binary = PHP_BINARY;

        if ($binary !== '' && ! str_contains(basename($binary), 'frankenphp')) {
            return $binary;
        }

        $versioned = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (file_exists($versioned)) {
            return $versioned;
        }

        $plain = PHP_BINDIR.'/php';
        if (file_exists($plain)) {
            return $plain;
        }

        return 'php';
    }

    private function isPortAvailable(int $port): bool
    {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($conn) {
            fclose($conn);

            return false;
        }

        return true;
    }
}
