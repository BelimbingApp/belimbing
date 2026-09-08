<?php

namespace App\Base\Authz\Services;

use App\Base\Authz\Exceptions\ImpersonationRefusedException;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ImpersonationManager
{
    private const SESSION_KEY = 'impersonation';

    private const SESSION_KEY_USER_ID = '.original_user_id';

    private const SESSION_KEY_USER_NAME = '.original_user_name';

    /**
     * Start impersonating the target user.
     *
     * Stores the impersonator's identity in session, then switches
     * the authenticated user to the target via Auth::login().
     *
     * @param  User  $impersonator  The admin user initiating impersonation
     * @param  User  $target  The user to impersonate
     */
    public function start(User $impersonator, User $target): void
    {
        if ($impersonator->id === $target->id) {
            throw new InvalidArgumentException('Cannot impersonate yourself.');
        }

        if ($this->isImpersonating()) {
            throw new ImpersonationRefusedException('Nested impersonation is not allowed.');
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        if ((int) $target->tenant_id !== $tenantId) {
            throw new ImpersonationRefusedException('Cannot impersonate a user in another tenant.');
        }

        app(SemanticActionRecorder::class)->record(
            event: 'impersonation.started',
            summary: __('Started impersonating :name', ['name' => $target->name]),
            source: __('Impersonation'),
            subject: ['name' => 'user', 'id' => $target->id],
            surface: 'admin.impersonate',
            context: [
                'impersonator_id' => $impersonator->id,
                'target_id' => $target->id,
            ],
            retain: true,
        );

        session([
            self::SESSION_KEY.self::SESSION_KEY_USER_ID => $impersonator->id,
            self::SESSION_KEY.self::SESSION_KEY_USER_NAME => $impersonator->name,
        ]);

        Auth::login($target);
    }

    /**
     * Stop impersonating and restore the original admin user.
     */
    public function stop(): void
    {
        $originalId = session(self::SESSION_KEY.self::SESSION_KEY_USER_ID);

        if ($originalId === null) {
            return;
        }

        $targetId = auth()->id();
        $impersonatorId = (int) $originalId;

        // Restore the real admin before the audit write so actor_id is A, not B.
        session()->forget(self::SESSION_KEY);
        Auth::loginUsingId($impersonatorId);

        app(SemanticActionRecorder::class)->record(
            event: 'impersonation.stopped',
            summary: __('Stopped impersonating'),
            source: __('Impersonation'),
            subject: ['name' => 'user', 'id' => $targetId ?? 0],
            surface: 'admin.impersonate',
            context: [
                'impersonator_id' => $impersonatorId,
                'target_id' => $targetId,
            ],
            retain: true,
        );
    }

    /**
     * Check whether the current session is impersonating another user.
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY.self::SESSION_KEY_USER_ID);
    }

    /**
     * Get the original admin user's ID, or null if not impersonating.
     */
    public function getImpersonatorId(): ?int
    {
        $id = session(self::SESSION_KEY.self::SESSION_KEY_USER_ID);

        return $id !== null ? (int) $id : null;
    }

    /**
     * Get the original admin user's name, or null if not impersonating.
     */
    public function getImpersonatorName(): ?string
    {
        return session(self::SESSION_KEY.self::SESSION_KEY_USER_NAME);
    }
}
