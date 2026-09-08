<?php

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Base\Foundation\Services\ModuleCheck;
use App\Base\Support\AppPath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * @param  array<string, string>  $requires
 * @param  array{route?: bool, table?: bool, binding?: bool}  $extras
 */
function moduleCheckFixture(string $group, string $module, string $id, array $requires = [], array $extras = []): string
{
    $directory = base_path(ApplicationTopology::relativePathUnder(ApplicationTopology::DOMAINS, $group, $module));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/composer.json', json_encode([
        'name' => 'blb/'.$id,
        'extra' => ['blb' => [
            'module' => $id,
            'version' => '1.0.0',
            'requires-modules' => $requires,
        ]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    $class = AppPath::toClass($directory.'/ServiceProvider.php');
    $namespace = substr($class, 0, (int) strrpos($class, '\\'));
    $binding = ! empty($extras['binding']);
    $registerBody = $binding
        ? '$this->app->singleton(\\'.$namespace.'\\ModuleCheckProbeContract::class, \\'.$namespace.'\\ModuleCheckProbe::class);'
        : '';

    file_put_contents($directory.'/ServiceProvider.php', <<<PHP
<?php
namespace {$namespace};
class ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {
    public function register(): void { {$registerBody} }
}
PHP);

    if ($binding) {
        file_put_contents($directory.'/ModuleCheckProbeContract.php', "<?php\nnamespace {$namespace};\ninterface ModuleCheckProbeContract {}\n");
        file_put_contents($directory.'/ModuleCheckProbe.php', "<?php\nnamespace {$namespace};\nclass ModuleCheckProbe implements ModuleCheckProbeContract {}\n");
    }

    if (! empty($extras['route'])) {
        File::ensureDirectoryExists($directory.'/Routes');
        file_put_contents($directory.'/Routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('module-check-probe', fn () => 'ok')->name('module-check.probe');
PHP);
    }

    if (! empty($extras['table'])) {
        File::ensureDirectoryExists($directory.'/Database/Migrations');
        file_put_contents(
            $directory.'/Database/Migrations/3999_01_01_000000_create_module_check_probe_rows_table.php',
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('module_check_probe_rows', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP
        );
    }

    return $id;
}

beforeEach(function (): void {
    $this->moduleCheckGroup = 'ZzModuleCheck'.bin2hex(random_bytes(6));
    $this->moduleCheckState = storage_path('framework/testing/'.$this->moduleCheckGroup.'.json');
    DomainState::useStatePath($this->moduleCheckState);
});

afterEach(function (): void {
    DomainState::useStatePath(null);
    File::delete($this->moduleCheckState);
    File::deleteDirectory(ApplicationTopology::domainPath($this->moduleCheckGroup));
});

test('an unmet required module is refused before a successful report', function (): void {
    moduleCheckFixture($this->moduleCheckGroup, 'Dependent', 'check/dependent', ['check/missing' => '*']);

    $exit = Artisan::call('blb:module-check', ['module' => 'check/dependent']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toBe(<<<'TXT'
module: check/dependent
composition:
  - check/dependent
refusals:
  - dependency: check/dependent requires check/missing (missing; constraint *)
routes:
  (none)
tables:
  (none)
bindings:
  (none)
status: refused

TXT);
});

test('the workflow command invocation exits one for an unmet dependency', function (): void {
    moduleCheckFixture($this->moduleCheckGroup, 'Dependent', 'check/dependent', ['check/missing' => '*']);

    $process = new Process([PHP_BINARY, 'artisan', 'blb:module-check', 'check/dependent'], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())
        ->toContain('check/dependent requires check/missing');
});

test('a module with its dependency reports routes tables and resolved bindings', function (): void {
    moduleCheckFixture($this->moduleCheckGroup, 'Dependency', 'check/dependency');
    moduleCheckFixture(
        $this->moduleCheckGroup,
        'Dependent',
        'check/dependent',
        ['check/dependency' => '*'],
        ['route' => true, 'table' => true, 'binding' => true],
    );

    // Register the fixture provider so the binding resolves in this process.
    $provider = AppPath::toClass(
        base_path(ApplicationTopology::relativePathUnder(
            ApplicationTopology::DOMAINS,
            $this->moduleCheckGroup,
            'Dependent',
        ).'/ServiceProvider.php'),
    );
    $this->app->register($provider);

    $exit = Artisan::call('blb:module-check', ['module' => 'check/dependent']);
    $output = Artisan::output();
    $probe = AppPath::toClass(
        base_path(ApplicationTopology::relativePathUnder(
            ApplicationTopology::DOMAINS,
            $this->moduleCheckGroup,
            'Dependent',
        ).'/ModuleCheckProbeContract.php'),
    );

    expect($exit)->toBe(0)
        ->and($output)->toBe(<<<TXT
module: check/dependent
composition:
  - check/dependency
  - check/dependent
refusals:
  (none)
routes:
  - check/dependent: GET /module-check-probe name=module-check.probe
tables:
  - check/dependent: module_check_probe_rows
bindings:
  - {$probe} [resolved]
status: ok

TXT);
});

test('json mode mirrors the structured report', function (): void {
    moduleCheckFixture($this->moduleCheckGroup, 'Dependent', 'check/dependent', ['check/missing' => '*']);

    Artisan::call('blb:module-check', ['module' => 'check/dependent', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'module' => 'check/dependent',
        'composition' => ['check/dependent'],
        'ok' => false,
    ])->and($payload['refusals'])->toBe([
        'dependency: check/dependent requires check/missing (missing; constraint *)',
    ]);
});

test('an unknown module id is refused', function (): void {
    $report = app(ModuleCheck::class)->inspect('check/does-not-exist');

    expect($report['ok'])->toBeFalse()
        ->and($report['refusals'])->toBe(['unknown module: check/does-not-exist']);
});

/** @param list<string> $capabilities */
function moduleCheckCapabilities(string $group, string $module, string $id, array $capabilities): void
{
    moduleCheckFixture($group, $module, $id);
    $directory = ApplicationTopology::domainPath($group).'/'.$module.'/Config';
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/authz.php', '<?php return '.var_export(['capabilities' => $capabilities], true).';');
    config([
        'authz.domains.fixture' => [],
        'authz.capabilities' => [...config('authz.capabilities', []), ...$capabilities],
    ]);
}

test('module smoke refuses its rejected capabilities without listing accepted keys', function (): void {
    moduleCheckCapabilities($this->moduleCheckGroup, 'Probe', 'check/probe', ['fixture.thing.view', 'fixture.thing.hod']);

    $exit = Artisan::call('blb:module-check', ['module' => 'check/probe']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Capabilities:', 'accepted: 1', 'rejected: 1', 'fixture.thing.hod', 'unknown verb [hod]')
        ->not->toContain('fixture.thing.view');
});

test('module capability JSON accepts grammatical declarations and excludes other modules without reading tenant data', function (): void {
    moduleCheckCapabilities($this->moduleCheckGroup, 'Probe', 'check/probe', ['fixture.thing.view']);
    moduleCheckCapabilities($this->moduleCheckGroup, 'Other', 'check/other', ['fixture.other.hod']);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $exit = Artisan::call('blb:module-check', ['module' => 'check/probe', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($exit)->toBe(0)
        ->and($payload['capabilities'])->toBe(['accepted' => ['fixture.thing.view'], 'rejected' => []])
        ->and($queries)->toBe([]);
});

test('module capability JSON carries the catalog rejection reason', function (): void {
    moduleCheckCapabilities($this->moduleCheckGroup, 'Probe', 'check/probe', ['fixture.thing.hod']);

    $exit = Artisan::call('blb:module-check', ['module' => 'check/probe', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['capabilities'])->toBe(['accepted' => [], 'rejected' => ['fixture.thing.hod' => 'unknown verb [hod]']]);
});

test('a declared binding whose provider is not registered is reported as missing', function (): void {
    moduleCheckFixture($this->moduleCheckGroup, 'Dependent', 'check/dependent', [], ['binding' => true]);
    $probe = AppPath::toClass(
        base_path(ApplicationTopology::relativePathUnder(
            ApplicationTopology::DOMAINS,
            $this->moduleCheckGroup,
            'Dependent',
        ).'/ModuleCheckProbeContract.php'),
    );

    $exit = Artisan::call('blb:module-check', ['module' => 'check/dependent']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('  - '.$probe.' [missing]')
        ->and($output)->not->toContain('[resolved]');
});
