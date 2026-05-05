<?php

use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('migrate command reports orphaned registry entries removed during reconciliation', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'ghost_registry_entry',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000001_create_ghost_registry_entry.php',
        'is_stable' => true,
        'stabilized_at' => now(),
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
