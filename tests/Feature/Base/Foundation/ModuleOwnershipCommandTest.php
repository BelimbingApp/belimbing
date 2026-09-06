<?php

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function moduleOwnershipFixture(
    string $group,
    string $module,
    string $id,
    string $table,
    string $routeUri,
    string $routeName,
): void {
    $directory = base_path(ApplicationTopology::relativePathUnder(ApplicationTopology::DOMAINS, $group, $module));
    File::ensureDirectoryExists($directory.'/Database/Migrations');
    File::ensureDirectoryExists($directory.'/Routes');
    File::put($directory.'/composer.json', json_encode([
        'name' => 'blb/'.$id,
        'extra' => ['blb' => ['module' => $id, 'version' => '1.0.0']],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    File::put($directory.'/Database/Migrations/3999_01_01_000000_create_rows_table.php', <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('{$table}', function (Blueprint \$table): void {
            \$table->id();
        });
    }
};
PHP);
    File::put($directory.'/Routes/web.php', <<<PHP
<?php
use Illuminate\Support\Facades\Route;
Route::get('{$routeUri}', fn () => 'ok')->name('{$routeName}');
PHP);
}

beforeEach(function (): void {
    $this->moduleOwnershipGroup = 'ZzModuleOwnership'.bin2hex(random_bytes(6));
    $this->moduleOwnershipState = storage_path('framework/testing/'.$this->moduleOwnershipGroup.'.json');
    DomainState::useStatePath($this->moduleOwnershipState);
});

afterEach(function (): void {
    DomainState::useStatePath(null);
    File::delete($this->moduleOwnershipState);
    File::deleteDirectory(ApplicationTopology::domainPath($this->moduleOwnershipGroup));
});

test('domain module ownership refuses a table created by two modules', function (): void {
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Alpha', 'probe/alpha', 'shared_probe_rows', 'alpha-probe', 'probe.alpha');
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Beta', 'probe/beta', 'shared_probe_rows', 'beta-probe', 'probe.beta');

    $exit = Artisan::call('blb:module-ownership');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('collision: table shared_probe_rows is created by probe/alpha and probe/beta');
});

test('domain module ownership refuses a route name registered by two modules', function (): void {
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Alpha', 'probe/alpha', 'alpha_probe_rows', 'alpha-probe', 'probe.shared');
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Beta', 'probe/beta', 'beta_probe_rows', 'beta-probe', 'probe.shared');

    $exit = Artisan::call('blb:module-ownership');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('collision: route name probe.shared is registered by probe/alpha and probe/beta');
});

test('distinct domain module tables and route names pass with no refusals', function (): void {
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Alpha', 'probe/alpha', 'alpha_probe_rows', 'alpha-probe', 'probe.alpha');
    moduleOwnershipFixture($this->moduleOwnershipGroup, 'Beta', 'probe/beta', 'beta_probe_rows', 'beta-probe', 'probe.beta');

    $exit = Artisan::call('blb:module-ownership');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain("refusals:\n  (none)")
        ->and($output)->toContain('status: ok');
});
