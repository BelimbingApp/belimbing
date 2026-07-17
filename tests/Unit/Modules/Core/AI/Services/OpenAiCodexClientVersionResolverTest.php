<?php

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\OpenAiCodexClientVersionResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const CODEX_GH_LATEST_URL = 'https://api.github.com/repos/openai/codex/releases/latest';

function codexVersionTestProvider(): AiProvider
{
    return new AiProvider([
        'name' => 'openai-codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'credentials' => [
            'access_token' => 'jwt-token',
            'account_id' => 'acct_1',
        ],
        'auth_type' => 'oauth',
    ]);
}

test('resolver returns the latest stable version and caches it', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    Http::fake([
        CODEX_GH_LATEST_URL => Http::response(['tag_name' => 'rust-v0.150.2'], 200),
    ]);

    $resolver = app(OpenAiCodexClientVersionResolver::class);

    expect($resolver->latest())->toBe('0.150.2');
    expect($resolver->latest())->toBe('0.150.2');

    Http::assertSentCount(1);
});

test('resolver is disabled by config and makes no request', function (): void {
    config()->set('ai.openai_codex.auto_client_version', false);

    Http::fake();

    expect(app(OpenAiCodexClientVersionResolver::class)->latest())->toBeNull();

    Http::assertNothingSent();
});

test('resolver rejects malformed tags and does not cache failures', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    Http::fake([
        CODEX_GH_LATEST_URL => Http::sequence()
            ->push(['tag_name' => 'rust-v0.151.0-alpha.3'], 200)
            ->push(['tag_name' => 'rust-v0.151.0'], 200),
    ]);

    $resolver = app(OpenAiCodexClientVersionResolver::class);

    expect($resolver->latest())->toBeNull();
    expect($resolver->latest())->toBe('0.151.0');
});

test('resolver returns null on http failure', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    Http::fake([
        CODEX_GH_LATEST_URL => Http::response('rate limited', 403),
    ]);

    expect(app(OpenAiCodexClientVersionResolver::class)->latest())->toBeNull();
});

test('codex model discovery uses the auto-resolved client_version', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    Http::fake([
        CODEX_GH_LATEST_URL => Http::response(['tag_name' => 'rust-v0.150.2'], 200),
        'https://chatgpt.com/backend-api/codex/models*' => Http::response(['models' => []], 200),
    ]);

    app(ModelDiscoveryService::class)->discoverModels(codexVersionTestProvider());

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://chatgpt.com/backend-api/codex/models')
        && str_contains($request->url(), 'client_version=0.150.2'));
});

test('codex model discovery falls back to the shipped constant when auto resolution fails', function (): void {
    config()->set('ai.openai_codex.auto_client_version', true);

    Http::fake([
        CODEX_GH_LATEST_URL => Http::response('rate limited', 403),
        'https://chatgpt.com/backend-api/codex/models*' => Http::response(['models' => []], 200),
    ]);

    app(ModelDiscoveryService::class)->discoverModels(codexVersionTestProvider());

    Http::assertSent(fn ($request): bool => str_contains(
        $request->url(),
        'client_version='.OpenAiCodexDefinition::MODELS_DISCOVERY_DEFAULT_CLIENT_VERSION,
    ));
});
