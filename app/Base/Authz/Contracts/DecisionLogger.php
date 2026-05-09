<?php
namespace App\Base\Authz\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;

/**
 * Records authorization decisions for audit.
 */
interface DecisionLogger
{
    /**
     * Buffer a decision for deferred persistence.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        AuthorizationDecision $decision,
        array $context = []
    ): void;
}
