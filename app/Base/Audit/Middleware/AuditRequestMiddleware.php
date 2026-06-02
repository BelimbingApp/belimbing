<?php

namespace App\Base\Audit\Middleware;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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

        $user = $request->user();
        if (! $user) {
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
        $actor = $this->resolveActor($user);

        $this->buffer->bufferAction([
            'company_id' => $actor['company_id'],
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
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

    /**
     * @return array{type: string, id: int, company_id: int|null}
     */
    private function resolveActor(Authenticatable $user): array
    {
        $actorType = $this->context->actorType;
        $actorId = $this->context->actorId;
        $companyId = $this->context->companyId;

        if ($actorType === null && method_exists($user, 'principalType')) {
            $actorType = $user->principalType()->value;
        }

        if ($actorType === null) {
            $actorType = PrincipalType::USER->value;
        }

        if ($actorId === null) {
            $actorId = (int) $user->getAuthIdentifier();
        }

        if ($companyId === null && method_exists($user, 'getCompanyId')) {
            $companyId = $user->getCompanyId();
        }

        return [
            'type' => $actorType,
            'id' => $actorId,
            'company_id' => $companyId,
        ];
    }
}
