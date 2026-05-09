<?php
namespace App\Base\Authz\Policies;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;

/**
 * Enforces company tenant boundary.
 *
 * Denies if the resource belongs to a different company than the actor.
 * Abstains when no resource, no resource company, or companies match.
 */
class CompanyScopePolicy implements AuthorizationPolicy
{
    public function key(): string
    {
        return 'company_scope';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        if ($resource === null || $resource->companyId === null) {
            return null;
        }

        if ($resource->companyId !== $actor->companyId) {
            return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_COMPANY_SCOPE);
        }

        return null;
    }
}
