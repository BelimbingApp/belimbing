<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\DTO\TenantResolution;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;

/**
 * Request/job-scoped tenant context.
 *
 * Registered as a scoped container binding so Octane/FrankenPHP workers
 * flush it between requests, jobs, and commands — tenant context can never
 * leak from one execution into the next.
 */
final class ApplicationTenantContext implements TenantContext
{
    private ?int $tenantId = null;

    private ?TenantResolution $resolution = null;

    public function currentTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function resolution(): ?TenantResolution
    {
        return $this->resolution;
    }

    public function requireTenantId(): int
    {
        if ($this->tenantId === null) {
            throw new TenantContextMissingException;
        }

        return $this->tenantId;
    }

    public function set(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->resolution = null;
    }

    public function setResolution(TenantResolution $resolution): void
    {
        $this->tenantId = $resolution->tenantId;
        $this->resolution = $resolution;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->resolution = null;
    }

    public function runForTenant(?int $tenantId, callable $callback): mixed
    {
        $previousTenantId = $this->tenantId;
        $previousResolution = $this->resolution;
        $this->set($tenantId);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenantId;
            $this->resolution = $previousResolution;
        }
    }
}
