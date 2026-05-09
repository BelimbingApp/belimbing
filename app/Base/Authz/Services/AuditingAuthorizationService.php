<?php
namespace App\Base\Authz\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use Illuminate\Support\Collection;

/**
 * Decorator that logs every authorization decision.
 *
 * Wraps a pure AuthorizationService and delegates audit
 * logging to a swappable DecisionLogger implementation.
 */
class AuditingAuthorizationService implements AuthorizationService
{
    public function __construct(
        private readonly AuthorizationEngine $engine,
        private readonly DecisionLogger $logger,
    ) {}

    /**
     * Evaluate and log the decision.
     */
    public function can(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = []
    ): AuthorizationDecision {
        $decision = $this->engine->can($actor, $capability, $resource, $context);

        $this->logger->log($actor, $capability, $resource, $decision, $context);

        return $decision;
    }

    /**
     * Authorize and throw when denied.
     */
    public function authorize(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = []
    ): void {
        $decision = $this->can($actor, $capability, $resource, $context);

        if ($decision->allowed) {
            return;
        }

        throw new AuthorizationDeniedException($decision);
    }

    /**
     * Filter resources by capability, logging each check.
     */
    public function filterAllowed(
        Actor $actor,
        string $capability,
        iterable $resources,
        array $context = []
    ): Collection {
        return collect($resources)->filter(function ($resource) use ($actor, $capability, $context): bool {
            $resourceContext = $resource instanceof ResourceContext ? $resource : null;

            return $this->can($actor, $capability, $resourceContext, $context)->allowed;
        })->values();
    }
}
