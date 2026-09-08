<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\DomainCommands\ZzTenantScopedProbeCommand;

/**
 * Keeps what CommandListener stamped instead of deferring it to a real INSERT,
 * so a test can read the audit row's tenant.
 */
final class ZzTenantClearAuditBufferSpy extends AuditBuffer
{
    /** @var list<array<string, mixed>> */
    public array $actions = [];

    /**
     * @param  array<string, mixed>  $entry
     */
    public function bufferAction(array $entry): void
    {
        $this->actions[] = $entry;
    }
}

beforeEach(function (): void {
    ZzTenantScopedProbeCommand::reset();
    Artisan::registerCommand(app(ZzTenantScopedProbeCommand::class));
});

afterEach(function (): void {
    ZzTenantScopedProbeCommand::reset();
    app(TenantContext::class)->clear();
});

/**
 * Dispatch what Symfony TERMINATE bridges to in a real `php artisan` run.
 *
 * Kernel::call() — the path `$this->artisan()` takes — never dispatches it:
 * Foundation\Console\Kernel wires the bridge only when ! runningUnitTests().
 */
function zzTenantClearDispatchCommandFinished(): void
{
    event(new CommandFinished('zz:tenant-scoped-probe', new ArrayInput([]), new NullOutput, 0));
}

it('clears the bound tenant from the CommandFinished listener, not only from the unit-test finally', function (): void {
    $tenant = createTenant(['name' => 'Terminate']);

    $this->artisan('zz:tenant-scoped-probe', ['--tenant' => $tenant->id])
        ->assertSuccessful();

    // execute()'s finally has already cleared under Pest. Put back what a real
    // process still holds when TERMINATE fires, so the only thing that can
    // clear it now is a listener execute() registered.
    $stale = RequestContext::forConsole(null, 'zz:tenant-scoped-probe');
    app()->instance(RequestContext::class, $stale);
    app(TenantContext::class)->set($tenant->id);

    zzTenantClearDispatchCommandFinished();

    expect(app(TenantContext::class)->currentTenantId())
        ->toBeNull('CommandFinished did not clear the bound tenant');
    expect(app(RequestContext::class))
        ->not->toBe($stale, 'CommandFinished did not forget the stale audit RequestContext');
});

it('stamps the console.command audit row before it clears, so the row carries the tenant', function (): void {
    $tenant = createTenant(['name' => 'Ordering']);

    $this->artisan('zz:tenant-scoped-probe', ['--tenant' => $tenant->id])
        ->assertSuccessful();

    $buffer = new ZzTenantClearAuditBufferSpy;
    app()->instance(AuditBuffer::class, $buffer);

    // Built while no tenant is bound, so its tenantId is null: CommandListener's
    // `?? $this->context->tenantId` fallback cannot supply the id, and the row
    // carries the tenant only if the clear runs after the stamp.
    app()->instance(RequestContext::class, RequestContext::forConsole(null, 'zz:tenant-scoped-probe'));
    app(TenantContext::class)->set($tenant->id);

    zzTenantClearDispatchCommandFinished();

    expect($buffer->actions)->toHaveCount(1);
    expect($buffer->actions[0]['event'])->toBe('console.command');
    expect($buffer->actions[0]['tenant_id'])
        ->toBe($tenant->id, 'the tenant was cleared before CommandListener stamped the audit row');
    expect(app(TenantContext::class)->currentTenantId())
        ->toBeNull('CommandFinished did not clear the bound tenant');
});
