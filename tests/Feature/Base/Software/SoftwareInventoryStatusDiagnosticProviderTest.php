<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Software\Inventory\InstalledBundle;
use App\Base\Software\Services\SoftwareInventoryService;
use App\Base\Software\Services\SoftwareInventoryStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

function softwareDiagnosticBundle(
    string $key,
    string $label,
    string $kind = InstalledBundle::KIND_BUSINESS_DOMAIN,
    array $workingTree = [],
    array $dependencyIssues = [],
): InstalledBundle {
    return new InstalledBundle(
        key: $key,
        label: $label,
        kind: $kind,
        path: $key,
        hasGit: true,
        repo: 'BelimbingApp/'.$key,
        branch: 'main',
        commit: ['sha' => str_repeat('a', 40), 'short' => 'aaaaaaa'],
        workingTree: array_merge(['dirty' => 0, 'ahead' => 0, 'behind' => 0], $workingTree),
        disabled: false,
        modules: [],
        dependencyIssues: $dependencyIssues,
    );
}

function fakeSoftwareInventory(array $bundles): void
{
    $inventory = Mockery::mock(SoftwareInventoryService::class);
    $inventory->shouldReceive('installedBundlesForStatusDiagnostics')->andReturn($bundles);

    app()->instance(SoftwareInventoryService::class, $inventory);
}

it('reports module dependency issues for users who can view modules', function (): void {
    fakeSoftwareInventory([
        softwareDiagnosticBundle(
            'app-Modules-People',
            'People',
            dependencyIssues: [
                [
                    'issue' => 'missing',
                    'requiring' => 'blb/payroll-my',
                    'requiring_module' => 'people/payroll',
                    'required' => 'people/attendance',
                    'constraint' => '*',
                ],
            ],
        ),
    ]);

    $diagnostics = collect(app(SoftwareInventoryStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.module-dependencies')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->summary)->toBe('1 module dependency issue needs attention')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.software.modules.index'))
        ->and($diagnostics[0]->metadata)->toMatchArray([
            'dependency_issues' => 1,
            'affected_bundles' => ['People'],
        ]);
});

it('reports dirty and unpushed add-in bundles as one aggregate warning', function (): void {
    fakeSoftwareInventory([
        softwareDiagnosticBundle('platform', 'Platform', InstalledBundle::KIND_PLATFORM, ['dirty' => 4, 'ahead' => 2]),
        softwareDiagnosticBundle('app-Modules-People', 'People', workingTree: ['dirty' => 3]),
        softwareDiagnosticBundle('extensions-kiat', 'Kiat', InstalledBundle::KIND_EXTENSION, ['ahead' => 2]),
    ]);

    $diagnostics = collect(app(SoftwareInventoryStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.bundle-drift')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Warning)
        ->and($diagnostics[0]->summary)->toBe('2 add-in bundles have local drift')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.software.modules.index').'#add-in-bundle-drift')
        ->and($diagnostics[0]->metadata)->toMatchArray([
            'affected_bundles' => ['People', 'Kiat'],
            'dirty_bundles' => 1,
            'unpushed_commits' => 2,
        ]);
});

it('hides software diagnostics from users without module inventory access', function (): void {
    $inventory = Mockery::mock(SoftwareInventoryService::class);
    $inventory->shouldNotReceive('installedBundlesForStatusDiagnostics');
    app()->instance(SoftwareInventoryService::class, $inventory);

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(SoftwareInventoryStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('surfaces software diagnostics through the status bar aggregator', function (): void {
    fakeSoftwareInventory([
        softwareDiagnosticBundle('app-Modules-People', 'People', workingTree: ['dirty' => 1]),
    ]);

    $response = $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 add-in bundle has local drift')
        ->assertSee('href="'.route('admin.system.software.modules.index').'#add-in-bundle-drift"', false);
});
