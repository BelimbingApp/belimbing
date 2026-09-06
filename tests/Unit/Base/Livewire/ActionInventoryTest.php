<?php

use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

test('strict action names refuse equal-count substitutions and require an identity snapshot', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzStrict'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example');
    $baseline = storage_path('framework/testing/strict-'.$suffix.'.json');
    File::ensureDirectoryExists($directory.'/Livewire');
    File::ensureDirectoryExists($directory.'/Tests');
    File::ensureDirectoryExists(dirname($baseline));
    try {
        file_put_contents($directory.'/Livewire/Old.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire; class Old extends \\Livewire\\Component { public function old'.$suffix.'() {} public function render() {} }');
        Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--write-baseline' => $baseline]);
        $arguments = ['--domain' => $domain, '--check-baseline' => $baseline];
        expect(Artisan::call('blb:livewire-actions', $arguments + ['--strict-names' => true]))->toBe(0);
        file_put_contents($directory.'/Tests/ReferenceTest.php', '<?php // old'.$suffix);
        expect(Artisan::call('blb:livewire-actions', $arguments + ['--strict-names' => true]))->toBe(1);
        file_put_contents($directory.'/Livewire/Added.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire; class Added extends \\Livewire\\Component { public function added'.$suffix.'() {} public function render() {} }');
        $new = 'App\\Domains\\'.$domain.'\\Example\\Livewire\\Added::added'.$suffix;
        expect(Artisan::call('blb:livewire-actions', $arguments))->toBe(0);
        expect(Artisan::call('blb:livewire-actions', $arguments + ['--strict-names' => true]))->toBe(1)
            ->and(Artisan::output())->toContain($new, 'names changed');
        $snapshot = json_decode(file_get_contents($baseline), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['strict'] = true;
        file_put_contents($baseline, json_encode($snapshot));
        expect(Artisan::call('blb:livewire-actions', $arguments))->toBe(1)->and(Artisan::output())->toContain($new);
        unset($snapshot['actions']);
        file_put_contents($baseline, json_encode($snapshot));
        expect(Artisan::call('blb:livewire-actions', $arguments + ['--strict-names' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('--write-baseline');
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--write-baseline' => $baseline, '--strict-names' => true]))->toBe(0);
        expect(json_decode(file_get_contents($baseline), true)['strict'])->toBeTrue();
        expect(Artisan::call('blb:livewire-actions', $arguments))->toBe(0);
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
        File::delete($baseline);
    }
});

test('baseline explanations name only newly unreferenced actions and preserve legacy counts', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzExplain'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example/Livewire');
    $baseline = storage_path('framework/testing/explain-'.$suffix.'.json');
    File::ensureDirectoryExists($directory);
    File::ensureDirectoryExists(dirname($baseline));

    try {
        foreach (['Old', 'Added'] as $phase) {
            file_put_contents($directory.'/'.$phase.'.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire;
class '.$phase.' extends \\Livewire\\Component { public function action'.$phase.$suffix.'() {} public function render() {} }');
            if ($phase === 'Old') {
                Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--write-baseline' => $baseline]);
            }
        }
        $old = 'App\\Domains\\'.$domain.'\\Example\\Livewire\\Old::actionOld'.$suffix;
        $new = 'App\\Domains\\'.$domain.'\\Example\\Livewire\\Added::actionAdded'.$suffix;
        $snapshot = json_decode(file_get_contents($baseline), true, flags: JSON_THROW_ON_ERROR);
        expect($snapshot['actions'] ?? null)->toBe([$old]);
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--check-baseline' => $baseline, '--explain' => true]))->toBe(1)
            ->and(Artisan::output())->toContain($new)->not->toContain($old);

        unset($snapshot['actions']);
        file_put_contents($baseline, json_encode($snapshot));
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--check-baseline' => $baseline, '--explain' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('no action list')->not->toContain($new);
        $snapshot['module_owned_unreferenced'] = 2;
        file_put_contents($baseline, json_encode($snapshot));
        expect(Artisan::call('blb:livewire-actions', ['--domain' => $domain, '--check-baseline' => $baseline, '--explain' => true]))->toBe(0);
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
        File::delete($baseline);
    }
});

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

test('livewire action baseline check fails first when an untested module-owned action is added', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzActionBaseline'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example');
    File::ensureDirectoryExists($directory.'/Livewire');
    File::ensureDirectoryExists($directory.'/Tests');
    $baseline = storage_path('framework/testing/livewire-actions-baseline-'.$suffix.'.json');

    try {
        // Distinct class files per phase: Reflection cannot see edits to an
        // already-loaded component class within one PHP process.
        file_put_contents($directory.'/Livewire/Covered.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire;
class Covered extends \Livewire\Component {
    public function covered'.$suffix.'() {}
    public function render() { return "<div></div>"; }
}');
        file_put_contents($directory.'/Tests/ReferenceTest.php', "<?php\ncovered".$suffix."\n");

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--write-baseline' => $baseline,
        ]))->toBe(0);

        $snapshot = json_decode((string) file_get_contents($baseline), true, flags: JSON_THROW_ON_ERROR);
        expect($snapshot)->toMatchArray([
            'domain' => $domain,
            'module_owned_unreferenced' => 0,
        ]);

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--check-baseline' => $baseline,
        ]))->toBe(0);

        file_put_contents($directory.'/Livewire/Debt.php', '<?php namespace App\\Domains\\'.$domain.'\\Example\\Livewire;
class Debt extends \Livewire\Component {
    public function untested'.$suffix.'() {}
    public function render() { return "<div></div>"; }
}');

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--check-baseline' => $baseline,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('Livewire action debt rose');

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--write-baseline' => $baseline,
        ]))->toBe(0);

        $raised = json_decode((string) file_get_contents($baseline), true, flags: JSON_THROW_ON_ERROR);
        expect($raised['module_owned_unreferenced'])->toBe(1);

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--check-baseline' => $baseline,
        ]))->toBe(0)
            ->and(Artisan::output())->toContain('baseline 1');
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
        @unlink($baseline);
    }
});

test('livewire action baseline check refuses a mismatched domain label', function (): void {
    $baseline = storage_path('framework/testing/livewire-actions-baseline-mismatch.json');
    file_put_contents($baseline, json_encode([
        'domain' => 'People',
        'module_owned_unreferenced' => 0,
    ], JSON_THROW_ON_ERROR));

    try {
        $domain = 'ZzMismatch'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists(app_path('Domains/'.$domain));
        DomainState::enable($domain);

        expect(Artisan::call('blb:livewire-actions', [
            '--domain' => $domain,
            '--check-baseline' => $baseline,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('does not match');
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
        @unlink($baseline);
    }
});
