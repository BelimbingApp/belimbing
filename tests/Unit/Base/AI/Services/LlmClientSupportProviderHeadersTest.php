<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\LlmClientSupport;
use Tests\TestCase;

uses(TestCase::class);

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

test('LlmClientSupport uses x-api-key for anthropic requests instead of bearer auth', function (): void {
    $request = new ChatRequest(
        baseUrl: 'https://api.anthropic.com/v1',
        apiKey: 'anthropic-token',
        model: 'claude-sonnet-4-6',
        messages: [['role' => 'user', 'content' => 'hello']],
        apiType: AiApiType::AnthropicMessages,
    );

    $http = LlmClientSupport::buildHttp($request, [
        'anthropic-version' => '2023-06-01',
    ]);

    $headers = $http->getOptions()['headers'] ?? [];

    expect($headers)->toMatchArray([
        'x-api-key' => 'anthropic-token',
        'anthropic-version' => '2023-06-01',
    ])->and($headers)->not->toHaveKey('Authorization');
});
