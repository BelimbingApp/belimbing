<?php

namespace App\Base\Authz\Policies;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;

/**
 * Enforces the Agent delegation invariant: an Agent cannot hold a capability
 * its human supervisor lacks (authorization.md §9.3).
 *
 * Abstains for non-agent actors. When the supervisor is allowed, abstains so
 * GrantPolicy remains authoritative for the Agent's own grants and explicit
 * denies (§9.4 item 2).
 */
class DelegationPolicy implements AuthorizationPolicy
{
    public function __construct(
        private readonly GrantPolicy $grants,
    ) {}

    public function key(): string
    {
        return 'delegation';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        if (! $actor->isAgent()) {
            return null;
        }

        // ActorContextPolicy already refused a missing supervisor id.
        $supervisor = new Actor(
            PrincipalType::USER,
            (int) $actor->actingForUserId,
            $actor->companyId,
            tenantId: $actor->tenantId,
        );

        $supervisorDecision = $this->grants->permissionsFor($supervisor)->evaluate($capability);

        if (! $supervisorDecision->allowed) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_DELEGATION_EXCEEDS_SUPERVISOR,
            );
        }

        return null;
    }
}
