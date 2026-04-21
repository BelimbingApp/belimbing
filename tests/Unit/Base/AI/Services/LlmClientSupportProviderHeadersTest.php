<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\LlmClientSupport;

test('LlmClientSupport merges mapper and runtime provider headers', function (): void {
    $request = new ChatRequest(
        baseUrl: 'https://chatgpt.com/backend-api',
        apiKey: 'codex-token',
        model: 'gpt-5-codex',
        messages: [['role' => 'user', 'content' => 'hello']],
        providerName: 'openai-codex',
        providerHeaders: ['chatgpt-account-id' => 'acct_test'],
    );

    $http = LlmClientSupport::buildHttp($request, [
        'originator' => 'blb',
        'OpenAI-Beta' => 'responses=experimental',
    ]);

    $headers = $http->getOptions()['headers'] ?? [];

    expect($headers)->toMatchArray([
        'Authorization' => 'Bearer codex-token',
        'chatgpt-account-id' => 'acct_test',
        'originator' => 'blb',
        'OpenAI-Beta' => 'responses=experimental',
    ]);
});
