<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Middleware;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeCapability
{
    public function __construct(private readonly AuthorizationService $authorizationService) {}

    /**
     * Authorize request by required capability.
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $actor = Actor::forUser($user, $this->resolvePrincipalType($user));

        try {
            $this->authorizationService->authorize($actor, $capability, context: [
                'route' => (string) $request->route()?->getName(),
            ]);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * Resolve the principal type from the authenticated user.
     *
     * Checks for a principalType() method on the user model,
     * falling back to USER for standard web authentication.
     */
    private function resolvePrincipalType(mixed $user): PrincipalType
    {
        if (method_exists($user, 'principalType')) {
            return $user->principalType();
        }

        return PrincipalType::USER;
    }
}
