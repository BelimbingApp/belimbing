<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Queue\Services\QueueStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reports failed jobs to users who can inspect the queue', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'ExampleJob']),
        'exception' => 'Example exception',
        'failed_at' => now(),
    ]);

    $diagnostics = collect(app(QueueStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('queue.failed-jobs')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Warning)
        ->and($diagnostics[0]->summary)->toBe('1 failed job needs attention')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.failed-jobs.index'))
        ->and($diagnostics[0]->metadata)->toMatchArray(['failed_jobs' => 1]);
});

it('reports high recent queue failure rate as danger', function (): void {
    Cache::put('queue_failures', 11, now()->addHour());

    $diagnostics = collect(app(QueueStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('queue.high-failure-rate')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->metadata)->toMatchArray([
            'recent_failures' => 11,
            'threshold' => 10,
        ]);
});

it('hides queue diagnostics from users without failed job access', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'ExampleJob']),
        'exception' => 'Example exception',
        'failed_at' => now(),
    ]);

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(QueueStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('surfaces queue diagnostics through the status bar aggregator', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'ExampleJob']),
        'exception' => 'Example exception',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 failed job needs attention')
        ->assertSee('href="'.route('admin.system.failed-jobs.index').'"', false);
});
