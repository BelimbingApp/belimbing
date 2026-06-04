<?php

use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use App\Base\Menu\Services\MenuDiscoveryService;

/**
 * The menu registry is cached in the cache store keyed by a config fingerprint so
 * discover()+validate() runs only when a menu config changes (Octane gives each
 * request a fresh container, so the in-memory singleton can't be relied on). The
 * fingerprint must be stable when unchanged and change when a menu file is added,
 * and the cache must self-invalidate when the fingerprint changes.
 */
test('menu config fingerprint is stable when files are unchanged', function (): void {
    $discovery = app(MenuDiscoveryService::class);

    expect($discovery->configFingerprint())
        ->toBeString()->not->toBe('')
        ->toBe($discovery->configFingerprint());
});

test('menu config fingerprint changes when a menu config file is added', function (): void {
    $discovery = app(MenuDiscoveryService::class);
    $before = $discovery->configFingerprint();

    $dir = base_path('app/Base/_FingerprintProbe/Config');
    $file = $dir.'/menu.php';
    @mkdir($dir, 0777, true);
    file_put_contents($file, "<?php\nreturn ['items' => []];\n");

    try {
        expect($discovery->configFingerprint())->not->toBe($before);
    } finally {
        @unlink($file);
        @rmdir($dir);
        @rmdir(base_path('app/Base/_FingerprintProbe'));
    }
});

test('registry cache round-trips under a fingerprint and self-invalidates', function (): void {
    $registry = app(MenuRegistry::class);
    $registry->registerFromDiscovery(collect([
        ['id' => 'probe.item', 'label' => 'Probe', 'route' => 'home', '_source' => ['file' => 'x', 'module_name' => 'X', 'module_path' => 'x']],
    ]));

    $registry->persist('fp-aaa');

    // Same fingerprint → hit.
    $fresh = app(MenuRegistry::class);
    expect($fresh->loadFromCache('fp-aaa'))->toBeTrue()
        ->and($fresh->getAll()->has('probe.item'))->toBeTrue();

    // Different fingerprint → miss (so a menu change forces a rebuild).
    expect(app(MenuRegistry::class)->loadFromCache('fp-bbb'))->toBeFalse();
});
