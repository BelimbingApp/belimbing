<?php

use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Software\Inventory\ContributionSummary;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\Software\Services\SoftwareInventoryService;

const SOFTWARE_INVENTORY_PLATFORM_REPO = 'BelimbingApp/belimbing';
const SOFTWARE_INVENTORY_PEOPLE_REPO = 'BelimbingApp/blb-people';
const SOFTWARE_INVENTORY_PEOPLE_PATH = 'app/Domains/People';
const SOFTWARE_INVENTORY_PAYROLL_PACKAGE = 'blb/payroll-my';
const SOFTWARE_INVENTORY_PAYROLL_MODULE = 'people/payroll';
const SOFTWARE_INVENTORY_PAYROLL_PATH = 'app/Domains/People/Payroll';
const SOFTWARE_INVENTORY_LEAVE_PACKAGE = 'blb/people-leave';
const SOFTWARE_INVENTORY_LEAVE_MODULE = 'people/leave';
const SOFTWARE_INVENTORY_LEAVE_PATH = 'app/Domains/People/Leave';
const SOFTWARE_INVENTORY_SAMPLE_PACKAGE = 'kiat/sample';

/**
 * @param  array{dirty?: int, ahead?: int, behind?: int}  $workingTree
 * @return array<string, mixed>
 */
function inventorySourceStatus(string $key, string $relative, ?string $repo = null, ?string $branch = 'main', array $workingTree = []): array
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
 * @return array<string, InstalledSource>
 */
function assembleByKey(array $sourceStatuses, array $manifests, array $dependencyIssues = [], array $disabled = [], array $contributions = []): array
{
    $sources = app(SoftwareInventoryService::class)->assemble($sourceStatuses, $manifests, $dependencyIssues, $disabled, $contributions);

    return collect($sources)->keyBy('key')->all();
}

it('groups modules under their nearest source source and falls Base/Core back to platform', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
            inventorySourceStatus('extension-kiat', 'app/Extensions/Kiat', 'kiatng/blb-kiat'),
        ],
        [
            inventoryManifest('base/database', 'app/Base/Database', 'blb/base-database'),
            inventoryManifest('core/company', 'app/Core/Company', 'blb/core-company'),
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, SOFTWARE_INVENTORY_PAYROLL_PACKAGE),
            inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE),
            inventoryManifest(SOFTWARE_INVENTORY_SAMPLE_PACKAGE, 'app/Extensions/Kiat/Sample', SOFTWARE_INVENTORY_SAMPLE_PACKAGE),
        ],
    );

    expect(collect($byKey['domain-people']->modules)->pluck('module')->all())
        ->toBe([SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_PAYROLL_MODULE])
        ->and($byKey['domain-people']->kind)->toBe(InstalledSource::KIND_DOMAIN)
        ->and($byKey['domain-people']->lifecycleName)->toBe('People')
        ->and(collect($byKey['platform']->modules)->pluck('module')->all())
        ->toContain('base/database', 'core/company')
        ->and($byKey['platform']->kind)->toBe(InstalledSource::KIND_PLATFORM)
        ->and(collect($byKey['extension-kiat']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_SAMPLE_PACKAGE])
        ->and($byKey['extension-kiat']->kind)->toBe(InstalledSource::KIND_EXTENSION);
});

it('recognizes a module-level git root as its own slot-implementation source', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
            inventorySourceStatus('module-people-payroll', SOFTWARE_INVENTORY_PAYROLL_PATH, 'BelimbingApp/blb-payroll-variant'),
        ],
        [
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, 'blb/payroll-variant'),
            inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE),
        ],
    );

    expect($byKey['module-people-payroll']->kind)->toBe(InstalledSource::KIND_SLOT_IMPLEMENTATION)
        ->and(collect($byKey['module-people-payroll']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_PAYROLL_MODULE])
        ->and($byKey['module-people-payroll']->lifecycleName)->toBeNull()
        ->and(collect($byKey['domain-people']->modules)->pluck('module')->all())->toBe([SOFTWARE_INVENTORY_LEAVE_MODULE]);
});

it('attaches dependency issues to the source that owns the requiring module', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
        ],
        [
            inventoryManifest(SOFTWARE_INVENTORY_PAYROLL_MODULE, SOFTWARE_INVENTORY_PAYROLL_PATH, SOFTWARE_INVENTORY_PAYROLL_PACKAGE, ['people/attendance' => '*']),
        ],
        [
            ['issue' => 'missing', 'requiring' => SOFTWARE_INVENTORY_PAYROLL_PACKAGE, 'requiring_module' => SOFTWARE_INVENTORY_PAYROLL_MODULE, 'required' => 'people/attendance', 'constraint' => '*'],
        ],
    );

    expect($byKey['domain-people']->hasDependencyIssues())->toBeTrue()
        ->and($byKey['domain-people']->dependencyIssues[0]['required'])->toBe('people/attendance')
        ->and($byKey['platform']->hasDependencyIssues())->toBeFalse();
});

it('marks a disabled business domain source', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
        ],
        [inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE)],
        [],
        ['People'],
    );

    expect($byKey['domain-people']->disabled)->toBeTrue()
        ->and($byKey['platform']->disabled)->toBeFalse();
});

it('attaches contributions to the source that delivers the providing module', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO),
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

    expect($byKey['domain-people']->hasContributions())->toBeTrue()
        ->and($byKey['domain-people']->contributions[0]->label)->toBe('Malaysia payroll rules')
        ->and($byKey['domain-people']->contributions[0]->metadata['country'])->toBe('MY')
        ->and($byKey['platform']->hasContributions())->toBeFalse();
});

it('attributes a contribution to its domain source when the host module has no manifest', function (): void {
    $byKey = assembleByKey(
        [
            inventorySourceStatus('platform', '.', SOFTWARE_INVENTORY_PLATFORM_REPO),
            inventorySourceStatus('domain-commerce', 'app/Domains/Commerce', 'BelimbingApp/blb-commerce'),
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

    expect($byKey['domain-commerce']->hasContributions())->toBeTrue()
        ->and($byKey['domain-commerce']->contributions[0]->label)->toBe('Shopee channel');
});

it('reports working-tree dirty and unpushed state per source', function (): void {
    $byKey = assembleByKey(
        [inventorySourceStatus('domain-people', SOFTWARE_INVENTORY_PEOPLE_PATH, SOFTWARE_INVENTORY_PEOPLE_REPO, 'main', ['dirty' => 3, 'ahead' => 2])],
        [inventoryManifest(SOFTWARE_INVENTORY_LEAVE_MODULE, SOFTWARE_INVENTORY_LEAVE_PATH, SOFTWARE_INVENTORY_LEAVE_PACKAGE)],
    );

    expect($byKey['domain-people']->isDirty())->toBeTrue()
        ->and($byKey['domain-people']->unpushed())->toBe(2)
        ->and($byKey['domain-people']->branch)->toBe('main')
        ->and($byKey['domain-people']->repo)->toBe(SOFTWARE_INVENTORY_PEOPLE_REPO);
});
