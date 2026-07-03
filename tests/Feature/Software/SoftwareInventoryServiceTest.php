<?php

use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Software\Inventory\ContributionSummary;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Services\SoftwareInventoryService;

const SOFTWARE_INVENTORY_PLATFORM_REPO = 'BelimbingApp/belimbing';
const SOFTWARE_INVENTORY_PEOPLE_REPO = 'BelimbingApp/blb-people';
const SOFTWARE_INVENTORY_PEOPLE_PATH = 'app/Modules/People';
const SOFTWARE_INVENTORY_PAYROLL_PACKAGE = 'blb/payroll-my';
const SOFTWARE_INVENTORY_PAYROLL_MODULE = 'people/payroll';
const SOFTWARE_INVENTORY_PAYROLL_PATH = 'app/Modules/People/Payroll';
const SOFTWARE_INVENTORY_LEAVE_PACKAGE = 'blb/people-leave';
const SOFTWARE_INVENTORY_LEAVE_MODULE = 'people/leave';
const SOFTWARE_INVENTORY_LEAVE_PATH = 'app/Modules/People/Leave';
const SOFTWARE_INVENTORY_SAMPLE_PACKAGE = 'kiat/sample';

/**
 * @param  array{dirty?: int, ahead?: int, behind?: int}  $workingTree
 * @return array<string, mixed>
 */
function inventoryBundleStatus(string $key, string $relative, ?string $repo = null, ?string $branch = 'main', array $workingTree = []): array
{
    return [
        'key' => $key,
        'label' => $key,
        'path' => $relative,
        'absolutePath' => $relative === '.' ? base_path() : base_path($relative),
        'owner' => $repo !== null ? explode('/', $repo)[0] : null,
        'repo' => $repo,
        'branch' => $branch,
        'working_tree' => array_merge(['dirty' => 0, 'ahead' => 0, 'behind' => 0], $workingTree),
        'current' => $branch !== null ? ['sha' => str_repeat('a', 40), 'short' => 'aaaaaaa', 'subject' => 'init'] : null,
    ];
}

function inventoryManifest(string $module, string $relativePath, string $name, array $requires = []): ModuleManifest
{
    return new ModuleManifest(
        name: $name,
        module: $module,
        path: base_path($relativePath),
        version: '1.0.0',
        description: $module.' module',
        requiresModules: $requires,
    );
}

/**
 * @return array<string, InstalledBundle>
 */
function assembleByKey(array $bundleStatuses, array $manifests, array $dependencyIssues = [], array $disabled = [], array $contributions = []): array
{
    $bundles = app(SoftwareInventoryService::class)->assemble($bundleStatuses, $manifests, $dependencyIssues, $disabled, $contributions);

    return collect($bundles)->keyBy('key')->all();
}

it('groups modules under their nearest distribution bundle and falls Base/Core back to platform', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
            inventoryBundleStatus('extensions-kiat', 'extensions/kiat', 'kiatng/blb-kiat'),
        ],
        [
            inventoryManifest('base/database', 'app/Base/Database', 'blb/base-database'),
            inventoryManifest('core/company', 'app/Modules/Core/Company', 'blb/core-company'),
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, SOFTWARE_INVENTORY_PAYROLL_PACKAGE),
            inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE),
            inventoryManifest(SOFTWARE_INVENTORY_SAMPLE_PACKAGE, 'extensions/kiat/Sample', SOFTWARE_INVENTORY_SAMPLE_PACKAGE),
        ],
    );

    expect(collect($byKey['app-Modules-People']->modules)->pluck('module')->all())
        ->toBe([SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_PAYROLL_MODULE])
        ->and($byKey['app-Modules-People']->kind)->toBe(InstalledBundle::KIND_BUSINESS_DOMAIN)
        ->and($byKey['app-Modules-People']->lifecycleName)->toBe('People')
        ->and(collect($byKey['platform']->modules)->pluck('module')->all())
        ->toContain('base/database', 'core/company')
        ->and($byKey['platform']->kind)->toBe(InstalledBundle::KIND_PLATFORM)
        ->and(collect($byKey['extensions-kiat']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_SAMPLE_PACKAGE])
        ->and($byKey['extensions-kiat']->kind)->toBe(InstalledBundle::KIND_EXTENSION);
});

