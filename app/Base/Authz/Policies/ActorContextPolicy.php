<?php
namespace App\Base\Authz\Policies;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;

/**
 * Validates minimum actor context before proceeding.
 *
 * Denies if actor ID, company, or agent delegation context is invalid.
 * Abstains when actor context is valid.
 */
class ActorContextPolicy implements AuthorizationPolicy
{
    public function key(): string
    {
        return 'actor_context';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        return $actor->validate();
    }
}
