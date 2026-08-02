<?php

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexOAuthException;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->user = createAdminUser();
    $this->provider = AiProvider::query()->create([
        'company_id' => $this->user->company_id,
        'name' => OpenAiCodexDefinition::KEY,
        'family' => AiProvider::FAMILY_LLM,
        'display_name' => 'OpenAI Codex',
        'base_url' => 'https://chatgpt.com/backend-api',
        'auth_type' => 'oauth',
        'credentials' => [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $this->user->employee_id,
    ]);
    $this->manager = app(OpenAiCodexAuthManager::class);

    $payload = base64_encode(json_encode([
        'https://api.openai.com/auth' => [
            'chatgpt_account_id' => 'acct_user_binding',
        ],
    ], JSON_THROW_ON_ERROR));
    $accessToken = 'header.'.rtrim(strtr($payload, '+/', '-_'), '=').'.signature';

    Http::fake([
        'https://auth.openai.com/oauth/token' => Http::response([
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-user-binding',
            'expires_in' => 3600,
        ]),
    ]);
});

test('cross-user callback is rejected without updating provider credentials', function (): void {
    $login = $this->manager->startLogin($this->provider, $this->user->id);
    $otherUser = User::factory()->create(['company_id' => $this->user->company_id]);
    $this->actingAs($otherUser);

    $callback = Request::create('/callback', 'GET', [
        'code' => 'authorization-code',
        'state' => $login['state'],
    ]);

    expect(fn () => $this->manager->completeCallback($callback))
        ->toThrow(OpenAiCodexOAuthException::class, 'OAuth state does not match the authenticated user.');

    expect($this->provider->fresh()->credentials)->toBe([])
        ->and(Cache::get('openai_codex_oauth:'.$login['state']))->not->toBeNull();

    Http::assertNothingSent();
});

test('same-user callback succeeds', function (): void {
    $login = $this->manager->startLogin($this->provider, $this->user->id);
    $this->actingAs($this->user);

    $result = $this->manager->completeCallback(Request::create('/callback', 'GET', [
        'code' => 'authorization-code',
        'state' => $login['state'],
    ]));

    expect($result->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN])->toBe('refresh-user-binding')
        ->and($result->credentials[OpenAiCodexDefinition::CRED_ACCOUNT_ID])->toBe('acct_user_binding')
        ->and(Cache::get('openai_codex_oauth:'.$login['state']))->toBeNull();
});

test('cli completion with a null user ID bypasses the user check', function (): void {
    $login = $this->manager->startLogin($this->provider, $this->user->id);
    $input = $this->manager->redirectUri().'?code=authorization-code&state='.$login['state'];

    $result = $this->manager->completeManualInput($input, null);

    expect($result->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN])->toBe('refresh-user-binding')
        ->and(Cache::get('openai_codex_oauth:'.$login['state']))->toBeNull();
});

test('cli-initiated flow can be completed by an authenticated user', function (): void {
    $login = $this->manager->startLogin($this->provider, null);
    $otherUser = User::factory()->create(['company_id' => $this->user->company_id]);
    $this->actingAs($otherUser);

    $result = $this->manager->completeCallback(Request::create('/callback', 'GET', [
        'code' => 'authorization-code',
        'state' => $login['state'],
    ]));

    expect($result->credentials[OpenAiCodexDefinition::CRED_REFRESH_TOKEN])->toBe('refresh-user-binding')
        ->and(Cache::get('openai_codex_oauth:'.$login['state']))->toBeNull();
});