it('recognizes a module-level git root as its own slot-implementation bundle', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
            inventoryBundleStatus('app-Modules-People-Payroll', SOFTWARE_INVENTORY_PAYROLL_PATH, 'BelimbingApp/blb-payroll-variant'),
        ],
        [
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, 'blb/payroll-variant'),
            inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE),
        ],
    );

    expect($byKey['app-Modules-People-Payroll']->kind)->toBe(InstalledBundle::KIND_SLOT_IMPLEMENTATION)
        ->and(collect($byKey['app-Modules-People-Payroll']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_PAYROLL_MODULE])
        ->and($byKey['app-Modules-People-Payroll']->lifecycleName)->toBeNull()
        ->and(collect($byKey['app-Modules-People']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_LEAVE_MODULE]);
});

it('attaches dependency issues to the bundle that owns the requiring module', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
        ],
        [
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, SOFTWARE_INVENTORY_PAYROLL_PACKAGE, ['people/attendance' => '*']),
        ],
        [
            ['issue' => 'missing', 'requiring' => SOFTWARE_INVENTORY_PAYROLL_PACKAGE, 'requiring_module' => SOFTWARE_INVENTORY_PAYROLL_MODULE, 'required' => 'people/attendance', 'constraint' => '*'],
        ],
    );

    expect($byKey['app-Modules-People']->hasDependencyIssues())->toBeTrue()
        ->and($byKey['app-Modules-People']->dependencyIssues[0]['required'])->toBe('people/attendance')
        ->and($byKey['platform']->hasDependencyIssues())->toBeFalse();
});

it('marks a disabled business domain bundle', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
        ],
        [inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE)],
        [],
        ['People'],
    );

    expect($byKey['app-Modules-People']->disabled)->toBeTrue()
        ->and($byKey['platform']->disabled)->toBeFalse();
});

it('attaches contributions to the bundle that delivers the providing module', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
        ],
        [inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, SOFTWARE_INVENTORY_PAYROLL_PACKAGE)],
        [],
        [],
        [
            new ContributionSummary(
                hostModule: SOFTWARE_INVENTORY_PAYROLL_MODULE,
                seam: 'payroll.country-pack',
                kind: ContributionSummary::KIND_ADAPTER,
                label: 'Malaysia payroll rules',
                providerModule: SOFTWARE_INVENTORY_PAYROLL_MODULE,
                metadata: ['country' => 'MY'],
            ),
        ],
    );

    expect($byKey['app-Modules-People']->hasContributions())->toBeTrue()
        ->and($byKey['app-Modules-People']->contributions[0]->label)->toBe('Malaysia payroll rules')
        ->and($byKey['app-Modules-People']->contributions[0]->metadata['country'])->toBe('MY')
        ->and($byKey['platform']->hasContributions())->toBeFalse();
});

it('attributes a contribution to its domain bundle when the host module has no manifest', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventoryBundleStatus('app-Modules-Commerce', 'app/Modules/Commerce', 'BelimbingApp/blb-commerce'),
        ],
        [], // Commerce ships no per-module manifests
        [],
        [],
        [
            new ContributionSummary(
                hostModule: 'commerce/marketplace',
                seam: 'commerce.marketplace.channel',
                kind: ContributionSummary::KIND_CHANNEL,
                label: 'Shopee channel',
            ),
        ],
    );

    expect($byKey['app-Modules-Commerce']->hasContributions())->toBeTrue()
        ->and($byKey['app-Modules-Commerce']->contributions[0]->label)->toBe('Shopee channel');
});

it('reports working-tree dirty and unpushed state per bundle', function (): void {
    $byKey = assembleByKey(
        [inventoryBundleStatus('app-Modules-People', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO, 'main', ['dirty' => 3, 'ahead' => 2])],
        [inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE)],
    );

    expect($byKey['app-Modules-People']->isDirty())->toBeTrue()
        ->and($byKey['app-Modules-People']->unpushed())->toBe(2)
        ->and($byKey['app-Modules-People']->branch)->toBe('main')
        ->and($byKey['app-Modules-People']->repo)->toBe(SOFTWARE_INVENTORY_PEOPLE_REPO);
});
