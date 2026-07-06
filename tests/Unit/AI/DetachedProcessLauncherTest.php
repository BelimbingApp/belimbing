<?php

use App\Base\AI\Services\DetachedProcessLauncher;
use App\Base\AI\Services\ExecutableLocator;

function unixCommandLine(array $command, array $env = [], ?string $stdout = null, ?string $stderr = null): string
{
    return (new DetachedProcessLauncher(new ExecutableLocator))->buildUnixCommandLine(
        'nohup',
        $command,
        '/srv/app',
        $env,
        $stdout,
        $stderr,
    );
}

it('passes every command token through shell escaping', function (): void {
    $injection = '; rm -rf / #';
    $line = unixCommandLine(['node', 'script.js', $injection]);

    // The injected token appears only in its escaped form, never as bare syntax.
    expect($line)->toContain(escapeshellarg($injection))
        ->and($line)->toContain(escapeshellarg('node'))
        ->and($line)->toStartWith('cd '.escapeshellarg('/srv/app').' && '.escapeshellarg('nohup'));
});

it('escapes environment values so they cannot inject commands', function (): void {
    $value = "x'; touch pwned; '";
    $line = unixCommandLine(['node', 'app.js'], ['TOKEN' => $value]);

    expect($line)->toContain('TOKEN='.escapeshellarg($value));
});

it('escapes redirect paths', function (): void {
    $path = '/var/log/a b.log';
    $line = unixCommandLine(['node', 'app.js'], [], $path, $path);

    expect($line)->toContain('>> '.escapeshellarg($path))
        ->and($line)->toContain('2>&1');
});
