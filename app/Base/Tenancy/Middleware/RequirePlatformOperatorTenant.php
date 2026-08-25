<?php

namespace App\Base\Tenancy\Middleware;

use App\Base\Tenancy\Services\PlatformOperatorTenantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequirePlatformOperatorTenant
{
    public function __construct(private PlatformOperatorTenantAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->allows(), 403);

        return $next($request);
    }
}
