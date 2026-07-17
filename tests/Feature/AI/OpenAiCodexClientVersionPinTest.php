<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use Illuminate\Support\Facades\Http;

test('an operator-pinned setting overrides the auto-resolved client_version', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    app(SettingsService::class)->set('ai.openai_codex.models_discovery_client_version', '0.133.7', scope: null);

    Http::fake([
        'https://chatgpt.com/backend-api/codex/models*' => Http::response(['models' => []], 200),
    ]);

    $provider = new AiProvider([
        'name' => 'openai-codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'credentials' => [
            'access_token' => 'jwt-token',
            'account_id' => 'acct_1',
        ],
        'auth_type' => 'oauth',
    ]);

    app(ModelDiscoveryService::class)->discoverModels($provider);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'client_version=0.133.7'));
    Http::assertNotSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://api.github.com/repos/openai/codex/releases/latest',
    ));
});
