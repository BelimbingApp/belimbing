<?php

namespace App\Base\Tenancy\Middleware;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\DTO\TenantResolution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolve the tenant boundary for the current web request.
 *
 * The tenant derives from the authenticated user's company; guests resolve
 * to no tenant context. Context is set unconditionally each request so a
 * long-lived worker can never carry one request's tenant into the next.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolve($request);

        if ($resolution === null) {
            $this->tenantContext->clear();
        } else {
            $this->tenantContext->setResolution($resolution);
        }

        return $next($request);
    }

    private function resolve(Request $request): ?TenantResolution
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'getAttribute')) {
            return null;
        }

        try {
            $tenantId = $user->getAttribute('tenant_id');
        } catch (Throwable) {
            return null;
        }

        // Authentication is session-backed for web requests. Host and header
        // resolvers do not exist yet; adding them would change behaviour.
        return $tenantId !== null
            ? new TenantResolution(TenantResolution::SESSION, (int) $tenantId)
            : null;
    }
}
