<?php
namespace App\Base\Authz\DTO;

use App\Base\Authz\Enums\AuthorizationReasonCode;

final readonly class AuthorizationDecision
{
    /**
     * @param  array<int, string>  $appliedPolicies
     * @param  array<string, mixed>  $auditMeta
     */
    public function __construct(
        public bool $allowed,
        public AuthorizationReasonCode $reasonCode,
        public array $appliedPolicies = [],
        public array $auditMeta = [],
    ) {}

    /**
     * Build an allow decision.
     *
     * @param  array<int, string>  $appliedPolicies
     * @param  array<string, mixed>  $auditMeta
     */
    public static function allow(array $appliedPolicies = [], array $auditMeta = []): self
    {
        return new self(true, AuthorizationReasonCode::ALLOWED, $appliedPolicies, $auditMeta);
    }

    /**
     * Build a deny decision.
     *
     * @param  array<int, string>  $appliedPolicies
     * @param  array<string, mixed>  $auditMeta
     */
    public static function deny(
        AuthorizationReasonCode $reasonCode,
        array $appliedPolicies = [],
        array $auditMeta = []
    ): self {
        return new self(false, $reasonCode, $appliedPolicies, $auditMeta);
    }
}
