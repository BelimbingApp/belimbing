<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Tools\BashTool;

// The tool only resolves a ShellCommandRunner once the gate passes, so a
// `bash_disabled` result proves no command reached the shell — had the gate
// failed open, the result would instead carry the command's output.

it('refuses to run when disabled', function (): void {
    app(SettingsService::class)->set(AiRuntimeSettings::BASH_TOOL_ENABLED_KEY, false);

    $result = (new BashTool)->execute(['command' => 'echo pwned']);

    expect($result->isError)->toBeTrue()
        ->and($result->errorPayload?->code)->toBe('bash_disabled');
});

it('also blocks the streaming path when disabled', function (): void {
    app(SettingsService::class)->set(AiRuntimeSettings::BASH_TOOL_ENABLED_KEY, false);

    $generator = (new BashTool)->executeStreaming(['command' => 'echo pwned']);

    // A disabled tool yields nothing and returns the result via Generator::getReturn().
    iterator_to_array($generator);

    expect($generator->getReturn()->errorPayload?->code)->toBe('bash_disabled');
});

it('uses the safe declared default in every environment', function (): void {
    config()->set('app.env', 'local');

    expect(app(AiRuntimeSettings::class)->bashToolEnabled())->toBeFalse();

    config()->set('app.env', 'production');

    expect(app(AiRuntimeSettings::class)->bashToolEnabled())->toBeFalse();
});
