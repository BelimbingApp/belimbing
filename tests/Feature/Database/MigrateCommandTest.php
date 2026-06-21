<?php

use App\Base\Database\Concerns\ExtractsModuleProvenance;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Models\TableRegistry;
use App\Base\Foundation\ModuleManifest\ModuleManifestException;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const MIGRATION_EXTENSION_ROOT = 'extensions/';
const MIGRATION_REQUIRED_SUFFIX = '/required';
const MIGRATION_DEPENDENT_SUFFIX = '/dependent';
const MIGRATION_MIGRATIONS_SUFFIX = '/Database/Migrations';
const MIGRATION_COMPOSER_JSON = '/composer.json';
const MIGRATION_TEST_VERSION = '1.0.0';
const MIGRATION_MISSING_SUFFIX = '/missing';

afterEach(function (): void {
    $property = new ReflectionProperty(Migrator::class, 'paths');
    $property->setValue(app(Migrator::class), []);
});

function migrationModuleFixture(string $owner, string $suffix, array $blb): string
{
    $moduleName = $owner.$suffix;
    $module = base_path(MIGRATION_EXTENSION_ROOT.$moduleName);

    File::ensureDirectoryExists($module.MIGRATION_MIGRATIONS_SUFFIX);
    file_put_contents($module.MIGRATION_COMPOSER_JSON, json_encode([
        'name' => $moduleName,
        'extra' => ['blb' => ['module' => $moduleName, ...$blb]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $module;
}

function migrationRequiredAndDependentFixtures(string $owner, string $requiredConstraint = '*'): array
{
    $required = migrationModuleFixture($owner, MIGRATION_REQUIRED_SUFFIX, [
        'role' => 'source',
        'version' => MIGRATION_TEST_VERSION,
    ]);
    $dependent = migrationModuleFixture($owner, MIGRATION_DEPENDENT_SUFFIX, [
        'role' => 'plugin',
        'version' => MIGRATION_TEST_VERSION,
        'requires-modules' => [$owner.MIGRATION_REQUIRED_SUFFIX => $requiredConstraint],
    ]);

    return [$required, $dependent];
}

function runMigrationDiscovery(): MigrateCommand
{
    $command = app(MigrateCommand::class);
    $command->setLaravel(app());

    (function (): void {
        $this->loadAllModuleMigrations();
    })->call($command);

    return $command;
}

test('migration commands discover extension migration paths and preserve explicit path scopes', function (): void {
    $owner = 'zz-migration-discovery-'.bin2hex(random_bytes(4));
    $relativePath = MIGRATION_EXTENSION_ROOT.$owner.'/sample'.MIGRATION_MIGRATIONS_SUFFIX;
    $extensionPath = base_path($relativePath);
    $normalize = static fn (string $path): string => str_replace('\\', '/', $path);

    File::ensureDirectoryExists($extensionPath);

    try {
        $command = runMigrationDiscovery();

        $pathsFor = function (array $input): array {
            $consoleInput = new ArrayInput($input, $this->getDefinition());
            $this->input = $consoleInput;

            return $this->getMigrationPaths();
        };

        expect(array_map($normalize, $pathsFor->call($command, [])))->toContain($normalize($extensionPath))
            ->and(array_map($normalize, $pathsFor->call($command, ['--path' => [$relativePath]])))->toBe([$normalize($extensionPath)]);
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }
});

test('migration discovery rejects missing required modules before registration', function (): void {
    $owner = 'zz-migration-dependency-'.bin2hex(random_bytes(4));
    migrationModuleFixture($owner, MIGRATION_DEPENDENT_SUFFIX, [
        'role' => 'plugin',
        'version' => MIGRATION_TEST_VERSION,
        'requires-modules' => [$owner.MIGRATION_MISSING_SUFFIX => '*'],
    ]);

    try {
        runMigrationDiscovery();
    } catch (ModuleManifestException $exception) {
        expect($exception->getMessage())
            ->toContain('Module migration dependency preflight failed')
            ->toContain($owner.MIGRATION_DEPENDENT_SUFFIX)
            ->toContain($owner.MIGRATION_MISSING_SUFFIX);

        return;
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected missing module dependency preflight to fail.');
});

test('migration discovery rejects incompatible required module versions before registration', function (): void {
    $owner = 'zz-migration-version-'.bin2hex(random_bytes(4));
    migrationRequiredAndDependentFixtures($owner, '^2.0.0');

    try {
        runMigrationDiscovery();
    } catch (ModuleManifestException $exception) {
        expect($exception->getMessage())
            ->toContain($owner.MIGRATION_DEPENDENT_SUFFIX)
            ->toContain($owner.MIGRATION_REQUIRED_SUFFIX.' (^2.0.0)')
            ->toContain('installed version is '.MIGRATION_TEST_VERSION);

        return;
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected incompatible module dependency preflight to fail.');
});

test('migration discovery rejects manifest dependency order that filenames cannot honor', function (): void {
    $owner = 'zz-migration-order-'.bin2hex(random_bytes(4));
    [$required, $dependent] = migrationRequiredAndDependentFixtures($owner);

    touch($required.MIGRATION_MIGRATIONS_SUFFIX.'/2026_02_01_000000_create_required_table.php');
    touch($dependent.MIGRATION_MIGRATIONS_SUFFIX.'/2026_01_01_000000_create_dependent_table.php');

    try {
        runMigrationDiscovery();
    } catch (ModuleManifestException $exception) {
        expect($exception->getMessage())
            ->toContain($owner.MIGRATION_DEPENDENT_SUFFIX.' requires '.$owner.MIGRATION_REQUIRED_SUFFIX)
            ->toContain('2026_01_01_000000_create_dependent_table')
            ->toContain('2026_02_01_000000_create_required_table');

        return;
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected migration filename ordering preflight to fail.');
});

test('migration dependency ordering ignores helper php files Laravel would not migrate', function (): void {
    $owner = 'zz-migration-helper-'.bin2hex(random_bytes(4));
    [$required, $dependent] = migrationRequiredAndDependentFixtures($owner);

    touch($required.MIGRATION_MIGRATIONS_SUFFIX.'/helper.php');
    touch($dependent.MIGRATION_MIGRATIONS_SUFFIX.'/2026_01_01_000000_create_dependent_table.php');

    try {
        $command = runMigrationDiscovery();

        $paths = (function (): array {
            return $this->migrator->paths();
        })->call($command);

        expect($paths)->toContain($dependent.MIGRATION_MIGRATIONS_SUFFIX);
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }
});

test('migration discovery rejects duplicate migration names before Laravel collapses them', function (): void {
    $owner = 'zz-migration-duplicate-'.bin2hex(random_bytes(4));
    $first = base_path(MIGRATION_EXTENSION_ROOT.$owner.'/first');
    $second = base_path(MIGRATION_EXTENSION_ROOT.$owner.'/second');

    foreach ([$first, $second] as $module) {
        File::ensureDirectoryExists($module.MIGRATION_MIGRATIONS_SUFFIX);
    }

    touch($first.MIGRATION_MIGRATIONS_SUFFIX.'/2026_01_01_000000_create_duplicate_table.php');
    touch($second.MIGRATION_MIGRATIONS_SUFFIX.'/2026_01_01_000000_create_duplicate_table.php');

    try {
        runMigrationDiscovery();
    } catch (ModuleManifestException $exception) {
        expect($exception->getMessage())
            ->toContain('Duplicate migration name 2026_01_01_000000_create_duplicate_table')
            ->toContain($first)
            ->toContain($second);

        return;
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected duplicate migration name preflight to fail.');
});

test('explicit path scopes do not bypass module dependency preflight', function (): void {
    $owner = 'zz-migration-explicit-path-'.bin2hex(random_bytes(4));
    migrationModuleFixture($owner, MIGRATION_DEPENDENT_SUFFIX, [
        'role' => 'plugin',
        'version' => MIGRATION_TEST_VERSION,
        'requires-modules' => [$owner.MIGRATION_MISSING_SUFFIX => '*'],
    ]);

    try {
        $command = app(MigrateCommand::class);
        $command->setLaravel(app());
        $command->setInput(new ArrayInput(['--path' => ['database/migrations']], $command->getDefinition()));

        (function (): void {
            $this->loadAllModuleMigrations();
        })->call($command);
    } catch (ModuleManifestException $exception) {
        expect($exception->getMessage())->toContain($owner.MIGRATION_MISSING_SUFFIX);

        return;
    } finally {
        File::deleteDirectory(base_path(MIGRATION_EXTENSION_ROOT.$owner));
    }

    expect(false)->toBeTrue('Expected explicit --path migration to still run module dependency preflight.');
});

test('extension migration provenance resolves to the extension module path', function (): void {
    $probe = new class
    {
        use ExtractsModuleProvenance;

        public function pathFor(string $migrationPath): ?string
        {
            return $this->extractModulePath($migrationPath);
        }

        public function nameFor(?string $modulePath): ?string
        {
            return $this->extractModuleName($modulePath);
        }
    };

    $modulePath = $probe->pathFor(base_path('extensions/sb-group/ibp/Database/Migrations/2026_01_01_000000_create_table.php'));

    expect($modulePath)->toBe('extensions/sb-group/ibp')
        ->and($probe->nameFor($modulePath))->toBe('ibp');
});

test('migrate command reports orphaned registry entries removed during reconciliation', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'ghost_registry_entry',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000001_create_ghost_registry_entry.php',
    ]);

    $command = app(MigrateCommand::class);
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    $method = new ReflectionMethod($command, 'reportRemovedRegistryEntries');
    $method->invoke($command, ['ghost_registry_entry']);

    expect($output->fetch())
        ->toContain('Removed 1 orphaned table registry entry that no longer matches any declared or live relation.')
        ->toContain('ghost_registry_entry');
});
