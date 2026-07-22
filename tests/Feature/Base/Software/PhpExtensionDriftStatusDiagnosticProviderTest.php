<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Services\PhpExtensionDriftProbe;
use App\Base\Software\Services\PhpExtensionDriftStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;

const PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION = 'zz_never_loaded_test_extension';

function phpExtensionDriftDiagnosticFixtureRoot(): string
{
    return storage_path('framework/testing/php-extension-drift-diagnostic');
}

function bindPhpExtensionDriftProbe(array $extensionNames): void
{
    File::ensureDirectoryExists(phpExtensionDriftDiagnosticFixtureRoot());
    $path = phpExtensionDriftDiagnosticFixtureRoot().'/fixture.ini';
    $contents = collect($extensionNames)->map(fn (string $name): string => "extension={$name}")->implode("\n");
    file_put_contents($path, $contents."\n");

    app()->instance(PhpExtensionDriftProbe::class, new PhpExtensionDriftProbe([$path]));
}

afterEach(function (): void {
    File::deleteDirectory(phpExtensionDriftDiagnosticFixtureRoot());
});

it('reports missing php extensions as a status bar diagnostic', function (): void {
    bindPhpExtensionDriftProbe([PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION]);

    $diagnostics = collect(app(PhpExtensionDriftStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.php-extension-drift')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->summary)->toBe('1 PHP extension enabled in php.ini is not loaded')
        ->and($diagnostics[0]->detail)->toBe('Reloading FrankenPHP workers will not fix this — extensions load once when the process starts. Open Updates for host restart instructions.')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.software.updates.index'))
        ->and($diagnostics[0]->metadata)->toBe(['extensions' => [PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION]]);
});

it('pluralizes the summary for multiple missing extensions', function (): void {
    bindPhpExtensionDriftProbe([PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION, 'zz_second_fake_extension']);

    $diagnostics = collect(app(PhpExtensionDriftStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->summary)->toBe('2 PHP extensions enabled in php.ini are not loaded');
});

it('reports no diagnostic when nothing is missing', function (): void {
    bindPhpExtensionDriftProbe([]);

    expect(collect(app(PhpExtensionDriftStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('hides the diagnostic from users without update access', function (): void {
    bindPhpExtensionDriftProbe([PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION]);

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(PhpExtensionDriftStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('surfaces the extension drift diagnostic through the status bar aggregator', function (): void {
    bindPhpExtensionDriftProbe([PHP_EXTENSION_DRIFT_DIAGNOSTIC_FAKE_EXTENSION]);

    $response = $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 PHP extension enabled in php.ini is not loaded')
        ->assertSee('href="'.route('admin.system.software.updates.index').'"', false);
});
