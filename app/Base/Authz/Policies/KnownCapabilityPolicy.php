<?php
namespace App\Base\Authz\Policies;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;

/**
 * Rejects capabilities not registered in the authz configuration.
 *
 * This policy acts as a "schema validator" for authorization checks.
 * It denies any check for a capability key that is not defined in any
 * module's Config/authz.php. This ensures we only evaluate grants
 * for known, well-defined capabilities.
 */
class KnownCapabilityPolicy implements AuthorizationPolicy
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    public function key(): string
    {
        return 'capability_registry';
    }

    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision {
        if ($this->registry->has($capability)) {
            return null;
        }

        return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_UNKNOWN_CAPABILITY);
    }
}
