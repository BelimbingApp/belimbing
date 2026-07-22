<?php

use App\Base\AI\Services\AiRuntimeSettings;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('ships a 100-round default for agent tool loops', function (): void {
    expect(AiRuntimeSettings::DEFAULT_MAX_TOOL_ROUNDS)->toBe(100)
        ->and(config(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(100);
});
