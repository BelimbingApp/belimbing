<?php

namespace App\Base\Database\Livewire\Concerns;

use App\Base\Tenancy\Services\PlatformOperatorTenantAccess;

/**
 * Re-check the structural database-console boundary on every Livewire request.
 */
trait RequiresPlatformOperatorTenant
{
    public function bootRequiresPlatformOperatorTenant(): void
    {
        abort_unless(app(PlatformOperatorTenantAccess::class)->allows(), 403);
    }
}
