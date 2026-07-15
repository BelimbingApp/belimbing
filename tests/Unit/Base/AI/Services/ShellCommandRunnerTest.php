<?php

use App\Base\AI\Exceptions\ShellBackendUnavailableException;
use App\Base\AI\Services\ShellCommandRunner;
use App\Base\Support\ExecutableLocator;
use Tests\TestCase;

uses(TestCase::class);

it('builds a bash command when bash backend is configured', function (): void {
    config()->set('ai.shell.backend', 'bash');
    config()->set('ai.shell.bash_binary', 'bash');

    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')
        ->times(3)
        ->with(['bash'])
        ->andReturn('/usr/bin/bash');

    $runner = new ShellCommandRunner($locator);

    expect($runner->backendName())->toBe('bash')
        ->and($runner->backendLabel())->toBe('Bash')
        ->and($runner->command('echo hello'))->toBe([
            '/usr/bin/bash',
            '-lc',
            'echo hello',
        ]);
});

it('builds a powershell command when powershell backend is configured', function (): void {
    config()->set('ai.shell.backend', 'powershell');
    config()->set('ai.shell.powershell_binary', 'pwsh');

    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')
        ->times(3)
        ->with(['pwsh'])
        ->andReturn('C:\\Program Files\\PowerShell\\7\\pwsh.exe');

    $runner = new ShellCommandRunner($locator);

    expect($runner->backendName())->toBe('powershell')
        ->and($runner->backendLabel())->toBe('PowerShell')
        ->and($runner->command('Write-Output hello'))->toBe([
            'C:\\Program Files\\PowerShell\\7\\pwsh.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            'Write-Output hello',
        ]);
});

it('throws a clear error when the configured backend is unavailable', function (): void {
    config()->set('ai.shell.backend', 'powershell');
    config()->set('ai.shell.powershell_binary', 'pwsh');

    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')
        ->once()
        ->with(['pwsh'])
        ->andReturn(null);

    $runner = new ShellCommandRunner($locator);

    expect(fn (): array => $runner->command('Write-Output hello'))
        ->toThrow(ShellBackendUnavailableException::class, 'PowerShell is not available.');
});
