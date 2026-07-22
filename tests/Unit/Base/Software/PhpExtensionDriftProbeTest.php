<?php

use App\Base\Software\Services\PhpExtensionDriftProbe;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const PHP_EXTENSION_DRIFT_FAKE_EXTENSION = 'zz_never_loaded_test_extension';

function phpExtensionDriftFixtureRoot(): string
{
    return storage_path('framework/testing/php-extension-drift');
}

function writePhpExtensionDriftIniFixture(string $name, string $contents): string
{
    File::ensureDirectoryExists(phpExtensionDriftFixtureRoot());
    $path = phpExtensionDriftFixtureRoot().'/'.$name.'.ini';
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    File::deleteDirectory(phpExtensionDriftFixtureRoot());
});

it('reports an extension declared in php.ini but not loaded in this process', function (): void {
    $ini = writePhpExtensionDriftIniFixture('missing', 'extension='.PHP_EXTENSION_DRIFT_FAKE_EXTENSION."\n");

    $probe = new PhpExtensionDriftProbe([$ini]);

    expect($probe->missingExtensions())->toBe([PHP_EXTENSION_DRIFT_FAKE_EXTENSION]);
});

it('ignores commented-out extension directives', function (): void {
    $ini = writePhpExtensionDriftIniFixture('commented', '; extension='.PHP_EXTENSION_DRIFT_FAKE_EXTENSION."\n");

    expect((new PhpExtensionDriftProbe([$ini]))->missingExtensions())->toBe([]);
});

it('does not report an extension that is actually loaded', function (): void {
    $loaded = get_loaded_extensions()[0];
    $ini = writePhpExtensionDriftIniFixture('loaded', 'extension='.$loaded."\n");

    expect((new PhpExtensionDriftProbe([$ini]))->missingExtensions())->not->toContain(strtolower($loaded));
});

it('normalizes a php_ prefix and a .dll suffix to the bare extension name', function (): void {
    $ini = writePhpExtensionDriftIniFixture('windows-style', 'extension=php_'.PHP_EXTENSION_DRIFT_FAKE_EXTENSION.".dll\n");

    expect((new PhpExtensionDriftProbe([$ini]))->missingExtensions())->toBe([PHP_EXTENSION_DRIFT_FAKE_EXTENSION]);
});

it('returns no drift when php.ini declares no extensions', function (): void {
    $ini = writePhpExtensionDriftIniFixture('empty', "; nothing enabled here\n");

    expect((new PhpExtensionDriftProbe([$ini]))->missingExtensions())->toBe([]);
});

it('merges declarations across the main ini and scanned .ini files', function (): void {
    $main = writePhpExtensionDriftIniFixture('main', 'extension='.PHP_EXTENSION_DRIFT_FAKE_EXTENSION."\n");
    $scanned = writePhpExtensionDriftIniFixture('scanned', "extension=zz_second_fake_extension\n");

    $missing = (new PhpExtensionDriftProbe([$main, $scanned]))->missingExtensions();

    expect($missing)->toContain(PHP_EXTENSION_DRIFT_FAKE_EXTENSION)
        ->and($missing)->toContain('zz_second_fake_extension');
});

it('ignores ini file paths that do not exist', function (): void {
    expect((new PhpExtensionDriftProbe(['/no/such/file.ini']))->missingExtensions())->toBe([]);
});
