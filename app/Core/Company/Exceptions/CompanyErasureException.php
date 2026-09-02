<?php

namespace App\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Company\Enums\CompanyErrorCode;

/**
 * Raised when erasing a company would shrink its tenant's company history.
 *
 * Soft deletion retires a company and keeps the row, so anything asking which
 * companies a tenant has held still gets the true answer. Hard deletion drops
 * the row, and the row is the only record that the company existed at all.
 * Core cannot see which subsystems have already read that history, so it
 * refuses the erasure rather than letting the answer change behind them.
 */
final class CompanyErasureException extends BlbInvariantViolationException
{
    public function __construct(int $tenantId, int $companyId, int $companiesHeldByTenant)
    {
        parent::__construct(
            'A company cannot be erased once its tenant has held another company; retire it with a soft delete instead.',
            CompanyErrorCode::COMPANY_ERASURE_FORBIDDEN,
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'companies_held_by_tenant' => $companiesHeldByTenant,
            ],
        );
    }
}
