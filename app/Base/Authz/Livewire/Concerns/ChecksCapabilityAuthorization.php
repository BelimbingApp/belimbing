<?php

namespace App\Base\Authz\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;

trait ChecksCapabilityAuthorization
{
    use InteractsWithNotifications;

    /**
     * Check if the current user has the given capability.
     *
     * Notifies with a friendly error if denied.
     */
    protected function checkCapability(string $capability): bool
    {
        $authUser = auth()->user();

        $actor = Actor::forUser($authUser);

        $decision = app(AuthorizationService::class)->can($actor, $capability);

        if (! $decision->allowed) {
            $this->notifyError(__('You do not have permission to perform this action.'));

            return false;
        }

        return true;
    }
}
