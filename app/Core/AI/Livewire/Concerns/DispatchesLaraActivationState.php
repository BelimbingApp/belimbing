<?php

namespace App\Core\AI\Livewire\Concerns;

use App\Core\Employee\Models\Employee;

trait DispatchesLaraActivationState
{
    protected function dispatchLaraActivationState(): void
    {
        $this->dispatch(
            'lara-activation-changed',
            activated: Employee::laraActivationState() === true,
        );
    }
}
