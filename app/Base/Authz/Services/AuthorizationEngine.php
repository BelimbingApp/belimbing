<?php

namespace App\Base\Authz\Services;

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Contracts\TenantDirectory;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pure authorization engine — no side effects.
 *
 * Evaluates authorization by running an ordered pipeline of policies.
 * Each policy can deny (halt), abstain (continue), or allow (final stage).
 * The engine collects the trail of consulted policies for audit.
 */
class AuthorizationEngine implements AuthorizationService
{
    /**
     * @param  array<int, AuthorizationPolicy>  $policies
     */
    public function __construct(
        private readonly array $policies,
        private readonly ?TenantDirectory $tenantDirectory = null,
    ) {}

    /**
     * Evaluate whether actor can perform capability on resource.
     */
    public function can(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = []
    ): AuthorizationDecision {
        $capability = strtolower($capability);
        $appliedPolicies = [];

        try {
            foreach ($this->policies as $policy) {
                $appliedPolicies[] = $policy->key();
                $decision = $policy->evaluate($actor, $capability, $resource, $context);

                if ($decision !== null) {
                    return new AuthorizationDecision(
                        $decision->allowed,
                        $decision->reasonCode,
                        array_merge($appliedPolicies, $decision->appliedPolicies),
                        $decision->auditMeta,
                    );
                }
            }

            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
                $appliedPolicies
            );
        } catch (Throwable) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_POLICY_ENGINE_ERROR,
                $appliedPolicies
            );
        }
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
     * Filter resources by capability.
     */
    public function filterAllowed(
        Actor $actor,
        string $capability,
        iterable $resources,
        array $context = []
    ): Collection {
        return collect($resources)->filter(function ($resource) use ($actor, $capability, $context): bool {
            $resourceContext = $this->resourceContext($resource);

            return $this->can($actor, $capability, $resourceContext, $context)->allowed;
        })->values();
    }

    /**
     * Convert resource to ResourceContext using convention-based extraction.
     *
     * Resources carrying a company but no explicit tenant are enriched through
     * the TenantDirectory so the tenant boundary holds even for models that
     * do not denormalize tenant_id.
     *
     * Public so decorators can normalize raw resources before running their
     * own (e.g. audited) checks — conversion lives in exactly one place.
     */
    public function resourceContext(mixed $resource): ?ResourceContext
    {
        if ($resource instanceof ResourceContext) {
            return $this->enrichTenant($resource);
        }

        if (is_array($resource)) {
            return $this->enrichTenant(new ResourceContext(
                type: (string) ($resource['type'] ?? 'resource'),
                id: $resource['id'] ?? null,
                companyId: isset($resource['company_id']) ? (int) $resource['company_id'] : null,
                attributes: $resource,
                tenantId: isset($resource['tenant_id']) ? (int) $resource['tenant_id'] : null,
            ));
        }

        if (is_object($resource)) {
            $type = method_exists($resource, 'getTable') ? (string) $resource->getTable() : 'resource';
            $id = $resource->id ?? null;
            $companyId = isset($resource->company_id) ? (int) $resource->company_id : null;
            $tenantId = isset($resource->tenant_id) ? (int) $resource->tenant_id : null;

            return $this->enrichTenant(new ResourceContext($type, $id, $companyId, (array) $resource, $tenantId));
        }

        return null;
    }

    /**
     * Fill in a missing tenant ID from the resource's company.
     */
    private function enrichTenant(ResourceContext $resource): ResourceContext
    {
        if ($resource->tenantId !== null || $resource->companyId === null || $this->tenantDirectory === null) {
            return $resource;
        }

        $tenantId = $this->tenantDirectory->tenantIdForCompany($resource->companyId);

        if ($tenantId === null) {
            return $resource;
        }

        return new ResourceContext(
            $resource->type,
            $resource->id,
            $resource->companyId,
            $resource->attributes,
            $tenantId,
        );
    }
}
