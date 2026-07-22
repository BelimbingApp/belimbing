<?php

use App\Base\Software\Services\PhpExtensionDriftProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const PHP_EXTENSION_DRIFT_PAGE_FAKE_EXTENSION = 'zz_never_loaded_test_extension';

function phpExtensionDriftUiFixtureRoot(): string
{
    return storage_path('framework/testing/php-extension-drift-ui');
}

function bindPhpExtensionDriftProbeForUi(array $extensionNames): void
{
    File::ensureDirectoryExists(phpExtensionDriftUiFixtureRoot());
    $path = phpExtensionDriftUiFixtureRoot().'/fixture.ini';
    $contents = collect($extensionNames)->map(fn (string $name): string => "extension={$name}")->implode("\n");
    file_put_contents($path, $contents."\n");

    app()->instance(PhpExtensionDriftProbe::class, new PhpExtensionDriftProbe([$path]));
}

afterEach(function (): void {
    File::deleteDirectory(phpExtensionDriftUiFixtureRoot());
});

test('the updates page shows extension drift with external restart guidance', function (): void {
    $user = createAdminUser();
    Process::fake();
    Http::fake();
    bindPhpExtensionDriftProbeForUi([PHP_EXTENSION_DRIFT_PAGE_FAKE_EXTENSION]);

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('1 PHP extension enabled in php.ini is not loaded in the running process')
        ->assertSee(PHP_EXTENSION_DRIFT_PAGE_FAKE_EXTENSION)
        ->assertSee('Restart from the host')
        ->assertSee('This page does not stop the process')
        ->assertDontSee('Restart PHP process');
});

test('the updates page hides extension restart guidance when nothing is missing', function (): void {
    $user = createAdminUser();
    Process::fake();
    Http::fake();
    bindPhpExtensionDriftProbeForUi([]);

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertDontSee('Restart from the host');
});
