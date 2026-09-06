<?php

namespace App\Base\Tenancy\Console\Concerns;

use App\Base\Tenancy\Contracts\CarriesTenant;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantJobMismatchException;
use Illuminate\Foundation\Bus\PendingDispatch;

/** Dispatches tenant-aware jobs only for the command's bound tenant. */
// @phpstan-ignore trait.unused (The tenant-scoped command lands separately in #732.)
trait DispatchesWithTenant
{
    protected function dispatchWithTenant(CarriesTenant $job): PendingDispatch
    {
        $commandTenantId = app(TenantContext::class)->requireTenantId();
        $jobTenantId = $job->tenantId();

        if ($jobTenantId !== $commandTenantId) {
            throw new TenantJobMismatchException($commandTenantId, $jobTenantId);
        }

        return dispatch($job);
    }
}
