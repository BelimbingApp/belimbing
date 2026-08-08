<?php

namespace App\Base\Tenancy\Middleware;

use App\Base\Tenancy\Contracts\TenantContext;
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
        $this->tenantContext->set($this->resolveTenantId($request));

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?int
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

        return $tenantId !== null ? (int) $tenantId : null;
    }
}
