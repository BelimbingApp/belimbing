<?php

namespace App\Base\Authz\DTO;

use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Contracts\CompanyScoped;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Actor
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public PrincipalType $type,
        public int $id,
        public ?int $companyId,
        public ?int $actingForUserId = null,
        public array $attributes = [],
        public ?int $tenantId = null,
    ) {}

    /**
     * Create an actor from an authenticated user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function forUser(
        Authenticatable $user,
        PrincipalType $type = PrincipalType::USER,
        ?int $actingForUserId = null,
        array $attributes = [],
    ): self {
        return new self(
            type: $type,
            id: (int) $user->getAuthIdentifier(),
            companyId: self::resolveUserCompanyId($user),
            actingForUserId: $actingForUserId,
            attributes: $attributes,
            tenantId: self::resolveUserTenantId($user),
        );
    }

    public function isUser(): bool
    {
        return $this->type === PrincipalType::USER;
    }

    public function isAgent(): bool
    {
        return $this->type === PrincipalType::AGENT;
    }

    /**
     * Validate minimum actor context for authorization.
     *
     * Returns null when valid, or a denial decision when invalid.
     *
     * Process principals (console, scheduler, queue) may omit companyId when
     * the work is tenant-scoped — BelimbingApp/blb-people#78. User and agent
     * actors still require a company.
     */
    public function validate(): ?AuthorizationDecision
    {
        if ($this->id <= 0) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT,
                ['actor_validation']
            );
        }

        if ($this->companyId === null && ! $this->type->isProcess()) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT,
                ['actor_validation']
            );
        }

        if ($this->isAgent() && $this->actingForUserId === null) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT,
                ['actor_validation']
            );
        }

        return null;
    }

    /**
     * Cache key representing this actor's identity for permission lookups.
     */
    public function cacheKey(): string
    {
        return $this->type->value.':'.$this->id.':'.$this->companyId;
    }

    private static function resolveUserCompanyId(Authenticatable $user): ?int
    {
        if ($user instanceof CompanyScoped) {
            return $user->getCompanyId();
        }

        if (! method_exists($user, 'getAttribute')) {
            return null;
        }

        $companyId = $user->getAttribute('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    /**
     * Resolve the actor's tenant from the user record.
     *
     * The tenant is derived data — a user's tenant is their company's tenant
     * (exposed by the User model as a `tenant_id` attribute). Null means the
     * tenant could not be resolved; tenant enforcement then fails closed at
     * TenantScopePolicy when the resource carries a tenant.
     */
    private static function resolveUserTenantId(Authenticatable $user): ?int
    {
        if (! method_exists($user, 'getAttribute')) {
            return null;
        }

        try {
            $tenantId = $user->getAttribute('tenant_id');
        } catch (\Throwable) {
            return null;
        }

        return $tenantId !== null ? (int) $tenantId : null;
    }
}
