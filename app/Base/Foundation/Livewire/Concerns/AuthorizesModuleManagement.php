<?php

namespace App\Base\Foundation\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use Illuminate\Support\Facades\Auth;

trait AuthorizesModuleManagement
{
    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.software.modules.manage')
            ->allowed;
    }

    /**
     * @param  list<string>  $log
     */
    private function flashReloadLog(array $log): void
    {
        if ($log !== []) {
            session()->flash('command-log', implode("\n", $log));
        }
    }
}
