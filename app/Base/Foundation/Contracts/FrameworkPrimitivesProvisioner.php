<?php

namespace App\Base\Foundation\Contracts;

interface FrameworkPrimitivesProvisioner
{
    /**
     * Provision the operator tenant, its primary company, initial admin, and
     * framework system agents as one idempotent operation.
     *
     * @param  callable(string): void|null  $output
     */
    public function provision(
        ?string $companyName = null,
        ?string $companyCode = null,
        ?string $bootstrapAdminFile = null,
        ?callable $output = null,
    ): void;
}
