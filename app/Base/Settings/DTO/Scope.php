<?php

namespace App\Base\Settings\DTO;

/**
 * Identifies the scope for a settings lookup.
 *
 * User contexts may carry a company id so definitions that allow
 * organizational inheritance can continue through company scope.
 */
final readonly class Scope
{
    public function __construct(
        public ScopeType $type,
        public int $id,
        public ?int $companyId = null,
    ) {}

    /**
     * Create a company scope.
     */
    public static function company(int $companyId): self
    {
        return new self(ScopeType::COMPANY, $companyId);
    }

    /**
     * Create a user scope with optional company fallback context.
     */
    public static function user(int $userId, ?int $companyId = null): self
    {
        return new self(ScopeType::USER, $userId, $companyId);
    }
}
