<?php

use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ExecutionControlSchemaFactory;
use Tests\TestCase;

uses(TestCase::class);

function kimiSchemaControls(string $model): array
{
    $schema = app(ExecutionControlSchemaFactory::class)->build(
        providerName: 'moonshotai',
        model: $model,
        apiType: AiApiType::OpenAiChatCompletions,
        controls: app(ExecutionControlSchemaFactory::class)->defaultControls(),
    );

    $byPath = [];

    foreach ($schema['groups'] as $group) {
        foreach ($group['controls'] as $control) {
            $byPath[$control['path']] = $control;
        }
    }

    return $byPath;
}

test('kimi k3 schema offers effort without a reasoning mode toggle', function (): void {
    $controls = kimiSchemaControls('kimi-k3');

    expect($controls)->toHaveKey('reasoning.effort')
        ->and($controls)->not->toHaveKey('reasoning.mode')
        ->and(array_column($controls['reasoning.effort']['options'], 'value'))->toBe(['', 'max']);
});

test('kimi k2.6 schema offers mode toggle and preserved reasoning', function (): void {
    $controls = kimiSchemaControls('kimi-k2.6');

    expect($controls)->toHaveKey('reasoning.mode')
        ->and($controls)->toHaveKey('tools.preserve_reasoning_context')
        ->and($controls)->not->toHaveKey('reasoning.effort');
});

test('non-thinking chat completions models expose no reasoning schema', function (): void {
    $controls = kimiSchemaControls('gpt-4o-mini');

    expect($controls)->not->toHaveKey('reasoning.mode')
        ->and($controls)->not->toHaveKey('reasoning.effort');
});
