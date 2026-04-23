<?php

use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ModelCatalogService;
use Tests\TestCase;

uses(TestCase::class);

it('resolves Anthropic models to the native Messages API type', function (): void {
    $service = new ModelCatalogService;

    expect($service->resolveApiType('anthropic', 'claude-sonnet-4-6'))
        ->toBe(AiApiType::AnthropicMessages);
});

it('resolves OpenAI Codex models to the Codex Responses API type', function (): void {
    $service = new ModelCatalogService;

    expect($service->resolveApiType('openai-codex', 'gpt-5.4'))
        ->toBe(AiApiType::OpenAiCodexResponses);
});

it('falls back to chat completions when no provider override matches', function (): void {
    $service = new ModelCatalogService;

    expect($service->resolveApiType('openai', 'gpt-4o-mini'))
        ->toBe(AiApiType::OpenAiChatCompletions)
        ->and($service->resolveApiType(null, 'claude-sonnet-4-6'))
        ->toBe(AiApiType::OpenAiChatCompletions);
});
