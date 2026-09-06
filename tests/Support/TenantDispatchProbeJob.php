<?php

namespace Tests\Support;

use App\Base\Tenancy\Contracts\CarriesTenant;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class TenantDispatchProbeJob implements CarriesTenant, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static ?int $observedTenantId = null;

    public function __construct(public readonly int $tenantId) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function handle(): void
    {
        self::$observedTenantId = app(TenantContext::class)->currentTenantId();
    }
}
