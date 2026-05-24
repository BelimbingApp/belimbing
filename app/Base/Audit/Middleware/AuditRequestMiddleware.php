<?php

namespace App\Base\Audit\Middleware;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP middleware that logs every authenticated web request
 * as an audit action entry.
 */
class AuditRequestMiddleware
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        private readonly RequestContext $context,
    ) {}

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        if (! config('audit.log_http_requests', true)) {
            return $response;
        }

        if (! $request->user()) {
            return $response;
        }

        $route = $request->route();
        if ($route === null) {
            return $response;
        }

        $routeName = $route->getName();
        if ($routeName !== null && str_starts_with($routeName, 'livewire')) {
            return $response;
        }

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        $now = now();

        $this->buffer->bufferAction([
            'company_id' => $this->context->companyId,
            'actor_type' => $this->context->actorType,
            'actor_id' => $this->context->actorId,
            'actor_role' => $this->context->actorRole,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => 'http.request',
            'payload' => json_encode([
                'method' => $request->method(),
                'route' => $routeName,
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
            ]),
            'trace_id' => $this->context->traceId,
            'occurred_at' => $now,
        ]);

        return $response;
    }
}
