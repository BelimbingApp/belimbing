<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Services\FrontendBuildStatusDiagnosticProvider;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->buildRoot = storage_path('framework/testing/frontend-build-'.uniqid());
    File::ensureDirectoryExists($this->buildRoot.'/sources');

    $this->manifest = $this->buildRoot.'/manifest.json';
    $this->hot = $this->buildRoot.'/hot';
    $this->packageJson = $this->buildRoot.'/package.json';
    $this->sourceA = $this->buildRoot.'/sources/app.js';

    File::put($this->packageJson, '{}');
    File::put($this->sourceA, '// source');
});

afterEach(function (): void {
    File::deleteDirectory($this->buildRoot);
});

function frontendBuildProvider(object $context): FrontendBuildStatusDiagnosticProvider
{
    return new FrontendBuildStatusDiagnosticProvider(
        app(AuthorizationService::class),
        manifestPath: $context->manifest,
        hotPath: $context->hot,
        packageJsonPath: $context->packageJson,
        sourcePatterns: [$context->buildRoot.'/sources/*.js'],
    );
}

it('warns when the built assets are older than their sources', function (): void {
    File::put($this->manifest, '{}');
    touch($this->manifest, time() - 3600);
    touch($this->sourceA, time() - 60);

    $diagnostics = collect(frontendBuildProvider($this)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.frontend-build.stale')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Warning)
        ->and($diagnostics[0]->summary)->toBe('Frontend assets are older than their sources')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.software.updates.index'))
        ->and($diagnostics[0]->metadata['newest_source'])->toContain('sources/app.js');
});

it('stays quiet when the build is newer than every source', function (): void {
    touch($this->sourceA, time() - 3600);
    File::put($this->manifest, '{}');
    touch($this->manifest, time() - 60);

    expect(collect(frontendBuildProvider($this)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('stays quiet while the Vite dev server is serving sources live', function (): void {
    File::put($this->manifest, '{}');
    touch($this->manifest, time() - 3600);
    touch($this->sourceA, time() - 60);
    File::put($this->hot, 'https://127.0.0.1:5173');

    expect(collect(frontendBuildProvider($this)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('reports a missing build as an error', function (): void {
    $diagnostics = collect(frontendBuildProvider($this)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.frontend-build.missing')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error);
});

it('stays quiet when there is no frontend package at all', function (): void {
    File::delete($this->packageJson);

    expect(collect(frontendBuildProvider($this)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('emits nothing for users who cannot manage updates', function (): void {
    File::put($this->manifest, '{}');
    touch($this->manifest, time() - 3600);
    touch($this->sourceA, time() - 60);

    $user = User::factory()->create();

    expect(collect(frontendBuildProvider($this)->diagnosticsFor($user)))->toBeEmpty();
});
