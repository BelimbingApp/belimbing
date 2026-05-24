<?php

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Contracts\MarketplaceChannelProvider;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Plugins\Contracts\CommerceReadinessContributor;
use App\Modules\Commerce\Plugins\Services\CommercePluginDiscoveryService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Support\Facades\File;

final class CommercePluginDiscoveryTestReadinessContributor implements CommerceReadinessContributor
{
    public function id(): string
    {
        return 'test.readiness';
    }

    public function readinessForItem(Item $item): array
    {
        return [
            [
                'code' => 'test.ready',
                'severity' => 'success',
                'label' => 'Test readiness entry',
            ],
        ];
    }
}

final class CommercePluginDiscoveryTestChannelProvider implements MarketplaceChannelProvider
{
    public function registerMarketplaceChannel(MarketplaceChannelRegistry $registry): void
    {
        // Test double only verifies discovery/registration of provider classes.
    }
}

test('commerce plugin discovery loads nested extension contributions', function (): void {
    $root = storage_path('framework/testing/commerce-plugin-discovery');
    File::deleteDirectory($root);
    File::ensureDirectoryExists($root.'/extensions/vendor/package/Config');

    $configPath = $root.'/extensions/vendor/package/Config/commerce.php';
    File::put($configPath, <<<'PHP'
<?php

return [
    'catalog_presets' => [
        ['id' => 'vendor.package.catalog', 'label' => 'Vendor Catalog'],
    ],
    'readiness_contributors' => [
        CommercePluginDiscoveryTestReadinessContributor::class,
    ],
    'marketplace_channel_providers' => [
        CommercePluginDiscoveryTestChannelProvider::class,
    ],
    'marketplace_template_mappings' => [
        [
            'id' => 'vendor.package.ebay.template',
            'channel' => 'ebay',
            'template_code' => 'vendor-template',
            'marketplace_id' => 'EBAY_MOTORS_US',
            'category_tree_id' => '100',
            'category_id' => '33710',
        ],
    ],
    'workbench_panels' => [
        [
            'id' => 'vendor.package.panel',
            'label' => 'Vendor Panel',
            'subject' => 'commerce.inventory.item',
            'readiness_contributor' => CommercePluginDiscoveryTestReadinessContributor::class,
        ],
    ],
    'insight_pages' => [
        ['id' => 'vendor.package.insight', 'route' => 'vendor.insight'],
    ],
];
PHP);

    $registry = new CommercePluginRegistry;
    $discovery = new CommercePluginDiscoveryService([
        $root.'/extensions/*/*/Config/commerce.php',
    ]);

    $discovery->discoverInto($registry);

    expect($registry->catalogPresets())->toHaveKey('vendor.package.catalog')
        ->and($registry->readinessContributors())->toContain(CommercePluginDiscoveryTestReadinessContributor::class)
        ->and($registry->marketplaceChannelProviders())->toContain(CommercePluginDiscoveryTestChannelProvider::class)
        ->and($registry->marketplaceTemplateMappings())->toHaveKey('vendor.package.ebay.template')
        ->and($registry->workbenchPanels())->toHaveKey('vendor.package.panel')
        ->and($registry->insightPages())->toHaveKey('vendor.package.insight');

    $item = Item::factory()->make();
    $panels = $registry->itemReadinessPanels($item);

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['id'])->toBe('test.readiness')
        ->and($panels[0]['entries'][0]['code'])->toBe('test.ready');

    File::deleteDirectory($root);
});

test('commerce plugin discovery leaves generic commerce usable without nested extensions', function (): void {
    $registry = new CommercePluginRegistry;
    $discovery = new CommercePluginDiscoveryService([
        base_path('app/Modules/Commerce/*/Config/commerce.php'),
    ]);

    $discovery->discoverInto($registry);

    expect($registry->workbenchPanels())->toHaveKey('commerce.inventory.item-basics')
        ->and($registry->catalogPresets())->not->toHaveKey('ham.auto-parts.catalog')
        ->and($registry->marketplaceTemplateMappings())->not->toHaveKey('ham.auto-parts.ebay-motors.lighting_headlights')
        ->and($registry->insightPages())->not->toHaveKey('ham.auto-parts.insights.sold-this-month');
});
