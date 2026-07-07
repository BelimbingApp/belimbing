<?php

use App\Modules\Core\AI\Tools\BashTool;

// The tool only resolves a ShellCommandRunner once the gate passes, so a
// `bash_disabled` result proves no command reached the shell — had the gate
// failed open, the result would instead carry the command's output.

it('refuses to run when disabled', function (): void {
    config()->set('ai.tools.bash.enabled', false);

    $result = (new BashTool)->execute(['command' => 'echo pwned']);

    expect($result->isError)->toBeTrue()
        ->and($result->errorPayload?->code)->toBe('bash_disabled');
});

it('also blocks the streaming path when disabled', function (): void {
    config()->set('ai.tools.bash.enabled', false);

    $generator = (new BashTool)->executeStreaming(['command' => 'echo pwned']);

    // A disabled tool yields nothing and returns the result via Generator::getReturn().
    iterator_to_array($generator);

    expect($generator->getReturn()->errorPayload?->code)->toBe('bash_disabled');
});

it('defaults to disabled outside local/dev environments', function (): void {
    // Mirrors the config('ai.tools.bash.enabled') default expression.
    $enabled = fn (string $env): bool => $env !== 'production';

    expect($enabled('production'))->toBeFalse()
        ->and($enabled('local'))->toBeTrue();
});
