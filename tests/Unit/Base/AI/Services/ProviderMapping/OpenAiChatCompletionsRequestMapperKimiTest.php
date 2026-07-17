<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Services\ProviderMapping\OpenAiChatCompletionsRequestMapper;
use App\Base\AI\Services\ProviderMapping\ProviderCapabilityRegistry;
use App\Base\AI\Services\ProviderMapping\ProviderRequestHeaderResolver;

function kimiMapperPayload(string $model, ExecutionControls $controls): array
{
    $mapper = new OpenAiChatCompletionsRequestMapper(app(ProviderRequestHeaderResolver::class));

    return $mapper->mapPayload(new ChatRequest(
        baseUrl: 'https://api.kimi.ai/v1',
        apiKey: 'key',
        model: $model,
        messages: [['role' => 'user', 'content' => 'hi']],
        executionControls: $controls,
        providerName: 'moonshotai',
    ), stream: false)->payload;
}

test('kimi k3 maps effort to top-level reasoning_effort and never sends thinking', function (): void {
    $payload = kimiMapperPayload('kimi-k3', ExecutionControls::defaults(
        reasoningEffort: ReasoningEffort::Max,
    ));

    expect($payload['reasoning_effort'] ?? null)->toBe('max')
        ->and(array_key_exists('thinking', $payload))->toBeFalse();
});

test('kimi k3 omits thinking even when reasoning mode is disabled', function (): void {
    $payload = kimiMapperPayload('kimi-k3', ExecutionControls::defaults(
        reasoningMode: ReasoningMode::Disabled,
    ));

    expect(array_key_exists('thinking', $payload))->toBeFalse()
        ->and(array_key_exists('reasoning_effort', $payload))->toBeFalse();
});

test('kimi k2.5 maps disabled reasoning to the thinking object', function (): void {
    $payload = kimiMapperPayload('kimi-k2.5', ExecutionControls::defaults(
        reasoningMode: ReasoningMode::Disabled,
    ));

    expect($payload['thinking'] ?? null)->toBe(['type' => 'disabled']);
});

test('kimi k2.6 maps preserved reasoning context to thinking keep', function (): void {
    $payload = kimiMapperPayload('kimi-k2.6', ExecutionControls::defaults(
        preserveReasoningContext: true,
    ));

    expect($payload['thinking'] ?? null)->toBe(['type' => 'enabled', 'keep' => 'all']);
});

test('kimi k2.5 does not send keep even when preservation is requested', function (): void {
    $payload = kimiMapperPayload('kimi-k2.5', ExecutionControls::defaults(
        preserveReasoningContext: true,
    ));

    expect(array_key_exists('thinking', $payload))->toBeFalse();
});

test('always-thinking kimi models receive no reasoning parameters', function (): void {
    foreach (['kimi-k2.7-code', 'kimi-k2-thinking'] as $model) {
        $payload = kimiMapperPayload($model, ExecutionControls::defaults(
            reasoningMode: ReasoningMode::Disabled,
            preserveReasoningContext: true,
        ));

        expect(array_key_exists('thinking', $payload))->toBeFalse()
            ->and(array_key_exists('reasoning_effort', $payload))->toBeFalse();
    }
});

test('non-kimi chat completions models keep the legacy disabled-thinking mapping', function (): void {
    $payload = kimiMapperPayload('gpt-4o-mini', ExecutionControls::defaults(
        reasoningMode: ReasoningMode::Disabled,
    ));

    expect($payload['thinking'] ?? null)->toBe(['type' => 'disabled']);
});

test('capability registry exposes kimi reasoning capabilities per family', function (): void {
    $registry = new ProviderCapabilityRegistry;

    $k3 = $registry->capabilitiesFor('moonshotai', 'kimi-k3', AiApiType::OpenAiChatCompletions);
    expect($k3->supportedReasoningEffort)->toBe([ReasoningEffort::Max])
        ->and($k3->supportsReasoningModeToggle)->toBeFalse();

    $k26 = $registry->capabilitiesFor('moonshotai', 'kimi-k2.6', AiApiType::OpenAiChatCompletions);
    expect($k26->supportsReasoningContextPreservation)->toBeTrue()
        ->and($k26->supportsReasoningModeToggle)->toBeTrue()
        ->and($k26->supportedReasoningEffort)->toBe([]);

    $plain = $registry->capabilitiesFor('openai', 'gpt-4o-mini', AiApiType::OpenAiChatCompletions);
    expect($plain->supportedReasoningEffort)->toBe([])
        ->and($plain->supportsReasoningBudget)->toBeFalse();
});

test('capability registry differentiates responses and codex effort ladders', function (): void {
    $registry = new ProviderCapabilityRegistry;

    $responses = $registry->capabilitiesFor('openai', 'gpt-5.6-sol', AiApiType::OpenAiResponses);
    expect($responses->supportedReasoningEffort)->toBe([
        ReasoningEffort::None,
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
        ReasoningEffort::Max,
    ]);

    $codex = $registry->capabilitiesFor('openai-codex', 'gpt-5.6-sol', AiApiType::OpenAiCodexResponses);
    expect($codex->supportedReasoningEffort)->toBe([
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
        ReasoningEffort::Max,
        ReasoningEffort::Ultra,
    ]);

    $olderCodex = $registry->capabilitiesFor('openai-codex', 'gpt-5.4', AiApiType::OpenAiCodexResponses);
    expect($olderCodex->supportedReasoningEffort)->toBe([
        ReasoningEffort::Low,
        ReasoningEffort::Medium,
        ReasoningEffort::High,
        ReasoningEffort::XHigh,
    ]);

    $unknown = $registry->capabilitiesFor('openai-codex', 'future-codex-model', AiApiType::OpenAiCodexResponses);
    expect($unknown->supportedReasoningEffort)->toBe([]);
});
