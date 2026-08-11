<?php

namespace App\Core\Company\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;
use App\Core\Company\Enums\CompanyErrorCode;

final class CompanyTenantAssignmentException extends BlbInvariantViolationException
{
    /** @param array<string, mixed> $context */
    private function __construct(string $message, array $context = [])
    {
        parent::__construct($message, CompanyErrorCode::COMPANY_TENANT_ASSIGNMENT_INVALID, $context);
    }

    public static function tenantDoesNotExist(int $tenantId): self
    {
        return new self('The assigned tenant does not exist or is soft-deleted.', ['tenant_id' => $tenantId]);
    }

    public static function immutable(int $companyId, int $fromTenantId, int $toTenantId): self
    {
        return new self(
            'A company tenant assignment is immutable after creation.',
            ['company_id' => $companyId, 'from_tenant_id' => $fromTenantId, 'to_tenant_id' => $toTenantId],
        );
    }

    public static function parentTenantMismatch(int $tenantId, int $parentCompanyId): self
    {
        return new self(
            'A parent company must belong to the same tenant as its child.',
            ['tenant_id' => $tenantId, 'parent_company_id' => $parentCompanyId],
        );
    }
}
