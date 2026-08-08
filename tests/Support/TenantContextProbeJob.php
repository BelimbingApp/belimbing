<?php

namespace Tests\Support;

use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Records the tenant context observed while the job executes, so tests can
 * prove queue propagation restored the dispatch-time tenant.
 */
class TenantContextProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static ?int $observedTenantId = null;

    public static function resetProbe(): void
    {
        self::$observedTenantId = null;
    }

    public function handle(): void
    {
        self::$observedTenantId = app(TenantContext::class)->currentTenantId();
    }
}
