<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings\DTO;

/**
 * Identifies the scope for a settings lookup.
 *
 * When type is EMPLOYEE, companyId enables the cascade:
 * employee → company → global → config.
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
     * Create an employee scope with company cascade.
     */
    public static function employee(int $employeeId, int $companyId): self
    {
        return new self(ScopeType::EMPLOYEE, $employeeId, $companyId);
    }
}
