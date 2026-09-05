<?php

use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

function tableOwnershipBoot(array $modules, bool $disabled = false): Process
{
    $suffix = bin2hex(random_bytes(6));
    $root = storage_path('framework/testing/table-boot-'.$suffix);
    $owner = 'ZzTableBoot'.$suffix;
    $fixtures = [app_path('Extensions/'.$owner), app_path('Domains/'.$owner)];
    File::ensureDirectoryExists($root);

    try {
        foreach ($modules as $index => [$type, $module, $table]) {
            $path = app_path($type.'/'.$owner.'/'.$module.'/Database/Migrations');
            File::ensureDirectoryExists($path);
            file_put_contents($path.'/2099_01_01_00000'.$index.'_create.php',
                "<?php\nSchema::create('".$table."', fn () => null);\n");
        }

        $state = $root.'/disabled.json';
        file_put_contents($state, json_encode(['disabled' => $disabled ? [$owner] : []]));
        $script = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
            .DomainState::class.'::useStatePath('.var_export($state, true).');'
            .'$app = require '.var_export(base_path('bootstrap/app.php'), true).';'
            .'$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .'echo "APPLICATION_BOOTED";';

        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    } finally {
        foreach ($fixtures as $fixture) {
            File::deleteDirectory($fixture);
        }
        File::deleteDirectory($root);
    }
}

test('application boot refuses table ownership shared by two modules before migrations', function (string $secondRoot): void {
    $process = tableOwnershipBoot([
        ['Extensions', 'First', 'zz_boot_shared_table'],
        [$secondRoot, 'Second', 'zz_boot_shared_table'],
    ]);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->isSuccessful())->toBeFalse()
        ->and($output)->toContain('Table zz_boot_shared_table is created by more than one module')
        ->toContain('/First/Database/Migrations/2099_01_01_000000_create.php')
        ->toContain('/Second/Database/Migrations/2099_01_01_000001_create.php')
        ->not->toContain('APPLICATION_BOOTED');
})->with(['Extensions', 'Domains']);

test('application boot allows one owner to recreate its table and another to own a distinct table', function (): void {
    $process = tableOwnershipBoot([
        ['Extensions', 'First', 'zz_boot_owned_table'],
        ['Extensions', 'First', 'zz_boot_owned_table'],
        ['Extensions', 'Second', 'zz_boot_other_table'],
    ]);

    expect($process->getOutput().$process->getErrorOutput())->toContain('APPLICATION_BOOTED')
        ->and($process->isSuccessful())->toBeTrue();
});

test('disabled domains do not participate in the boot ownership check', function (): void {
    $process = tableOwnershipBoot([
        ['Extensions', 'First', 'zz_boot_disabled_table'],
        ['Domains', 'Second', 'zz_boot_disabled_table'],
    ], true);

    expect($process->getOutput().$process->getErrorOutput())->toContain('APPLICATION_BOOTED')
        ->and($process->isSuccessful())->toBeTrue();
});
