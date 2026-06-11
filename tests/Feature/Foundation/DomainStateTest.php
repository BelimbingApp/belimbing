<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use App\Base\Foundation\Services\DomainResidueScanner;
use App\Base\Foundation\Services\DomainState;
use App\Base\Menu\Services\MenuDiscoveryService;
use App\Base\Settings\Models\Setting;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(app_path('Modules/ZzStateDomain'));
});

it('persists disable and enable', function (): void {
    expect(DomainState::isDisabled('Commerce'))->toBeFalse();

    DomainState::disable('Commerce');

    expect(DomainState::isDisabled('Commerce'))->toBeTrue()
        ->and(DomainState::disabled())->toBe(['Commerce']);

    DomainState::enable('Commerce');

    expect(DomainState::isDisabled('Commerce'))->toBeFalse()
        ->and(DomainState::disabled())->toBe([]);
});

it('filters only paths under disabled domains', function (): void {
    $paths = [
        app_path('Modules/People/Settings/Config/menu.php'),
        app_path('Modules/Core/User/Config/menu.php'),
        app_path('Base/Menu/Config/menu.php'),
        base_path('extensions/acme/widget/Config/menu.php'),
    ];

    expect(DomainState::filterPaths($paths))->toBe($paths);

    DomainState::disable('People');

    expect(DomainState::filterPaths($paths))->toBe(array_slice($paths, 1));
});

it('hides a disabled domain from provider and menu discovery while its data stays claimed', function (): void {
    createFakeDomainCheckout('ZzStateDomain', 'zz_state_table', 'zz_state.option', [
        'withProvider' => true,
        'withMenu' => true,
    ]);

    $provider = 'App\Modules\ZzStateDomain\Sample\ServiceProvider';
    $menuIds = fn (): array => app(MenuDiscoveryService::class)->discover()->pluck('id')->all();

    expect(ProviderRegistry::discoverModuleProviders())->toContain($provider)
        ->and($menuIds())->toContain('zz-fake-domain-root');

    DomainState::disable('ZzStateDomain');

    expect(ProviderRegistry::discoverModuleProviders())->not->toContain($provider)
        ->and($menuIds())->not->toContain('zz-fake-domain-root');

    // Disabled is not uninstalled: the domain's settings rows are still
    // claimed by its on-disk declaration, so the residue scanner stays quiet.
    Setting::query()->create([
        'key' => 'zz_state.option',
        'value' => 'kept',
        'scope_type' => null,
        'scope_id' => null,
    ]);

    $report = app(DomainResidueScanner::class)->scan();

    expect(array_column($report['orphanSettings'], 'key'))->not->toContain('zz_state.option');
});

it('varies the menu config fingerprint with the disabled set', function (): void {
    createFakeDomainCheckout('ZzStateDomain', 'zz_state_table', 'zz_state.option', ['withMenu' => true]);

    $service = app(MenuDiscoveryService::class);
    $enabled = $service->configFingerprint();

    DomainState::disable('ZzStateDomain');

    expect($service->configFingerprint())->not->toBe($enabled);
});
