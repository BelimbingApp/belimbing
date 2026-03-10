<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Services;

use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ImpersonationManager
{
    private const SESSION_KEY = 'impersonation';

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

        session([
            self::SESSION_KEY.'.original_user_id' => $impersonator->id,
            self::SESSION_KEY.'.original_user_name' => $impersonator->name,
        ]);

        Auth::login($target);
    }

    /**
     * Stop impersonating and restore the original admin user.
     */
    public function stop(): void
    {
        $originalId = session(self::SESSION_KEY.'.original_user_id');

        if ($originalId === null) {
            return;
        }

        session()->forget(self::SESSION_KEY);

        Auth::loginUsingId((int) $originalId);
    }

    /**
     * Check whether the current session is impersonating another user.
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY.'.original_user_id');
    }

    /**
     * Get the original admin user's ID, or null if not impersonating.
     */
    public function getImpersonatorId(): ?int
    {
        $id = session(self::SESSION_KEY.'.original_user_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Get the original admin user's name, or null if not impersonating.
     */
    public function getImpersonatorName(): ?string
    {
        return session(self::SESSION_KEY.'.original_user_name');
    }
}
