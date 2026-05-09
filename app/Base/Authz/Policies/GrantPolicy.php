<?php
namespace App\Base\Authz\Policies;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Services\EffectivePermissions;

/**
 * Evaluates explicit grants/denies and role-derived capabilities.
 *
 * This is the final (authoritative) policy in the pipeline.
 * It always returns a decision — never abstains.
 */
class GrantPolicy implements AuthorizationPolicy
{
    /**
     * Per-actor permission cache, keyed by actor cache key.
     *
     * @var array<string, EffectivePermissions>
     */
    private array $cache = [];

    public function key(): string
    {
        return 'grant';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        $permissions = $this->cache[$actor->cacheKey()]
            ??= EffectivePermissions::forActor($actor);

        return $permissions->evaluate($capability);
    }
}
