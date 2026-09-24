<?php

namespace App\Base\Database\Middleware;

use App\Base\Database\Services\HydrationGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the HTTP request the hydration guard's unit of work. Prepended to
 * the global stack so every request, Livewire update included, is counted
 * from its first query; the window closes in terminate() so after-response
 * work still belongs to the request that caused it.
 */
final class GuardRequestHydration
{
    public function __construct(private readonly HydrationGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Route facts are read lazily: the route is not resolved yet here,
        // but it is by the time a load crosses the limit.
        $this->guard->openRequest(static fn (): array => [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
        ]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->guard->closeRequest();
    }
}
