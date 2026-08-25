<?php

namespace App\Base\Authz\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use Closure;

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

    /**
     * Run a Livewire action only when the current actor holds its capability.
     *
     * @param  Closure(): void  $action
     */
    protected function runIfCapable(string $capability, Closure $action): void
    {
        if (! $this->checkCapability($capability)) {
            return;
        }

        $action();
    }
}
