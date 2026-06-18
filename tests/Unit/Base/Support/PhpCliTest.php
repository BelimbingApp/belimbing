<?php

use App\Base\Support\PhpCli;
use Tests\TestCase;

uses(TestCase::class);

function phpCliTestRoot(): string
{
    return storage_path('framework/testing/php-cli');
}

function phpCliTestExecutable(string $relativePath): string
{
    $path = phpCliTestRoot().'/'.str_replace('\\', '/', $relativePath);
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($path, '#!/usr/bin/env php');
    @chmod($path, 0755);

    return realpath($path) ?: $path;
}

afterEach(function (): void {
    $directory = phpCliTestRoot();

    if (is_dir($directory)) {
        phpCliDeleteDirectory($directory);
    }
});

it('builds artisan commands from an ordinary php binary', function (): void {
    $php = phpCliTestExecutable('ordinary/php.exe');

    expect((new PhpCli(phpBinary: $php))->artisan(['migrate', '--force']))
        ->toBe([$php, 'artisan', 'migrate', '--force']);
});

it('uses the Windows FrankenPHP sidecar php executable for artisan commands', function (): void {
    $frankenPhp = phpCliTestExecutable('windows/frankenphp.exe');
    $php = phpCliTestExecutable('windows/php.exe');

    expect((new PhpCli(phpBinary: $frankenPhp))->artisan(['migrate', '--force']))
        ->toBe([$php, 'artisan', 'migrate', '--force']);
});

it('falls back to FrankenPHP php-cli when no sidecar php executable exists', function (): void {
    $frankenPhp = phpCliTestExecutable('portable/frankenphp.exe');

    expect((new PhpCli(phpBinary: $frankenPhp))->script('artisan', ['about']))
        ->toBe([$frankenPhp, 'php-cli', 'artisan', 'about']);
});

it('prefers an exported PHP_BINARY wrapper over the current FrankenPHP runtime', function (): void {
    $wrapper = phpCliTestExecutable('wrapper/php.exe');
    $frankenPhp = phpCliTestExecutable('current/frankenphp');

    expect((new PhpCli(environmentPhpBinary: $wrapper, phpBinary: $frankenPhp))->commandPrefix())
        ->toBe([$wrapper]);
});

function phpCliDeleteDirectory(string $directory): void
{
    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            phpCliDeleteDirectory($path);

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}
