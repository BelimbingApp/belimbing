<?php

namespace Tests\Support;

use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Counts its own executions so a test can prove `handle()` never ran for a
 * job stamped with a suspended tenant. A null tenant observation is not
 * enough on its own: a job that did not run and a job that ran without a
 * tenant would look identical.
 */
class TenantSuspensionProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static int $runs = 0;

    public static ?int $observedTenantId = null;

    public static function resetProbe(): void
    {
        self::$runs = 0;
        self::$observedTenantId = null;
    }

    public function handle(): void
    {
        self::$runs++;
        self::$observedTenantId = app(TenantContext::class)->currentTenantId();
    }
}
