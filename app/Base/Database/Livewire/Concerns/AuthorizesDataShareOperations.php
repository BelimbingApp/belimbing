<?php

namespace App\Base\Database\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\User\Models\User;

trait AuthorizesDataShareOperations
{
    private function recentlyAuthenticated(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && (time() - $confirmedAt) <= (int) config('auth.password_timeout', 10800);
    }

    private function requireCapability(string $capability): void
    {
        if (! $this->capabilityAllows($capability)) {
            abort(403, "Capability '{$capability}' is required.");
        }
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
