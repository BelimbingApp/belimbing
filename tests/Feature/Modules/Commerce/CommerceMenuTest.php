<?php

use App\Base\Menu\Contracts\NavigableMenuSnapshot;

test('commerce menu is organized around operator jobs instead of implementation buckets', function (): void {
    $user = createAdminUser();

    $items = app(NavigableMenuSnapshot::class)
        ->snapshotForUser($user)['filtered']
        ->keyBy('id');

    expect($items['commerce.catalog']->label)->toBe('Catalog')
        ->and($items['commerce.catalog.categories']->parent)->toBe('commerce.catalog')
        ->and($items['commerce.catalog.categories']->label)->toBe('Categories')
        ->and($items['commerce.catalog.templates']->parent)->toBe('commerce.catalog')
        ->and($items['commerce.catalog.templates']->label)->toBe('Templates')
        ->and($items['commerce.catalog.attributes']->parent)->toBe('commerce.catalog')
        ->and($items['commerce.catalog.attributes']->label)->toBe('Attributes')
        ->and($items['commerce.inventory']->label)->toBe('Inventory')
        ->and($items['commerce.marketplace']->label)->toBe('Marketplace')
        ->and($items['commerce.sales']->label)->toBe('Sales')
        ->and($items['commerce.reports']->label)->toBe('Reports')
        ->and($items['commerce.settings']->label)->toBe('Settings')
        ->and($items)->not->toHaveKey('commerce.ham-auto-parts')
        ->and($items)->not->toHaveKey('commerce.inventory.setting');

    expect($items['commerce.settings.general']->parent)->toBe('commerce.settings')
        ->and($items['commerce.settings.general']->label)->toBe('General')
        ->and($items['commerce.marketplace.ebay-setting']->parent)->toBe('commerce.settings')
        ->and($items['commerce.marketplace.ebay-setting']->label)->toBe('eBay Settings');

    if ($items->has('commerce.ham-auto-parts.setting')) {
        expect($items['commerce.ham-auto-parts.setting']->parent)->toBe('commerce.settings')
            ->and($items['commerce.ham-auto-parts.setting']->label)->toBe('Auto Parts');

        expect($items['commerce.ham-auto-parts.insights.recent-sale']->parent)->toBe('commerce.sales')
            ->and($items['commerce.ham-auto-parts.insights.recent-sale']->label)->toBe('Recent Sales')
            ->and($items['commerce.ham-auto-parts.insights.sold-this-month']->parent)->toBe('commerce.reports')
            ->and($items['commerce.ham-auto-parts.insights.sold-this-month']->label)->toBe('Month-to-Date Sales')
            ->and($items['commerce.ham-auto-parts.insights.sales-by-category']->parent)->toBe('commerce.reports')
            ->and($items['commerce.ham-auto-parts.insights.sales-by-category']->label)->toBe('Category Sales')
            ->and($items['commerce.ham-auto-parts.insights.top-earners-last-90-days']->parent)->toBe('commerce.reports')
            ->and($items['commerce.ham-auto-parts.insights.top-earners-last-90-days']->label)->toBe('Top Sellers (90 Days)')
            ->and($items['commerce.ham-auto-parts.insights.listed-without-sale']->parent)->toBe('commerce.marketplace')
            ->and($items['commerce.ham-auto-parts.insights.listed-without-sale']->label)->toBe('Unsold Listings');
    }
});
