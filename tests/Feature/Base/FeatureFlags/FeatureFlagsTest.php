<?php

use App\Base\FeatureFlags\Exceptions\DuplicateFeatureFlagException;
use App\Base\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use App\Base\FeatureFlags\Services\FeatureFlagDefinition;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Feature flag registry (#645): declarations live in module descriptors;
 * reads go through one service; undeclared names refuse; tenants may override.
 */
function featureFlagsReplace(FeatureFlagDefinition ...$definitions): FeatureFlags
{
    $registry = app(FeatureFlagRegistry::class);
    $registry->replace($definitions);

    return app(FeatureFlags::class);
}

it('refuses an undeclared feature flag', function (): void {
    $flags = featureFlagsReplace(
        new FeatureFlagDefinition('demo.declared', default: false, module: 'base/demo'),
    );

    expect(fn () => $flags->enabled('demo.missing'))
        ->toThrow(UndeclaredFeatureFlagException::class, 'demo.missing');
});

it('resolves the declared default until a tenant override is stored', function (): void {
    $tenant = createTenant(['name' => 'Feature flag tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $flags = featureFlagsReplace(
        new FeatureFlagDefinition('demo.beta', default: false, module: 'base/demo', description: 'Beta surface'),
    );

    expect($flags->enabled('demo.beta'))->toBeFalse();

    $flags->override('demo.beta', true);

    expect($flags->enabled('demo.beta'))->toBeTrue()
        ->and($flags->listForCurrentTenant())->toContain([
            'flag' => 'demo.beta',
            'module' => 'base/demo',
            'description' => 'Beta surface',
            'default' => false,
            'enabled' => true,
            'overridden' => true,
        ]);
});

it('keeps per-tenant overrides isolated', function (): void {
    $alpha = createTenant(['name' => 'Alpha flags']);
    $beta = createTenant(['name' => 'Beta flags']);
    $tenants = app(TenantContext::class);

    $flags = featureFlagsReplace(
        new FeatureFlagDefinition('demo.isolated', default: false, module: 'base/demo'),
    );

    $tenants->set((int) $alpha->id);
    $flags->override('demo.isolated', true);

    $tenants->set((int) $beta->id);
    expect($flags->enabled('demo.isolated'))->toBeFalse();

    $tenants->set((int) $alpha->id);
    expect($flags->enabled('demo.isolated'))->toBeTrue();
});

it('reads feature-flags from a module descriptor through the registry', function (): void {
    $root = storage_path('framework/testing/feature-flags-'.bin2hex(random_bytes(4)));
    $module = $root.'/app/Extensions/acme/flags';
    File::ensureDirectoryExists($module);

    file_put_contents($module.'/composer.json', json_encode([
        'name' => 'acme/flags',
        'extra' => [
            'blb' => [
                'module' => 'acme/flags',
                'version' => '1.0.0',
                'feature-flags' => [
                    'acme.surface' => [
                        'default' => true,
                        'description' => 'Acme surface',
                    ],
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $reader = new ModuleManifestReader([$root.'/app/Extensions']);
        $manifest = $reader->all()[0];

        expect($manifest->featureFlags)->toBe([
            'acme.surface' => [
                'default' => true,
                'description' => 'Acme surface',
            ],
        ]);

        $registry = new FeatureFlagRegistry($reader);
        expect($registry->get('acme.surface')->default)->toBeTrue()
            ->and($registry->get('acme.surface')->module)->toBe('acme/flags');
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists declared flags for the current tenant through the artisan command', function (): void {
    $tenant = createTenant(['name' => 'Flag list tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    featureFlagsReplace(
        new FeatureFlagDefinition('demo.list', default: true, module: 'base/demo', description: 'Listed'),
    );

    Artisan::call('blb:feature-flags', ['--json' => true]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toContain([
        'flag' => 'demo.list',
        'module' => 'base/demo',
        'description' => 'Listed',
        'default' => true,
        'enabled' => true,
        'overridden' => false,
    ]);
});


it('refuses duplicate feature-flag identities when replacing the registry', function (): void {
    $registry = app(FeatureFlagRegistry::class);

    expect(fn () => $registry->replace([
        new FeatureFlagDefinition('demo.dup', default: false, module: 'base/one'),
        new FeatureFlagDefinition('demo.dup', default: true, module: 'base/two'),
    ]))->toThrow(DuplicateFeatureFlagException::class, 'demo.dup');
});

it('refuses duplicate feature-flag identities across module manifests', function (): void {
    $root = storage_path('framework/testing/feature-flags-dup-'.bin2hex(random_bytes(4)));
    $a = $root.'/app/Extensions/acme/a';
    $b = $root.'/app/Extensions/acme/b';
    File::ensureDirectoryExists($a);
    File::ensureDirectoryExists($b);

    foreach ([
        [$a, 'acme/a'],
        [$b, 'acme/b'],
    ] as [$dir, $module]) {
        file_put_contents($dir.'/composer.json', json_encode([
            'name' => $module,
            'extra' => [
                'blb' => [
                    'module' => $module,
                    'version' => '1.0.0',
                    'feature-flags' => [
                        'acme.shared' => ['default' => false],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    try {
        $registry = new FeatureFlagRegistry(new ModuleManifestReader([$root.'/app/Extensions']));
        expect(fn () => $registry->all())
            ->toThrow(DuplicateFeatureFlagException::class, 'acme.shared');
    } finally {
        File::deleteDirectory($root);
    }
});

it('reports whether a flag is declared via has()', function (): void {
    $registry = app(FeatureFlagRegistry::class);
    $registry->replace([
        new FeatureFlagDefinition('demo.has', default: false, module: 'base/demo'),
    ]);

    expect($registry->has('demo.has'))->toBeTrue()
        ->and($registry->has('demo.missing'))->toBeFalse();
});

it('clears a tenant override so the declared default applies again', function (): void {
    $tenant = createTenant(['name' => 'Clear override tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $flags = featureFlagsReplace(
        new FeatureFlagDefinition('demo.clear', default: false, module: 'base/demo'),
    );

    $flags->override('demo.clear', true);
    expect($flags->enabled('demo.clear'))->toBeTrue();

    $flags->clearOverride('demo.clear');
    expect($flags->enabled('demo.clear'))->toBeFalse();
});

it('renders the feature-flags table when json is not requested', function (): void {
    $tenant = createTenant(['name' => 'Flag table tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    featureFlagsReplace(
        new FeatureFlagDefinition('demo.table', default: false, module: 'base/demo', description: 'Tabled'),
    );

    Artisan::call('blb:feature-flags');

    $output = Artisan::output();
    expect($output)->toContain('demo.table')
        ->and($output)->toContain('base/demo')
        ->and($output)->toContain('Tabled');
});

it('reports when no feature flags are declared for the table command', function (): void {
    $tenant = createTenant(['name' => 'Empty flags tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    featureFlagsReplace();

    Artisan::call('blb:feature-flags');

    expect(Artisan::output())->toContain('No feature flags are declared');
});

it('fails the feature-flags command when no tenant is in context', function (): void {
    app(TenantContext::class)->clear();
    featureFlagsReplace(
        new FeatureFlagDefinition('demo.need-tenant', default: false, module: 'base/demo'),
    );

    $exit = Artisan::call('blb:feature-flags');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('No tenant is in context');
});
