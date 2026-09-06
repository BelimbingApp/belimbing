<?php

namespace Tests\Support;

use App\Base\Tenancy\Console\Concerns\DispatchesWithTenant;
use Illuminate\Console\Command;

final class TenantDispatchProbeCommand extends Command
{
    use DispatchesWithTenant;

    protected $signature = 'test:tenant-dispatch-probe';

    public int $jobTenantId = 0;

    public ?TenantDispatchProbeJob $dispatchedJob = null;

    public function handle(): int
    {
        $this->dispatchedJob = new TenantDispatchProbeJob($this->jobTenantId);
        $this->dispatchWithTenant($this->dispatchedJob);

        return self::SUCCESS;
    }
}
