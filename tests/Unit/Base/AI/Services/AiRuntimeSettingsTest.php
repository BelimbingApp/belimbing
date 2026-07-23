<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('ships a 100-round default without environment-backed config', function (): void {
    $definitions = app(SettingDefinitionRegistry::class);
    $runtimeSettings = app(AiRuntimeSettings::class);

    expect($definitions->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY)->default)->toBe(100)
        ->and($runtimeSettings->defaultMaxToolRounds())->toBe(100)
        ->and($runtimeSettings->maxToolRoundsRules())->toBe([
            'required',
            'integer',
            'min:1',
            'max:500',
        ])
        ->and(config(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeNull()
        ->and(config(AiRuntimeSettings::PDFTOTEXT_PATH_KEY))->toBeNull();
});
