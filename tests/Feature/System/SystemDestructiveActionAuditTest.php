<?php

use App\Base\Audit\Livewire\AuditLog\OperatorActivity;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Cache\Livewire\CacheManagement\Index as CacheManagementIndex;
use App\Base\Log\Livewire\Logs\Show as LogsShow;
use App\Base\Queue\Livewire\FailedJobs\Index as FailedJobsIndex;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

function systemAuditFlush(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return list<object> */
function systemAuditEvents(string $event): array
{
    systemAuditFlush();

    return DB::table('base_audit_actions')->where('event', $event)->orderBy('id')->get()->all();
}

it('records system.cache.flushed when flushAll runs with the manage capability', function (): void {
    Livewire::actingAs(createAdminUser())
        ->test(CacheManagementIndex::class)
        ->call('flushAll');

    $rows = systemAuditEvents('system.cache.flushed');
    expect($rows)->toHaveCount(1);
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($payload['semantic'] ?? null)->toBeTrue()
        ->and($payload['surface'] ?? null)->toBe('admin.system.cache')
        ->and($payload['result'] ?? null)->toBe('succeeded');
});

it('records nothing when flushAll is refused for lack of capability', function (): void {
    setupAuthzRoles();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CacheManagementIndex::class)
        ->call('flushAll');

    expect(systemAuditEvents('system.cache.flushed'))->toBe([]);
});

it('records queue.failed_job.deleted with the job uuid when deleteJob runs', function (): void {
    $user = createAdminUser();
    $uuid = (string) Str::uuid();
    $id = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'OrdinaryFailedJob']),
        'exception' => 'Ordinary queue failure',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('deleteJob', $id);

    expect(DB::table('failed_jobs')->where('id', $id)->exists())->toBeFalse();

    $rows = systemAuditEvents('queue.failed_job.deleted');
    expect($rows)->toHaveCount(1);
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($payload['subject']['identifier'] ?? null)->toBe($uuid)
        ->and($payload['context']['uuid'] ?? null)->toBe($uuid)
        ->and($payload['surface'] ?? null)->toBe('admin.system.failed-jobs');
});

it('records system.log.truncated with the line count removed', function (): void {
    $user = createAdminUser();
    $filename = 'system-audit-truncate-'.bin2hex(random_bytes(4)).'.log';
    $path = storage_path('logs/'.$filename);
    File::put($path, "one\ntwo\nthree\nfour\n");

    try {
        Livewire::actingAs($user)
            ->test(LogsShow::class, ['filename' => $filename])
            ->set('deleteLines', 2)
            ->call('deleteLinesFromTop');

        $rows = systemAuditEvents('system.log.truncated');
        expect($rows)->toHaveCount(1);
        $payload = json_decode((string) $rows[0]->payload, true);
        expect($payload['subject']['identifier'] ?? null)->toBe($filename)
            ->and($payload['context']['lines_removed'] ?? null)->toBe(2)
            ->and($payload['surface'] ?? null)->toBe('admin.system.logs');
        expect(File::get($path))->toBe("three\nfour\n");
    } finally {
        @unlink($path);
    }
});

it('records system.log.deleted with the filename and byte count before redirecting', function (): void {
    $user = createAdminUser();
    $filename = 'system-audit-delete-'.bin2hex(random_bytes(4)).'.log';
    $path = storage_path('logs/'.$filename);
    $contents = "delete-me\n";
    File::put($path, $contents);
    $bytes = strlen($contents);

    try {
        Livewire::actingAs($user)
            ->test(LogsShow::class, ['filename' => $filename])
            ->call('deleteFile')
            ->assertRedirect(route('admin.system.logs.index'));

        expect(File::exists($path))->toBeFalse();

        $rows = systemAuditEvents('system.log.deleted');
        expect($rows)->toHaveCount(1);
        $payload = json_decode((string) $rows[0]->payload, true);
        expect($payload['subject']['identifier'] ?? null)->toBe($filename)
            ->and($payload['context']['bytes'] ?? null)->toBe($bytes)
            ->and($payload['context']['filename'] ?? null)->toBe($filename);
    } finally {
        @unlink($path);
    }
});

it('leaves the file and writes no system.log.deleted row when File::delete returns false', function (): void {
    $user = createAdminUser();
    $filename = 'system-audit-delete-fail-'.bin2hex(random_bytes(4)).'.log';
    $path = storage_path('logs/'.$filename);
    File::put($path, "still-here\n");

    try {
        File::partialMock()
            ->shouldReceive('delete')
            ->once()
            ->with($path)
            ->andReturn(false);

        Livewire::actingAs($user)
            ->test(LogsShow::class, ['filename' => $filename])
            ->call('deleteFile')
            ->assertRedirect(route('admin.system.logs.index'))
            ->assertSessionHas('error');

        expect(File::exists($path))->toBeTrue();
        expect(systemAuditEvents('system.log.deleted'))->toBe([]);
    } finally {
        File::swap(new Filesystem);
        @unlink($path);
    }
});

it('shows a failed-job deletion on Operator Activity under the ambient tenant and hides it from another tenant', function (): void {
    $user = createAdminUser();
    $homeTenant = app(TenantContext::class)->requireTenantId();
    $uuid = (string) Str::uuid();
    $id = (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'OpActFailedJob']),
        'exception' => 'op-act failure',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('deleteJob', $id);
    systemAuditFlush();

    expect(DB::table('base_audit_actions')->where('event', 'queue.failed_job.deleted')->where('tenant_id', $homeTenant)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertSee('queue.failed_job.deleted');

    [$foreignTenant] = createTenantWithCompany(['name' => 'Foreign System Audit Tenant']);
    app(TenantContext::class)->set((int) $foreignTenant->id);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertDontSee('queue.failed_job.deleted');
});
