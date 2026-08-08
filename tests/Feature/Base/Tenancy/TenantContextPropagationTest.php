<?php

use App\Base\Audit\Services\AuditBuffer;
use App\Base\Media\Services\MediaAssetStore;
use App\Base\Settings\Models\Setting;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Services\TenantStoragePath;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantContextProbeJob;

const TENANT_PROPAGATION_DISK = 'tenant-propagation';

beforeEach(function (): void {
    config()->set('filesystems.disks.'.TENANT_PROPAGATION_DISK, [
        'driver' => 'local',
        'root' => storage_path('framework/testing/tenant-propagation-'.bin2hex(random_bytes(8))),
    ]);
});

afterEach(function (): void {
    Storage::disk(TENANT_PROPAGATION_DISK)->deleteDirectory('');
});

function flushTenantPropagationAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $method = new ReflectionMethod($buffer, 'flush');
    $method->invoke($buffer);
}

it('stamps the dispatch-time tenant onto queued jobs and clears after execution', function (): void {
    TenantContextProbeJob::resetProbe();

    $context = app(TenantContext::class);
    $context->set(7);

    Bus::dispatch(new TenantContextProbeJob);

    expect(TenantContextProbeJob::$observedTenantId)->toBe(7);

    // The job boundary cleared the context it restored: a following job
    // dispatched with no tenant context must observe null, never the
    // previous job's tenant.
    TenantContextProbeJob::resetProbe();
    $context->clear();

    Bus::dispatch(new TenantContextProbeJob);

    expect(TenantContextProbeJob::$observedTenantId)->toBeNull();
});

it('clears the tenant when a job throws but still has attempts left', function (): void {
    // A released job fires neither JobProcessed nor JobFailed. Without a
    // JobExceptionOccurred hook the worker would keep that job's tenant while
    // it waits for the next one.
    $context = app(TenantContext::class);
    $context->set(11);

    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturns(['tenantId' => 11]);

    event(new JobProcessing('database', $job));
    expect($context->currentTenantId())->toBe(11);

    event(new JobExceptionOccurred('database', $job, new RuntimeException('transient')));

    expect($context->currentTenantId())->toBeNull();
});

it('stamps mutation audit entries with the row tenant, then request context', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Audit Tenant']);

    // Row carries its own tenant_id: ground truth wins even with no context.
    app(TenantContext::class)->clear();
    $company->update(['name' => 'Row Tenant Wins']);

    flushTenantPropagationAuditBuffer();

    $row = DB::table('base_audit_mutations')
        ->where('auditable_type', $company->getMorphClass())
        ->where('auditable_id', (string) $company->id)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->tenant_id)->toBe($tenant->id);

    // Model without a tenant_id column: ambient context stamps the entry.
    app(TenantContext::class)->runForTenant($tenant->id, function (): void {
        Setting::query()->updateOrCreate(
            ['key' => 'zz_tenant_probe.option', 'scope_type' => null, 'scope_id' => null],
            ['value' => 'x'],
        );
    });

    flushTenantPropagationAuditBuffer();

    $settingRow = DB::table('base_audit_mutations')
        ->where('auditable_type', (new Setting)->getMorphClass())
        ->latest('id')
        ->first();

    expect($settingRow)->not->toBeNull();
    expect((int) $settingRow->tenant_id)->toBe($tenant->id);
});

it('partitions uploaded media paths by tenant', function (): void {
    $tenant = createTenant(['name' => 'Media Tenant']);

    $asset = app(TenantContext::class)->runForTenant(
        $tenant->id,
        fn () => app(MediaAssetStore::class)->putUploadedFile(
            TENANT_PROPAGATION_DISK,
            'media/originals',
            UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg'),
        ),
    );

    expect($asset->storage_key)->toStartWith("tenants/{$tenant->id}/media/originals/");
    Storage::disk(TENANT_PROPAGATION_DISK)->assertExists($asset->storage_key);

    // No tenant context: caller-supplied directory is unchanged.
    app(TenantContext::class)->clear();

    $unscoped = app(MediaAssetStore::class)->putUploadedFile(
        TENANT_PROPAGATION_DISK,
        'media/originals',
        UploadedFile::fake()->create('platform.jpg', 64, 'image/jpeg'),
    );

    expect($unscoped->storage_key)->toStartWith('media/originals/');
});

it('prefixes storage directories only when a tenant context is active', function (): void {
    $path = app(TenantStoragePath::class);

    app(TenantContext::class)->clear();
    expect($path->prefix('avatars'))->toBe('avatars');

    app(TenantContext::class)->set(3);
    expect($path->prefix('avatars'))->toBe('tenants/3/avatars')
        ->and($path->prefix('/avatars/'))->toBe('tenants/3/avatars');
});
