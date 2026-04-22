<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ProviderMapping\OpenAiResponsesRequestMapper;
use App\Base\AI\Services\ProviderMapping\ProviderCapabilityRegistry;
use App\Base\AI\Services\ProviderMapping\ProviderRequestHeaderResolver;

test('OpenAiResponsesRequestMapper supplies Codex-required instructions and payload fields', function (): void {
    $mapper = new OpenAiResponsesRequestMapper(
        app(ProviderCapabilityRegistry::class),
        app(ProviderRequestHeaderResolver::class),
    );

    $mapping = $mapper->mapPayload(new ChatRequest(
        baseUrl: 'https://chatgpt.com/backend-api',
        apiKey: 'codex-token',
        model: 'gpt-5.4',
        messages: [['role' => 'user', 'content' => 'Reply with OK only.']],
        providerName: 'openai-codex',
        apiType: AiApiType::OpenAiCodexResponses,
        providerHeaders: ['chatgpt-account-id' => 'acct_test'],
    ), stream: false);

    expect($mapping->payload['instructions'] ?? null)
        ->toBe('You are Codex, a coding assistant. Follow the user request exactly and reply concisely.')
        ->and(array_key_exists('max_output_tokens', $mapping->payload))->toBeFalse()
        ->and($mapping->payload['text'] ?? null)->toBe(['verbosity' => 'medium'])
        ->and($mapping->payload['include'] ?? null)->toBe(['reasoning.encrypted_content'])
        ->and($mapping->payload['parallel_tool_calls'] ?? null)->toBeTrue()
        ->and($mapping->payload['tool_choice'] ?? null)->toBe('auto');
});
