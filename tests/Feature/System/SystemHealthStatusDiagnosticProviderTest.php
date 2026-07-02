<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Services\SystemHealthProbe;
use App\Base\System\Services\SystemHealthStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

function fakeSystemHealthProbe(array $unwritablePaths, array $writablePaths = []): void
{
    $probe = Mockery::mock(SystemHealthProbe::class);
    $probe->shouldReceive('unwritablePaths')->andReturn($unwritablePaths);
    $probe->shouldReceive('writablePaths')->andReturn($writablePaths ?: $unwritablePaths);

    app()->instance(SystemHealthProbe::class, $probe);
}

it('reports unwritable runtime paths for users who can view system info', function (): void {
    fakeSystemHealthProbe([
        [
            'key' => 'storage.logs',
            'label' => 'storage/logs',
            'path' => '/srv/app/storage/logs',
            'exists' => true,
            'writable' => false,
        ],
    ]);

    $diagnostics = collect(app(SystemHealthStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('system.filesystem-unwritable')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->summary)->toBe('1 required filesystem path is not writable')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.info.index'))
        ->and($diagnostics[0]->metadata)->toMatchArray([
            'paths' => ['storage/logs'],
        ]);
});

it('clears the system health diagnostic when all runtime paths are writable', function (): void {
    fakeSystemHealthProbe([]);

    expect(collect(app(SystemHealthStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('hides system health diagnostics from users without system info access', function (): void {
    $probe = Mockery::mock(SystemHealthProbe::class);
    $probe->shouldNotReceive('unwritablePaths');
    app()->instance(SystemHealthProbe::class, $probe);

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(SystemHealthStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('surfaces system health diagnostics through the status bar aggregator', function (): void {
    fakeSystemHealthProbe([
        [
            'key' => 'storage.logs',
            'label' => 'storage/logs',
            'path' => '/srv/app/storage/logs',
            'exists' => true,
            'writable' => false,
        ],
    ]);

    $response = $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 required filesystem path is not writable')
        ->assertSee('href="'.route('admin.system.info.index').'"', false)
        ->assertSee('storage/logs');
});
