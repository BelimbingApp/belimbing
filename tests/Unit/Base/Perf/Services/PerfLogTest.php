<?php

use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('Directory permissions do not restrict root.');
    }

    config(['settings.cache_ttl' => 0]);

    $this->root = storage_path('framework/testing/perf-log-'.uniqid());
    File::ensureDirectoryExists($this->root);
});

afterEach(function (): void {
    @chmod($this->root, 0755);

    foreach (glob($this->root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
        @chmod($directory, 0755);
    }

    File::deleteDirectory($this->root);
});

function perfLogWritingTo(string $directory): PerfLog
{
    app(SettingsService::class)->set(PerfRuntimeSettings::LOG_PATH_KEY, $directory);
    app(PerfRuntimeSettings::class)->refresh();

    return app(PerfLog::class);
}

it('appends one json line per entry', function (): void {
    $log = perfLogWritingTo($this->root);

    $log->write(['type' => 'command', 'path' => 'about']);
    $log->write(['type' => 'command', 'path' => 'inspire']);

    $files = glob($this->root.'/perf-*.jsonl');
    $lines = array_values(array_filter(explode(PHP_EOL, file_get_contents($files[0]))));

    expect($files)->toHaveCount(1)
        ->and($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true)['path'])->toBe('inspire');
});

it('drops the entry and warns once when the log directory is not writable', function (): void {
    $directory = $this->root.'/readonly';
    File::ensureDirectoryExists($directory);
    chmod($directory, 0555);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Perf log write failed')
            && str_starts_with($context['path'], $directory.'/perf-')
            && $context['error'] !== '');

    $log = perfLogWritingTo($directory);

    $log->write(['type' => 'command', 'path' => 'about']);
    $log->write(['type' => 'command', 'path' => 'about']);

    expect(glob($directory.'/perf-*.jsonl'))->toBeEmpty();
});

it('drops the entry and warns once when the log directory is missing and cannot be created', function (): void {
    chmod($this->root, 0555);
    $directory = $this->root.'/missing/nested';

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($context['error'], $this->root.'/missing'));

    $log = perfLogWritingTo($directory);

    $log->write(['type' => 'command', 'path' => 'about']);
    $log->write(['type' => 'command', 'path' => 'about']);

    expect(is_dir($directory))->toBeFalse();
});

it('does not throw when the framework log itself cannot be written', function (): void {
    $directory = $this->root.'/readonly';
    File::ensureDirectoryExists($directory);
    chmod($directory, 0555);

    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('laravel.log is on a full disk'));

    perfLogWritingTo($directory)->write(['type' => 'command', 'path' => 'about']);

    expect(glob($directory.'/perf-*.jsonl'))->toBeEmpty();
});

it('drops the entry and warns once when the perf settings cannot be read', function (): void {
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('getMany')->andThrow(new RuntimeException('settings database connection lost'));
    app()->instance(SettingsService::class, $settings);
    app()->forgetInstance(PerfRuntimeSettings::class);
    app()->forgetInstance(PerfLog::class);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['path'] === null
            && $context['error'] === 'settings database connection lost');

    $log = app(PerfLog::class);

    $log->write(['type' => 'command', 'path' => 'about']);
    $log->write(['type' => 'command', 'path' => 'about']);
});
