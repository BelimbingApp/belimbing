<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\ProviderMapping\ProviderRequestHeaderResolver;

test('ProviderRequestHeaderResolver adds codex headers including chatgpt-account-id', function (): void {
    $payload = rtrim(strtr(base64_encode(json_encode([
        'https://api.openai.com/auth' => ['chatgpt_account_id' => 'acct_test'],
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $jwt = 'aaa.'.$payload.'.zzz';

    $request = new ChatRequest(
        baseUrl: 'https://chatgpt.com/backend-api',
        apiKey: $jwt,
        model: 'gpt-5.4',
        messages: [['role' => 'user', 'content' => 'hi']],
        providerName: 'openai-codex',
    );

    $resolver = app(ProviderRequestHeaderResolver::class);
    $headers = $resolver->headersFor($request);

    expect($headers['chatgpt-account-id'] ?? null)->toBe('acct_test')
        ->and($headers['OpenAI-Beta'] ?? null)->toBe('responses=experimental')
        ->and($headers)->not->toHaveKey('accept')
        ->and($headers)->not->toHaveKey('content-type');
});
