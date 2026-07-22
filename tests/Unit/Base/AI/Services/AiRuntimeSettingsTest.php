<?php

use App\Base\AI\Services\AiRuntimeSettings;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('ships a 100-round default for agent tool loops', function (): void {
    expect(AiRuntimeSettings::DEFAULT_MAX_TOOL_ITERATIONS)->toBe(100)
        ->and(config(AiRuntimeSettings::MAX_TOOL_ITERATIONS_KEY))->toBe(100);
});
