<?php

namespace App\Base\Settings\DTO;

/**
 * Identifies the scope for a settings lookup.
 *
 * User contexts may carry company and tenant ids so definitions that
 * allow organizational inheritance can continue through company and
 * tenant scope. Company contexts may carry a tenant id for the same
 * purpose.
 */
final readonly class Scope
{
    public function __construct(
        public ScopeType $type,
        public int $id,
        public ?int $companyId = null,
        public ?int $tenantId = null,
    ) {}

    /**
     * Create a company scope with optional tenant fallback context.
     */
    public static function company(int $companyId, ?int $tenantId = null): self
    {
        return new self(ScopeType::COMPANY, $companyId, tenantId: $tenantId);
    }

    /**
     * Create a tenant scope.
     */
    public static function tenant(int $tenantId): self
    {
        return new self(ScopeType::TENANT, $tenantId);
    }

    /**
     * Create a user scope with optional company and tenant fallback context.
     */
    public static function user(int $userId, ?int $companyId = null, ?int $tenantId = null): self
    {
        return new self(ScopeType::USER, $userId, $companyId, $tenantId);
    }
}
