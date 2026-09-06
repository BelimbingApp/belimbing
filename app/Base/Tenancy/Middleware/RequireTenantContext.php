<?php

namespace App\Base\Tenancy\Middleware;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Base\Tenancy\Services\TenantContextMissRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireTenantContext
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantContextMissRecorder $missRecorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenantContext->hasTenant() || $this->isExcluded($request)) {
            return $next($request);
        }

        // Session is the only live web resolver today (authenticated user's
        // tenant_id). Host/header names are reserved for future channels.
        $this->missRecorder->record(
            $request->route()?->getName(),
            'session',
        );

        throw new TenantContextMissingException;
    }

    private function isExcluded(Request $request): bool
    {
        $name = $request->route()?->getName();
        $exclusions = config('domain_routes.tenant_context.exclusions', []);

        if (! is_string($name) || ! is_array($exclusions)) {
            return false;
        }

        $reason = $exclusions[$name] ?? null;

        return is_string($reason) && trim($reason) !== '';
    }
}
