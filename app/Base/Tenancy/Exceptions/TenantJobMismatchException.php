<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

final class TenantJobMismatchException extends BlbException
{
    public function __construct(int $commandTenantId, int $jobTenantId)
    {
        parent::__construct(
            sprintf('Job tenant [%d] does not match command tenant [%d].', $jobTenantId, $commandTenantId),
            TenancyErrorCode::TENANT_JOB_MISMATCH,
            ['command_tenant_id' => $commandTenantId, 'job_tenant_id' => $jobTenantId],
        );
    }
}
