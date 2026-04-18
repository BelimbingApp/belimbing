<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Captures Laravel auth events (login, logout, failed login)
 * and buffers them as audit action entries.
 */
class AuthListener
{
    public function __construct(
        private readonly AuditBuffer $buffer,
        private readonly RequestContext $context,
    ) {}

    /**
     * Handle a user login event.
     */
    public function handleLogin(Login $event): void
    {
        $this->bufferAuthAction(
            'auth.login',
            (int) $event->user->getAuthIdentifier(),
            $event->guard,
            $event->user,
        );
    }

    /**
     * Handle a user logout event.
     */
    public function handleLogout(Logout $event): void
    {
        $actorId = $event->user !== null
            ? (int) $event->user->getAuthIdentifier()
            : 0;

        $this->bufferAuthAction('auth.logout', $actorId, $event->guard, $event->user);
    }

    /**
     * Handle a failed login event.
     *
     * When Laravel resolves a user (e.g. wrong password), {@see Failed::$user}
     * is that account; otherwise both are absent (unknown identifier).
     */
    public function handleFailed(Failed $event): void
    {
        $now = now();
        $target = $event->user;

        $actorId = $target !== null ? (int) $target->getAuthIdentifier() : 0;

        $companyId = $this->context->companyId;
        if ($companyId === null && $target !== null && method_exists($target, 'getCompanyId')) {
            $companyId = $target->getCompanyId();
        }

        $this->buffer->bufferAction([
            'company_id' => $companyId,
            'actor_type' => $target !== null
                ? $this->principalTypeForUser($target)->value
                : PrincipalType::GUEST->value,
            'actor_id' => $actorId,
            'actor_role' => null,
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => 'auth.login.failed',
            'payload' => json_encode(['email' => $event->credentials['email'] ?? null]),
            'correlation_id' => $this->context->correlationId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * Buffer a standard auth action entry.
     */
    private function bufferAuthAction(
        string $event,
        int $actorId,
        string $guard,
        ?Authenticatable $eventUser = null,
    ): void {
        $now = now();

        $this->buffer->bufferAction([
            'company_id' => $this->resolveCompanyId($guard, $eventUser),
            'actor_type' => $this->resolveActorType($guard, $eventUser),
            'actor_id' => $actorId,
            'actor_role' => $this->resolveActorRole($guard, $eventUser),
            'ip_address' => $this->context->ipAddress,
            'url' => $this->context->url,
            'user_agent' => $this->context->userAgent,
            'event' => $event,
            'payload' => null,
            'correlation_id' => $this->context->correlationId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * Prefer request context; otherwise derive from the authenticatable via
     * {@see self::principalTypeForUser()} (same contract as {@see AuthorizeCapability}).
     */
    private function resolveActorType(string $guard, ?Authenticatable $eventUser): string
    {
        if ($this->context->actorType !== null) {
            return $this->context->actorType;
        }

        $user = $this->authUser($guard, $eventUser);
        if ($user !== null) {
            return $this->principalTypeForUser($user)->value;
        }

        return PrincipalType::GUEST->value;
    }

    /**
     * Resolve principal type from the authenticatable.
     *
     * When the model implements principalType(), that return value is used (e.g.
     * PrincipalType::USER, PrincipalType::AGENT). Stored values describe account kind,
     * not whether the actor is a person.
     *
     * When principalType() is absent, defaults to PrincipalType::USER — same rule as
     * {@see AuthorizeCapability::resolvePrincipalType()} (e.g. tests or third-party
     * guards that yield a plain {@see Authenticatable}).
     */
    private function principalTypeForUser(mixed $user): PrincipalType
    {
        if (is_object($user) && method_exists($user, 'principalType')) {
            return $user->principalType();
        }

        return PrincipalType::USER;
    }

    /**
     * Authenticated user for metadata: guard first, then the user attached to the event
     * (logout clears the guard before the in-memory user is nulled; login uses the event user).
     */
    private function authUser(string $guard, ?Authenticatable $eventUser): ?Authenticatable
    {
        return auth()->guard($guard)->user() ?? $eventUser;
    }

    private function resolveCompanyId(string $guard, ?Authenticatable $eventUser): ?int
    {
        if ($this->context->companyId !== null) {
            return $this->context->companyId;
        }

        $user = $this->authUser($guard, $eventUser);
        if ($user !== null && method_exists($user, 'getCompanyId')) {
            return $user->getCompanyId();
        }

        return null;
    }

    private function resolveActorRole(string $guard, ?Authenticatable $eventUser): ?string
    {
        if ($this->context->actorRole !== null) {
            return $this->context->actorRole;
        }

        $user = $this->authUser($guard, $eventUser);
        if ($user === null) {
            return null;
        }

        $principalType = $this->principalTypeForUser($user);

        $roles = PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->where('base_authz_principal_roles.principal_type', $principalType->value)
            ->where('base_authz_principal_roles.principal_id', $user->getAuthIdentifier())
            ->pluck('base_authz_roles.code')
            ->sort()
            ->implode(',');

        return $roles !== '' ? $roles : null;
    }
}
