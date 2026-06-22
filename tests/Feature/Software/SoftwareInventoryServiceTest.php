<?php

use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Services\SoftwareInventoryService;

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
function assembleByKey(array $bundleStatuses, array $manifests, array $dependencyIssues = [], array $disabled = []): array
{
    $bundles = app(SoftwareInventoryService::class)->assemble($bundleStatuses, $manifests, $dependencyIssues, $disabled);

    return collect($bundles)->keyBy('key')->all();
}

it('groups modules under their nearest distribution bundle and falls Base/Core back to platform', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', 'BelimbingApp/belimbing'),
            inventoryBundleStatus('app-Modules-People', 'app/Modules/People', 'BelimbingApp/blb-people'),
            inventoryBundleStatus('extensions-kiat', 'extensions/kiat', 'kiatng/blb-kiat'),
        ],
        [
            inventoryManifest('base/database', 'app/Base/Database', 'blb/base-database'),
            inventoryManifest('core/company', 'app/Modules/Core/Company', 'blb/core-company'),
            inventoryManifest('people/payroll', 'app/Modules/People/Payroll', 'blb/payroll-my'),
            inventoryManifest('people/leave', 'app/Modules/People/Leave', 'blb/people-leave'),
            inventoryManifest('kiat/sample', 'extensions/kiat/Sample', 'kiat/sample'),
        ],
    );

    expect(collect($byKey['app-Modules-People']->modules)->pluck('module')->all())
        ->toBe(['people/leave', 'people/payroll'])
        ->and($byKey['app-Modules-People']->kind)->toBe(InstalledBundle::KIND_BUSINESS_DOMAIN)
        ->and($byKey['app-Modules-People']->lifecycleName)->toBe('People')
        ->and(collect($byKey['platform']->modules)->pluck('module')->all())
        ->toContain('base/database', 'core/company')
        ->and($byKey['platform']->kind)->toBe(InstalledBundle::KIND_PLATFORM)
        ->and(collect($byKey['extensions-kiat']->modules)->pluck('module')->all())->toBe(['kiat/sample'])
        ->and($byKey['extensions-kiat']->kind)->toBe(InstalledBundle::KIND_EXTENSION);
});

it('recognizes a module-level git root as its own slot-implementation bundle', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', 'BelimbingApp/belimbing'),
            inventoryBundleStatus('app-Modules-People', 'app/Modules/People', 'BelimbingApp/blb-people'),
            inventoryBundleStatus('app-Modules-People-Payroll', 'app/Modules/People/Payroll', 'BelimbingApp/blb-payroll-variant'),
        ],
        [
            inventoryManifest('people/payroll', 'app/Modules/People/Payroll', 'blb/payroll-variant'),
            inventoryManifest('people/leave', 'app/Modules/People/Leave', 'blb/people-leave'),
        ],
    );

    expect($byKey['app-Modules-People-Payroll']->kind)->toBe(InstalledBundle::KIND_SLOT_IMPLEMENTATION)
        ->and(collect($byKey['app-Modules-People-Payroll']->modules)->pluck('module')->all())->toBe(['people/payroll'])
        ->and($byKey['app-Modules-People-Payroll']->lifecycleName)->toBeNull()
        ->and(collect($byKey['app-Modules-People']->modules)->pluck('module')->all())->toBe(['people/leave']);
});

it('attaches dependency issues to the bundle that owns the requiring module', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', 'BelimbingApp/belimbing'),
            inventoryBundleStatus('app-Modules-People', 'app/Modules/People', 'BelimbingApp/blb-people'),
        ],
        [
            inventoryManifest('people/payroll', 'app/Modules/People/Payroll', 'blb/payroll-my', ['people/attendance' => '*']),
        ],
        [
            ['issue' => 'missing', 'requiring' => 'blb/payroll-my', 'requiring_module' => 'people/payroll', 'required' => 'people/attendance', 'constraint' => '*'],
        ],
    );

    expect($byKey['app-Modules-People']->hasDependencyIssues())->toBeTrue()
        ->and($byKey['app-Modules-People']->dependencyIssues[0]['required'])->toBe('people/attendance')
        ->and($byKey['platform']->hasDependencyIssues())->toBeFalse();
});

it('marks a disabled business domain bundle', function (): void {
    $byKey = assembleByKey(
        [
            inventoryBundleStatus('platform', '.', 'BelimbingApp/belimbing'),
            inventoryBundleStatus('app-Modules-People', 'app/Modules/People', 'BelimbingApp/blb-people'),
        ],
        [inventoryManifest('people/leave', 'app/Modules/People/Leave', 'blb/people-leave')],
        [],
        ['People'],
    );

    expect($byKey['app-Modules-People']->disabled)->toBeTrue()
        ->and($byKey['platform']->disabled)->toBeFalse();
});

it('reports working-tree dirty and unpushed state per bundle', function (): void {
    $byKey = assembleByKey(
        [inventoryBundleStatus('app-Modules-People', 'app/Modules/People', 'BelimbingApp/blb-people', 'main', ['dirty' => 3, 'ahead' => 2])],
        [inventoryManifest('people/leave', 'app/Modules/People/Leave', 'blb/people-leave')],
    );

    expect($byKey['app-Modules-People']->isDirty())->toBeTrue()
        ->and($byKey['app-Modules-People']->unpushed())->toBe(2)
        ->and($byKey['app-Modules-People']->branch)->toBe('main')
        ->and($byKey['app-Modules-People']->repo)->toBe('BelimbingApp/blb-people');
});
