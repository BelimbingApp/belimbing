<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\ProviderMapping\ProviderRequestHeaderResolver;

test('ProviderRequestHeaderResolver adds codex static headers without reconstructing account state', function (): void {
    $request = new ChatRequest(
        baseUrl: 'https://chatgpt.com/backend-api',
        apiKey: 'codex-access-token',
        model: 'gpt-5.4',
        messages: [['role' => 'user', 'content' => 'hi']],
        providerName: 'openai-codex',
        providerHeaders: ['chatgpt-account-id' => 'acct_test'],
    );

    $resolver = app(ProviderRequestHeaderResolver::class);
    $headers = $resolver->headersFor($request);

    expect($headers)->not->toHaveKey('chatgpt-account-id')
        ->and($headers['OpenAI-Beta'] ?? null)->toBe('responses=experimental')
        ->and($headers['originator'] ?? null)->toBe('pi')
        ->and($headers['Accept'] ?? null)->toBe('text/event-stream')
        ->and($headers['Content-Type'] ?? null)->toBe('application/json')
        ->and($headers['User-Agent'] ?? null)->toBe('pi (php)');
});
