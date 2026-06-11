<?php

use App\Base\Database\Concerns\ExtractsModuleProvenance;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('migration commands discover extension migration paths and preserve explicit path scopes', function (): void {
    $owner = 'zz-migration-discovery-'.bin2hex(random_bytes(4));
    $relativePath = 'extensions/'.$owner.'/sample/Database/Migrations';
    $extensionPath = base_path($relativePath);

    File::ensureDirectoryExists($extensionPath);

    try {
        $command = app(MigrateCommand::class);
        $command->setLaravel(app());

        (function (): void {
            $this->loadAllModuleMigrations();
        })->call($command);

        $pathsFor = function (array $input): array {
            $consoleInput = new ArrayInput($input, $this->getDefinition());
            $this->input = $consoleInput;

            return $this->getMigrationPaths();
        };

        expect($pathsFor->call($command, []))->toContain($extensionPath)
            ->and($pathsFor->call($command, ['--path' => [$relativePath]]))->toBe([$extensionPath]);
    } finally {
        File::deleteDirectory(base_path('extensions/'.$owner));
    }
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
