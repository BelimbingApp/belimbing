<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<string>
 */
function retiredApplicationTopologyContracts(string $contents): array
{
    $normalizedNamespaceContents = $contents;

    while (str_contains($normalizedNamespaceContents, '\\\\')) {
        $normalizedNamespaceContents = str_replace('\\\\', '\\', $normalizedNamespaceContents);
    }

    $normalizedPathContents = str_replace('\\', '/', $normalizedNamespaceContents);

    return array_keys(array_filter([
        'App\\Modules namespace' => str_contains($normalizedNamespaceContents, 'App\\Modules\\'),
        'app/Modules path' => str_contains($normalizedPathContents, 'app/Modules'),
        'Extensions namespace' => preg_match('/(?<![A-Za-z0-9_\\\\])Extensions\\\\/', $normalizedNamespaceContents) === 1,
        'repository-root extensions path' => preg_match('#(?<!/)extensions/(?:[a-z0-9_{-])#', $normalizedPathContents) === 1,
    ]));
}

it('keeps application code beneath exactly the four accepted roots', function (): void {
    $roots = collect(File::directories(app_path()))
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($roots)->toBe(['Base', 'Core', 'Domains', 'Extensions'])
        ->and(base_path('extensions'))->not->toBeDirectory();
});

it('rejects retired topology references from active code tests and tooling', function (): void {
    $scanRoots = array_values(array_filter([
        app_path(),
        base_path('bootstrap'),
        config_path(),
        database_path(),
        resource_path(),
        base_path('routes'),
        base_path('scripts'),
        base_path('tests'),
        base_path('.github'),
        base_path('.agents'),
    ], 'is_dir'));

    $finder = (new Finder)
        ->files()
        ->in($scanRoots)
        ->ignoreVCS(true)
        ->exclude(['vendor', 'node_modules', 'storage', 'cache'])
        ->name(['*.php', '*.json', '*.js', '*.ts', '*.css', '*.xml', '*.yml', '*.yaml', '*.md', '*.sh', '*.ps1', 'setup']);

    // These files are the bounded compatibility and forward-normalization
    // boundary for identities persisted before ADR 0001.
    $compatibilityBoundaries = [
        'app/Base/Foundation/Compatibility/LegacyApplicationClassMap.php',
        'app/Base/Foundation/Database/Migrations/2026_08_05_000000_normalize_four_root_application_topology.php',
        'app/Base/Foundation/bootstrap/autoload_legacy_application_classes.php',
        'tests/Feature/Database/SeederRegistryTopologyCompatibilityTest.php',
        'tests/Feature/Foundation/FourRootApplicationTopologyMigrationTest.php',
        'tests/Unit/Base/Foundation/ApplicationTopologyTest.php',
        'tests/Unit/Base/Foundation/ApplicationTopologyGuardTest.php',
    ];

    // Stable migrations cannot be rewritten after landing. These exact
    // pre-cutover files were audited and may retain retired provenance needed
    // to replay their original behavior. New migrations are never exempted.
    $immutableMigrationProvenance = [
        'app/Base/Database/Database/Migrations/0001_01_01_000001_create_base_database_seeders_table.php',
        'app/Core/Company/Database/Migrations/0200_01_07_000001_create_company_relationship_types_table.php',
        'app/Core/Company/Database/Migrations/0200_01_07_000004_create_company_legal_entity_types_table.php',
        'app/Core/Company/Database/Migrations/0200_01_07_001000_create_company_department_types_table.php',
        'app/Core/Employee/Database/Migrations/0200_01_09_000002_create_employee_types_table.php',
        'app/Core/Geonames/Database/Migrations/0200_01_03_000000_create_geonames_countries_table.php',
        'app/Core/Geonames/Database/Migrations/0200_01_03_000001_create_geonames_admin1_table.php',
        'app/Core/Geonames/Database/Migrations/0200_01_03_000003_create_geonames_cities_table.php',
        'app/Domains/Operation/IT/Database/Migrations/0300_01_01_000000_create_operation_it_tickets_table.php',
        'app/Domains/Operation/Quality/Database/Migrations/0300_01_03_000000_create_operation_quality_ncrs_table.php',
        'app/Domains/Operation/Quality/Database/Migrations/0300_01_03_000002_create_operation_quality_scars_table.php',
        'app/Domains/People/Leave/Database/Migrations/0320_02_01_000001_seed_leave_application_workflow.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_06_23_000000_create_kiat_investment_research_tables.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_06_26_000001_create_kiat_investment_dividends.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_06_28_000001_create_kiat_investment_portfolio_entries.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_08_000001_create_kiat_investment_agent_tables.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_12_000002_make_investment_agent_tasks_data_only.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_13_000008_unregister_investment_sample_seeder.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_14_000002_create_autonomous_research_committee.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_15_000001_seed_investment_company_research_workflow.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_15_000003_simplify_company_research_flow_to_coverage.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_15_000004_create_research_cycles_and_annual_report_roles.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_15_000005_repair_corrupted_research_cycle_fiscal_years.php',
        'app/Extensions/Kiat/Investment/Database/Migrations/2026_07_16_000001_enable_cyclical_earnings_valuation.php',
        'app/Extensions/SbGroup/Ibp/Database/Migrations/2026_05_23_000000_create_sbg_ibp_formula_versions_table.php',
        'app/Extensions/SbGroup/Ibp/Database/Migrations/2026_05_23_000008_create_sbg_ibp_monthly_material_snapshots_table.php',
        'app/Extensions/SbGroup/Ibp/Database/Migrations/2026_05_23_000014_create_sbg_ibp_weekly_ba_balances_table.php',
    ];

    $allowed = array_fill_keys([
        ...$compatibilityBoundaries,
        ...$immutableMigrationProvenance,
    ], true);
    $violations = [];

    foreach ($immutableMigrationProvenance as $relative) {
        $migration = base_path($relative);

        if (! File::exists($migration)) {
            continue;
        }

        $contents = File::get($migration);

        if (str_contains($contents, 'IncubatingSchema')) {
            $violations[] = $relative.' (incubating migration cannot retain retired topology references)';
        }

        if (retiredApplicationTopologyContracts($contents) === []) {
            $violations[] = $relative.' (stale immutable-migration exemption)';
        }
    }

    foreach ($finder as $file) {
        $path = str_replace('\\', '/', $file->getRealPath());
        $relative = str_replace(str_replace('\\', '/', base_path()).'/', '', $path);

        if (isset($allowed[$relative])) {
            continue;
        }

        $contents = $file->getContents();

        foreach (retiredApplicationTopologyContracts($contents) as $contract) {
            $violations[] = $relative.' ('.$contract.')';
        }
    }

    foreach ([
        'composer.json',
        'phpunit.xml',
        'vite.config.js',
        'AGENTS.md',
        'README.md',
    ] as $relative) {
        $contents = File::get(base_path($relative));

        if (str_contains($contents, 'App\\Modules\\') || str_contains($contents, 'app/Modules')) {
            $violations[] = $relative.' (retired namespace or path)';
        }
    }

    expect($violations)->toBe([]);
});

it('recognizes retired topology references inside executable migration forms', function (string $source, string $contract): void {
    expect(retiredApplicationTopologyContracts($source))->toContain($contract);
})->with([
    'imported class' => [
        '<?php use App\\Modules\\Core\\Company\\Database\\Seeders\\CompanySeeder;',
        'App\\Modules namespace',
    ],
    'escaped class string' => [
        "<?php return 'App\\\\Modules\\\\People\\\\Payroll\\\\PayrollSeeder';",
        'App\\Modules namespace',
    ],
    'old application path' => [
        "<?php return base_path('app/Modules/People/Payroll');",
        'app/Modules path',
    ],
    'extension import' => [
        '<?php use Extensions\\SbGroup\\Ibp\\Database\\Seeders\\IbpSeeder;',
        'Extensions namespace',
    ],
    'escaped extension class string' => [
        "<?php return 'Extensions\\\\SbGroup\\\\Ibp\\\\Database\\\\Seeders\\\\IbpSeeder';",
        'Extensions namespace',
    ],
    'old extension checkout path' => [
        "<?php return base_path('extensions/sb-group/ibp');",
        'repository-root extensions path',
    ],
]);
