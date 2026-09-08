<?php

namespace App\Base\Tenancy\Middleware;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\DTO\TenantResolution;
use App\Base\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolve the tenant boundary for the current web request.
 *
 * The tenant derives from the authenticated user's company; guests resolve
 * to no tenant context. Context is set unconditionally each request so a
 * long-lived worker can never carry one request's tenant into the next.
 *
 * A resolved tenant that is not {@see Tenant::isActive()} is refused before
 * it is bound: an operator who suspends a tenant must lock its users out of
 * the web surface, not only out of the console.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolve($request);

        if ($resolution !== null && ! $this->tenantIsUsable($resolution->tenantId)) {
            return $this->refuseInactiveTenant($request);
        }

        if ($resolution === null) {
            $this->tenantContext->clear();
        } else {
            $this->tenantContext->setResolution($resolution);
        }

        return $next($request);
    }

    /**
     * A tenant row that exists but is not active refuses the request.
     *
     * A tenant ID with no row at all is left exactly as it was: that is the
     * unresolvable-tenant path (#729), and this rule only narrows what an
     * existing tenant may do.
     */
    private function tenantIsUsable(int $tenantId): bool
    {
        $tenant = Tenant::withTrashed()->find($tenantId);

        return $tenant === null || $tenant->isActive();
    }

    /**
     * Unbind, sign the user out of the web guard, and send them to login.
     *
     * The platform-operator tenant can never reach this path: the model
     * refuses to mark it inactive, so an operator cannot lock themselves out.
     */
    private function refuseInactiveTenant(Request $request): Response
    {
        // Unbind first: nothing after this middleware — the redirect included —
        // may run inside a suspended tenant's context.
        $this->tenantContext->clear();

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('error', __('tenancy.suspended'));
        }

        return redirect()->route('login');
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
