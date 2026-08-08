<?php

namespace App\Base\Authz\Policies;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;

/**
 * Enforces the tenant boundary — the platform's outer isolation layer.
 *
 * Denies when the resource's tenant differs from the actor's tenant, and
 * fails closed when the actor has no tenant while the resource does.
 * Abstains when the resource carries no tenant (or an unresolved one):
 * CompanyScopePolicy remains the inner guard there.
 */
class TenantScopePolicy implements AuthorizationPolicy
{
    public function key(): string
    {
        return 'tenant_scope';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        if ($resource === null || $resource->tenantId === null) {
            return null;
        }

        if ($actor->tenantId === null || $resource->tenantId !== $actor->tenantId) {
            return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_TENANT_SCOPE);
        }

        return null;
    }
}
