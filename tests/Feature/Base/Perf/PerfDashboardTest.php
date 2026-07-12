<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->perfDir = storage_path('framework/testing/perf-dash-'.uniqid());
    config()->set('perf.enabled', false);
    config()->set('perf.path', $this->perfDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfDir);
});

function writePerfFixture(string $dir, array $overrides = []): void
{
    File::ensureDirectoryExists($dir);

    $entry = array_merge([
        'ts' => now()->toIso8601String(),
        'method' => 'GET',
        'path' => '/dashboard',
        'route' => 'dashboard',
        'status' => 200,
        'ms' => 432.1,
        'db_ms' => 21.4,
        'queries' => 45,
        'cache_hits' => 15,
        'cache_misses' => 1,
        'cache_writes' => 0,
        'procs' => 0,
        'proc_ms' => 0,
        'resp_bytes' => 1291000,
        'navigate' => false,
        'livewire' => false,
        'mem_mb' => 48.0,
    ], $overrides);

    File::append(
        $dir.DIRECTORY_SEPARATOR.'perf-'.now()->format('Y-m-d').'.jsonl',
        json_encode($entry).PHP_EOL,
    );
}

it('shows the performance dashboard to authorized admins', function (): void {
    writePerfFixture($this->perfDir);
    writePerfFixture($this->perfDir, [
        'route' => 'admin.system.software.modules.index',
        'path' => '/admin/system/software/modules',
        'ms' => 9421.4,
        'procs' => 18,
        'proc_ms' => 8259.7,
    ]);

    $this->actingAs(createAdminUser())
        ->get(route('admin.system.perf.index'))
        ->assertOk()
        ->assertSee('Where the time goes')
        ->assertSee('admin.system.software.modules.index')
        ->assertSee('perf:slowest');
});

it('teaches how to fill the log when the window is empty', function (): void {
    $this->actingAs(createAdminUser())
        ->get(route('admin.system.perf.index'))
        ->assertOk()
        ->assertSee('No requests recorded');
});

it('denies users without the perf capability', function (): void {
    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.perf.index'))
        ->assertForbidden();
});

it('rejects unknown time windows without breaking', function (): void {
    writePerfFixture($this->perfDir);

    Livewire\Livewire::actingAs(createAdminUser())
        ->test(App\Base\Perf\Livewire\Dashboard\Index::class)
        ->call('setWindow', 'bogus')
        ->assertSet('window', '1h')
        ->call('setWindow', '7d')
        ->assertSet('window', '7d');
});
