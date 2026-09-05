<?php

use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

test('action inventory reports real fixture methods and lexical test references without invoking components', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzActionInventory'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example');
    $class = 'App\\Domains\\'.$domain.'\\Example\\Livewire\\Index';
    $called = 'referenced'.$suffix;
    $uncalled = 'unreferenced'.$suffix;
    $shared = 'shared'.$suffix;
    $sharedDirectory = app_path('Domains/'.$domain.'/Shared/Concerns');
    File::ensureDirectoryExists($sharedDirectory);
    File::ensureDirectoryExists($directory.'/Livewire');
    File::ensureDirectoryExists($directory.'/Tests');

    try {
        file_put_contents($sharedDirectory.'/InventoryActions.php', '<?php namespace App\\Domains\\'.$domain.'\\Shared\\Concerns;
trait InventoryActions {
    public function '.$shared.'() {}
    public function mountInventoryActions() {}
}');
        file_put_contents($directory.'/Livewire/Index.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire;
class Index extends \Livewire\Component {
    use \App\Domains\\'.$domain.'\\Shared\Concerns\InventoryActions;
    public function __construct() { throw new \LogicException("Inventory must not instantiate components"); }
    public function '.$called.'() {}
    public function '.$uncalled.'() {}
    public function mount() {}
    public function updatedName() {}
    public function render() {}
    #[\Livewire\Attributes\Computed] public function displayValue() {}
    public static function staticHelper() {}
    protected function hidden() {}
}');
        file_put_contents($directory.'/Tests/ReferenceTest.php', "<?php\n// ".$called."\n// ".$uncalled."Suffix\n");

        $status = Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--json' => true]);
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $local = array_values(array_filter($rows, fn (array $row): bool => $row['module_owned']));

        expect($status)->toBe(0)
            ->and(array_column($rows, 'method'))->toBe([$called, $shared, $uncalled])
            ->and($rows[1]['module_owned'])->toBeFalse()
            ->and(array_column($local, 'method'))->toBe([$called, $uncalled])
            ->and(array_column($local, 'component'))->toBe([$class, $class])
            ->and(array_column($local, 'referenced_in_tests'))->toBe([true, false])
            ->and($local[0]['test_files'])->toContain('app/Domains/'.$domain.'/Example/Tests/ReferenceTest.php')
            ->and($local[1]['test_files'])->toBe([]);

        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain]))->toBe(0)
            ->and(Artisan::output())->toContain('Test reference', $called, $uncalled);
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
    }
});

test('a mistyped domain cannot silently look like an empty action inventory', function (): void {
    expect(Artisan::call('blb:livewire-actions', ['--domain' => 'NotInstalledInventoryDomain', '--json' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Unknown or disabled Domain');
});

test('disabled domains are refused and an enabled domain without components reports an empty inventory', function (): void {
    $domain = 'ZzEmptyInventory'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists(app_path('Domains/'.$domain));

    try {
        DomainState::disable($domain);
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--json' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Unknown or disabled Domain');

        DomainState::enable($domain);
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
    } finally {
        DomainState::enable($domain);
        File::deleteDirectory(app_path('Domains/'.$domain));
    }
});
